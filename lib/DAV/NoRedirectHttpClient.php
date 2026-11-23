<?php

namespace ESN\DAV;

use Sabre\HTTP\Client;

/**
 * sabre/http follows a redirect to any origin and re-sends every request header and the body there, so a client
 * carrying a credential must not follow redirects: the 3xx is returned to the caller instead.
 */
class NoRedirectHttpClient extends Client {
    // Untyped like the sabre/http 5.x parent property, which a typed redeclaration would make fatal.
    protected $maxRedirects = 0;

    public function __construct() {
        parent::__construct();

        // sabre/http only hands its own class the separate header callback: a subclass falls back to the deprecated
        // parser that splits headers from the body by size.
        unset($this->curlSettings[CURLOPT_HEADER]);
        $this->curlSettings[CURLOPT_HEADERFUNCTION] = [$this, 'receiveCurlHeader'];
    }
}
