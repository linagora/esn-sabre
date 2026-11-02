<?php

namespace ESN\CalDAV;

use ESN\Utils\RepairedNameIndex;
use ESN\Utils\TrustedUrlBase;
use ESN\Utils\AuthTenant;
use ESN\Utils\TenantType;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use Sabre\VObject;
use Sabre\VObject\Component\VCalendar;

/**
 * Binary Attachment Plugin
 *
 * Controls how inline binary attachments (ATTACH;ENCODING=BASE64;VALUE=BINARY)
 * are handled when calendar objects are created or updated.
 *
 * Inline binaries can bloat calendar objects significantly; URI attachments
 * (ATTACH:https://...) are preserved unless a trusted URL base is configured.
 *
 * Four modes are supported:
 *   - allow  : the data is stored as-is, binary attachments included.
 *   - reject : a request carrying a binary attachment is rejected (403).
 *   - filter : binary attachments are silently stripped from the object
 *              (URI attachments are preserved). This is the default.
 *   - offload : offload binaries to the attachment service before scheduling.
 */
class BinaryAttachmentPlugin extends ServerPlugin {

    const MODE_ALLOW = 'allow';
    const MODE_REJECT = 'reject';
    const MODE_FILTER = 'filter';
    const MODE_OFFLOAD = 'offload';
    private static string $ATTACH_PROPERTY = 'ATTACH';

    /**
     * @var string
     */
    protected $mode;
    private ?TrustedUrlBase $trustedUrlBase;
    private ?AuthTenant $tenant = null;

    /**
     * @var Server
     */
    protected $server;

    /**
     * @param string $mode One of allow|reject|filter|offload. Defaults to filter.
     */
    function __construct($mode = self::MODE_FILTER, ?string $trustedUrlBase = null, private ?AttachmentUploadClient $uploader = null) {
        $mode = strtolower((string) $mode);

        if (!in_array($mode, [self::MODE_ALLOW, self::MODE_REJECT, self::MODE_FILTER, self::MODE_OFFLOAD], true)) {
            throw new \InvalidArgumentException(
                'Invalid binary attachment mode "' . $mode . '", expected one of: allow, reject, filter, offload'
            );
        }

        $this->mode = $mode;
        if ($mode === self::MODE_OFFLOAD && $uploader === null) {
            throw new \InvalidArgumentException('Offload mode requires an attachment upload client');
        }
        $this->trustedUrlBase = $trustedUrlBase !== null && $trustedUrlBase !== ''
            ? new TrustedUrlBase($trustedUrlBase) : null;
    }

    function initialize(Server $server) {
        $this->server = $server;

        // calendarObjectChange hands us the object Sabre already parsed, and
        // re-serializes it once for everybody if we report a change. Running on
        // the raw payload instead would mean parsing and serializing the event a
        // second time on every single write.
        //
        // Runs before scheduling so an attachment never reaches an attendee, and
        // before participation handling for the same reason.
        // Offload only after event validation (80), but before scheduling (100).
        $priority = $this->mode === self::MODE_OFFLOAD ? Plugin::PRIORITY_BEFORE_SCHEDULING + 1 : Plugin::PRIORITY_BEFORE_SCHEDULING - 30;
        $server->on('calendarObjectChange', [$this, 'calendarObjectChange'], $priority);
        $server->on('auth:success', function (AuthTenant $tenant) {
            $this->tenant = $tenant;
        });
    }

    function getPluginName() {
        return 'caldav-binary-attachment';
    }

    /**
     * Applies the configured policy to the calendar object being written.
     *
     * @param VCalendar $vCal     the parsed object, mutated in place
     * @param bool      $modified set when an attachment was stripped, which is
     *                            what tells Sabre to re-serialize the object
     */
    function calendarObjectChange(RequestInterface $request, ResponseInterface $response, VCalendar $vCal, $calendarPath, &$modified, $isNew) {
        if ($this->mode === self::MODE_OFFLOAD && $request->getMethod() === 'PUT' && $this->uploadAttachments($vCal, $request)) {
            $modified = true;
        }
        if ($this->filterCalendar($vCal)) {
            $modified = true;
        }
    }

    // iTIP delivery bypasses calendarObjectChange, so it must apply the same policy explicitly.
    function filterCalendar(VCalendar $vCal): bool {
        if ($this->mode === self::MODE_ALLOW && $this->trustedUrlBase === null) {
            return false;
        }

        // Sabre's name repair can turn "..ATTACH" into an ATTACH that remove() cannot find.
        RepairedNameIndex::refresh($vCal);
        $filtered = false;
        $this->applyPolicy($vCal, $filtered);
        return $filtered;
    }

