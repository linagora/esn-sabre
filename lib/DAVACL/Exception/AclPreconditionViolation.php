<?php

namespace ESN\DAVACL\Exception;

use Sabre\DAV;

/** RFC 3744 section 8.1.1 requires 403/409 while preserving the DAV:error condition. */
class AclPreconditionViolation extends DAV\Exception\Forbidden {
    private DAV\Exception $condition;

    function __construct(DAV\Exception $condition) {
        parent::__construct($condition->getMessage(), 0, $condition);
        $this->condition = $condition;
    }

    public function serialize(DAV\Server $server, \DOMElement $errorNode): void {
        $this->condition->serialize($server, $errorNode);
    }
}
