<?php

namespace ESN\CalDAV;

use ESN\Utils\TrustedUrlBase;
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
 * Three modes are supported:
 *   - allow  : the data is stored as-is, binary attachments included.
 *   - reject : a request carrying a binary attachment is rejected (403).
 *   - filter : binary attachments are silently stripped from the object
 *              (URI attachments are preserved). This is the default.
 */
class BinaryAttachmentPlugin extends ServerPlugin {

    const MODE_ALLOW = 'allow';
    const MODE_REJECT = 'reject';
    const MODE_FILTER = 'filter';
    private static string $ATTACH_PROPERTY = 'ATTACH';

    /**
     * @var string
     */
    protected $mode;
    private ?TrustedUrlBase $trustedUrlBase;

    /**
     * @var Server
     */
    protected $server;

    /**
     * @param string $mode One of allow|reject|filter. Defaults to filter.
     */
    function __construct($mode = self::MODE_FILTER, ?string $trustedUrlBase = null) {
        $mode = strtolower((string) $mode);

        if (!in_array($mode, [self::MODE_ALLOW, self::MODE_REJECT, self::MODE_FILTER], true)) {
            throw new \InvalidArgumentException(
                'Invalid binary attachment mode "' . $mode . '", expected one of: allow, reject, filter'
            );
        }

        $this->mode = $mode;
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
        $server->on('calendarObjectChange', [$this, 'calendarObjectChange'], Plugin::PRIORITY_BEFORE_SCHEDULING - 30);
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
        if ($this->filterCalendar($vCal)) {
            $modified = true;
        }
    }

    // iTIP delivery bypasses calendarObjectChange, so it must apply the same policy explicitly.
    function filterCalendar(VCalendar $vCal): bool {
        if ($this->mode === self::MODE_ALLOW && $this->trustedUrlBase === null) {
            return false;
        }

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
        if ($this->mode === self::MODE_REJECT) {
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
}