    /**
     * Walks the component tree and applies the configured policy to every
     * binary ATTACH property it finds.
     *
     * @param VObject\Component $component
     * @param bool              $filtered  Set to true when the payload was mutated.
     */
    protected function applyPolicy(VObject\Component $component, &$filtered) {
        $toRemove = [];

        foreach ($component->children() as $child) {
            if ($child instanceof VObject\Component) {
                $this->applyPolicy($child, $filtered);
            } elseif ($this->isBinaryAttachment($child)) {
                if ($this->mode !== self::MODE_ALLOW) {
                    $this->rejectIfConfigured();
                    $toRemove[] = $child;
                }
            } elseif ($this->trustedUrlBase !== null && $child instanceof VObject\Property &&
                strtoupper($child->name) === self::$ATTACH_PROPERTY && !$this->trustedUrlBase->accepts((string) $child)) {
                $toRemove[] = $child;
            }
        }

        foreach ($toRemove as $property) {
            $component->remove($property);
            $filtered = true;
        }
    }

    /**
     * Throws when the plugin is configured to reject binary attachments.
     */
    private function rejectIfConfigured() {
        if ($this->mode === self::MODE_REJECT || $this->mode === self::MODE_OFFLOAD) {
            throw new \Sabre\DAV\Exception\Forbidden(
                'Inline binary attachments (ATTACH;VALUE=BINARY) are not allowed on this server.'
            );
        }
    }

    /**
     * A child is a binary attachment when it is an ATTACH property carrying an
     * inline base64 payload, i.e. ENCODING=BASE64 or VALUE=BINARY. URI
     * attachments (and any other node) are not.
     *
     * @param mixed $child
     * @return bool
     */
    protected function isBinaryAttachment($child) {
        if (!($child instanceof VObject\Property) || strtoupper($child->name) !== self::$ATTACH_PROPERTY) {
            return false;
        }

        $value = isset($child['VALUE']) ? strtoupper((string) $child['VALUE']) : null;
        $encoding = isset($child['ENCODING']) ? strtoupper((string) $child['ENCODING']) : null;

        return $value === 'BINARY' || $encoding === 'BASE64';
    }

    private function uploadAttachments(VCalendar $calendar, RequestInterface $request): bool {
        $pending = [];
        $total = 0;
        foreach ($this->components($calendar) as $component) {
            foreach ($component->select('ATTACH') as $attachment) {
                if (!$this->isBinaryAttachment($attachment)) {
                    continue;
                }
                if ($this->tenant === null || $this->tenant->tenantType !== TenantType::User) {
                    throw new \Sabre\DAV\Exception\Forbidden('Attachment upload requires an authenticated user.');
                }
                if (!isset($component->UID)) {
                    throw new \Sabre\DAV\Exception\BadRequest('Attachment component requires UID.');
                }
                // VObject already decodes VALUE=BINARY. Legacy ENCODING-only input needs explicit decoding.
                $content = $attachment instanceof VObject\Property\Binary
                    ? $attachment->getValue() : base64_decode((string) $attachment, true);
                $filename = (string) ($attachment['X-FILENAME'] ?? $attachment['FILENAME'] ?? 'attachment.bin');
                $type = (string) ($attachment['FMTTYPE'] ?? 'application/octet-stream');
                if (!is_string($content) || strlen($content) > 5 * 1024 * 1024 || ($total += strlen($content)) > 10 * 1024 * 1024
                    || count($pending) >= 20 || $filename === '' || strlen($filename) > 160
                    || preg_match('/[\x00-\x1f\x7f\/\\\\]/', $filename)
                    || !preg_match('/^[a-zA-Z0-9!#$&^_.+-]+\/[a-zA-Z0-9!#$&^_.+-]+$/', $type)) {
                    throw new \Sabre\DAV\Exception\BadRequest('Invalid or oversized binary attachment.');
                }
                $pending[] = [$component, $attachment, $filename, $type, $content];
            }
        }
        // Forward only the authenticated user's credential; side-service resolves the uploader itself.
        $authorization = $request->getHeader('Authorization') ?? '';
        if ($pending && !preg_match('/^(Basic|Bearer) [^\\s]+$/', $authorization)) {
            throw new \Sabre\DAV\Exception\Forbidden('Attachment upload requires Basic or Bearer user authentication.');
        }
        // Validate the full set before creating any files; mutate only after every upload succeeds.
        $replacements = [];
        foreach ($pending as [$component, $attachment, $filename, $type, $content]) {
            $uri = $this->uploader->upload($authorization, $filename, $type, $content);
            if ($this->trustedUrlBase !== null && !$this->trustedUrlBase->accepts($uri)) {
                throw new \Sabre\DAV\Exception\ServiceUnavailable('Uploaded attachment URL is not trusted by this server.');
            }
            $replacements[] = [$component, $attachment, $filename, $type, $uri];
        }
        foreach ($replacements as [$component, $attachment, $filename, $type, $uri]) {
            $component->remove($attachment);
            $component->add('ATTACH', $uri, ['VALUE' => 'URI', 'FMTTYPE' => $type, 'X-FILENAME' => $filename]);
        }
        return count($pending) > 0;
    }

    private function components(VObject\Component $component): \Generator {
        yield $component;
        foreach ($component->getComponents() as $child) {
            yield from $this->components($child);
        }
    }
}
