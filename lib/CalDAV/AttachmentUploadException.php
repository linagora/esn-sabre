<?php

namespace ESN\CalDAV;

use Sabre\DAV\Exception\ServiceUnavailable;
use Sabre\DAV\Server;

class AttachmentUploadException extends ServiceUnavailable {
    function __construct() {
        parent::__construct('Could not upload the attachment. Please try saving the event again.');
    }

    function getHTTPHeaders(Server $server) {
        return ['X-Twake-Attachment-Error' => 'upload-failed'];
    }
}
