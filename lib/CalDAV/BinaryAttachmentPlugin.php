<?php

namespace ESN\CalDAV;

use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\VObject;

/**
 * Binary Attachment Plugin
 *
 * Controls how inline binary attachments (ATTACH;ENCODING=BASE64;VALUE=BINARY)
 * are handled when calendar objects are created or updated.
 *
 * Inline binaries can bloat calendar objects significantly; URI attachments
 * (ATTACH:https://...) are always left untouched.
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

    /**
     * @var string
     */
    protected $mode;

    /**
     * @var Server
     */
    protected $server;

    /**
     * @param string $mode One of allow|reject|filter. Defaults to filter.
     */
    function __construct($mode = self::MODE_FILTER) {
        $mode = strtolower((string) $mode);

        if (!in_array($mode, [self::MODE_ALLOW, self::MODE_REJECT, self::MODE_FILTER], true)) {
            throw new \InvalidArgumentException(
                'Invalid binary attachment mode "' . $mode . '", expected one of: allow, reject, filter'
            );
        }

        $this->mode = $mode;
    }

    function initialize(Server $server) {
        $this->server = $server;

        // Run early so the policy is applied before scheduling/participation
        // logic re-serializes the object.
        $server->on('beforeCreateFile', [$this, 'beforeCreateFile'], 1);
        $server->on('beforeWriteContent', [$this, 'beforeWriteContent'], 1);
    }

    function getPluginName() {
        return 'caldav-binary-attachment';
    }

    function beforeCreateFile($path, &$data, \Sabre\DAV\ICollection $parent, &$modified) {
        $this->process($data, $modified);
    }

    function beforeWriteContent($path, \Sabre\DAV\IFile $node, &$data, &$modified) {
        $this->process($data, $modified);
    }

    /**
     * Applies the configured policy to the given calendar payload.
     *
     * Non-calendar payloads and malformed data are left untouched so the
     * regular validation pipeline can deal with them.
     *
     * @param string|resource $data
     * @param bool            $modified
     */
    protected function process(&$data, &$modified) {
        if ($this->mode === self::MODE_ALLOW) {
            return;
        }

        if (is_resource($data)) {
            $data = stream_get_contents($data);
        }

        if (!is_string($data) || $data === '') {
            return;
        }

        try {
            // A leading '[' means we're dealing with a jCal document.
            if (substr($data, 0, 1) === '[') {
                $vcalendar = VObject\Reader::readJson($data);
            } else {
                $vcalendar = VObject\Reader::read($data);
            }
        } catch (VObject\ParseException $e) {
            // Not our concern; let the regular validation reject malformed data.
            return;
        }

        if (!$vcalendar instanceof VObject\Component\VCalendar) {
            return;
        }

        $filtered = false;
        $this->applyPolicy($vcalendar, $filtered);

        if ($filtered) {
            $data = $vcalendar->serialize();
            $modified = true;
        }
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
                continue;
            }

            if (!($child instanceof VObject\Property) || strtoupper($child->name) !== 'ATTACH') {
                continue;
            }

            if (!$this->isBinaryAttachment($child)) {
                continue;
            }

            if ($this->mode === self::MODE_REJECT) {
                throw new \Sabre\DAV\Exception\Forbidden(
                    'Inline binary attachments (ATTACH;VALUE=BINARY) are not allowed on this server.'
                );
            }

            // MODE_FILTER
            $toRemove[] = $child;
        }

        foreach ($toRemove as $property) {
            $component->remove($property);
            $filtered = true;
        }
    }

    /**
     * An ATTACH is considered binary when it carries an inline base64 payload,
     * i.e. ENCODING=BASE64 or VALUE=BINARY. URI attachments are not.
     *
     * @param VObject\Property $attach
     * @return bool
     */
    protected function isBinaryAttachment(VObject\Property $attach) {
        $value = isset($attach['VALUE']) ? strtoupper((string) $attach['VALUE']) : null;
        $encoding = isset($attach['ENCODING']) ? strtoupper((string) $attach['ENCODING']) : null;

        return $value === 'BINARY' || $encoding === 'BASE64';
    }
}
