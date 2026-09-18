<?php

namespace ESN\CardDAV;

use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\VObject;

/**
 * Inline Photo Plugin
 *
 * Rejects vCards carrying an inline PHOTO (PHOTO;ENCODING=b,
 * PHOTO;VALUE=BINARY or a data: URI) when they are created or updated,
 * unless inline attachments are explicitly allowed.
 *
 * Inline photos bloat stored cards; clients are expected to reference the
 * picture by URI instead (PHOTO;VALUE=URI:https://...), which is always
 * accepted.
 */
class InlinePhotoPlugin extends ServerPlugin {

    /**
     * @var bool
     */
    protected $allowInline;

    /**
     * @var Server
     */
    protected $server;

    /**
     * @param bool $allowInline Store inline photos as-is. Defaults to false.
     */
    function __construct($allowInline = false) {
        $this->allowInline = (bool) $allowInline;
    }

    function initialize(Server $server) {
        $this->server = $server;

        // Run before the CardDAV plugin validates and re-serializes the card.
        $server->on('beforeCreateFile', [$this, 'beforeCreateFile'], 1);
        $server->on('beforeWriteContent', [$this, 'beforeWriteContent'], 1);
    }

    function getPluginName() {
        return 'carddav-inline-photo';
    }

    function beforeCreateFile($path, &$data, \Sabre\DAV\ICollection $parent, &$modified) {
        $this->process($data);
    }

    function beforeWriteContent($path, \Sabre\DAV\IFile $node, &$data, &$modified) {
        $this->process($data);
    }

    /**
     * Rejects the given payload when it is a vCard with an inline PHOTO.
     *
     * Non-vCard payloads and malformed data are left untouched so the
     * regular validation pipeline can deal with them.
     *
     * @param string|resource $data
     */
    protected function process(&$data) {
        if ($this->allowInline) {
            return;
        }

        if (is_resource($data)) {
            $data = stream_get_contents($data);
        }

        if (!is_string($data) || $data === '') {
            return;
        }

        try {
            // A leading '[' means we're dealing with a jCard document.
            if (substr($data, 0, 1) === '[') {
                $vcard = VObject\Reader::readJson($data);
            } else {
                $vcard = VObject\Reader::read($data);
            }
        } catch (VObject\ParseException $e) {
            // Not our concern; let the regular validation reject malformed data.
            return;
        }

        if (!$vcard instanceof VObject\Component\VCard) {
            return;
        }

        foreach ($vcard->select('PHOTO') as $photo) {
            if ($this->isInline($photo)) {
                throw new \Sabre\DAV\Exception\Forbidden(
                    'Inline PHOTO (ENCODING=b, VALUE=BINARY or data: URI) is not allowed on this server, reference the picture by URI instead.'
                );
            }
        }
    }

    /**
     * A PHOTO is inline when it carries a base64 payload, i.e. ENCODING=b
     * (vCard 3.0) or ENCODING=BASE64 (vCard 2.1), or VALUE=BINARY, or when
     * its value is a data: URI (vCard 4.0 / jCard, e.g.
     * PHOTO:data:image/jpeg;base64,...).
     *
     * @param VObject\Property $photo
     * @return bool
     */
    protected function isInline(VObject\Property $photo) {
        $value = isset($photo['VALUE']) ? strtoupper((string) $photo['VALUE']) : null;
        $encoding = isset($photo['ENCODING']) ? strtoupper((string) $photo['ENCODING']) : null;

        if ($value === 'BINARY' || $encoding === 'B' || $encoding === 'BASE64') {
            return true;
        }

        return stripos(ltrim((string) $photo->getValue()), 'data:') === 0;
    }
}
