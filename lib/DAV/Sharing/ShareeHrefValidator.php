<?php

namespace ESN\DAV\Sharing;

use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\Server;
use Sabre\DAVACL\IPrincipal;

/**
 * Validates the dav:href of a sharee received through the JSON sharing APIs:
 * it must be either a valid mailto: URI or an existing principal.
 */
class ShareeHrefValidator {

    private const MAILTO_PREFIX = 'mailto:';

    private Server $server;

    public function __construct(Server $server) {
        $this->server = $server;
    }

    public function validate($href): string {
        if (!is_string($href) || $href === '') {
            throw new BadRequest('dav:href must be a non-empty string');
        }

        if (stripos($href, self::MAILTO_PREFIX) === 0) {
            return $this->validateMailto($href);
        }

        return $this->validatePrincipal($href);
    }

    private function validateMailto(string $href): string {
        if (!filter_var(substr($href, strlen(self::MAILTO_PREFIX)), FILTER_VALIDATE_EMAIL)) {
            throw new BadRequest('dav:href is not a valid mailto: URI');
        }

        return $href;
    }

    private function validatePrincipal(string $href): string {
        if (!$this->isExistingPrincipal($href)) {
            throw new BadRequest('dav:href must be a mailto: URI or a principal');
        }

        return $href;
    }

    private function isExistingPrincipal(string $href): bool {
        $path = preg_replace('#/+#', '/', trim($href, '/'));

        if (str_starts_with($path, 'principals/') && $this->isPrincipalNode($path)) {
            return true;
        }

        $principal = null;
        $this->server->emit('getPrincipalByUri', [$href, &$principal]);

        return !is_null($principal);
    }

    private function isPrincipalNode(string $path): bool {
        return $this->server->tree->nodeExists($path) &&
            $this->server->tree->getNodeForPath($path) instanceof IPrincipal;
    }
}
