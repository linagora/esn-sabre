<?php

namespace ESN\CardDAV;

use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\VObject;

/**
 * Inline Photo Plugin
 *
 * Controls how inline photos (PHOTO;ENCODING=b, PHOTO;VALUE=BINARY or a
 * data: URI) are handled when contacts are created or updated.
 *
 * Inline photos can bloat stored cards significantly; photos referenced by
 * an external URI (PHOTO;VALUE=URI:https://...) are always left untouched.
 *
 * Three modes are supported:
 *   - allow  : the card is stored as-is, inline photo included.
 *   - reject : a request carrying an inline photo is rejected (403).
 *   - filter : inline photos are silently stripped from the card (URI photos
 *              are preserved). This is the default.
 */
class InlinePhotoPlugin extends ServerPlugin {

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
                'Invalid inline photo mode "' . $mode . '", expected one of: allow, reject, filter'
            );
        }

        $this->mode = $mode;
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
        $this->process($data, $modified);
    }

    function beforeWriteContent($path, \Sabre\DAV\IFile $node, &$data, &$modified) {
        $this->process($data, $modified);
    }

    /**
     * Applies the configured policy to the given vCard payload.
     *
     * Non-vCard payloads and malformed data are left untouched so the
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

        $vcard = $this->parseVCard($data);

        if (is_null($vcard)) {
            return;
        }

        $filtered = false;
        $this->applyPolicy($vcard, $filtered);

        if ($filtered) {
            $data = $vcard->serialize();
            $modified = true;
        }
    }

    /**
     * Parses the payload as a vCard or a jCard.
     *
     * Returns null for anything that is not a vCard, malformed data included:
     * the regular validation pipeline deals with those.
     *
     * @param mixed $data
     * @return VObject\Component\VCard|null
     */
    protected function parseVCard($data) {
        if (!is_string($data) || $data === '') {
            return null;
        }

        try {
            // A leading '[' means we're dealing with a jCard document.
            $document = substr($data, 0, 1) === '['
                ? VObject\Reader::readJson($data)
                : VObject\Reader::read($data);
        } catch (VObject\ParseException $e) {
            return null;
        }

        return $document instanceof VObject\Component\VCard ? $document : null;
    }

    /**
     * Applies the configured policy to every inline PHOTO property of the card.
     *
     * @param VObject\Component\VCard $vcard
     * @param bool                    $filtered Set to true when the payload was mutated.
     */
    protected function applyPolicy(VObject\Component\VCard $vcard, &$filtered) {
        $toRemove = [];

        foreach ($vcard->select('PHOTO') as $photo) {
            if ($this->isInline($photo)) {
                $this->rejectIfConfigured();
                $toRemove[] = $photo;
            }
        }

        foreach ($toRemove as $photo) {
            $vcard->remove($photo);
            $filtered = true;
        }
    }

    /**
     * Throws when the plugin is configured to reject inline photos.
     */
    private function rejectIfConfigured() {
        if ($this->mode === self::MODE_REJECT) {
            throw new \Sabre\DAV\Exception\Forbidden(
                'Inline photos (PHOTO;ENCODING=b, PHOTO;VALUE=BINARY or data: URI) are not allowed on this server, reference the picture by URI instead.'
            );
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
