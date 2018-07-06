<?php
namespace ESN\Publisher\CardDAV;

require_once ESN_TEST_BASE . '/CardDAV/MockUtils.php';

class CardDAVBackendMock extends \ESN\CardDAV\CardDAVBackendMock {

    protected $eventEmitter;

    function setEventEmitter($value) {
        $this->eventEmitter = $value;
    }

    function getEventEmitter() {
        return $this->eventEmitter;
    }
}