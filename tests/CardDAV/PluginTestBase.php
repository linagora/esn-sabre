<?php

namespace ESN\CardDAV;

require_once ESN_TEST_BASE. '/DAV/ServerMock.php';

class PluginTestBase extends \ESN\DAV\ServerMock {

    function setUp() {
        parent::setUp();

        // TODO: move CardDAV mocks from tests/DAV/ServerMock.php to here
    }
}
