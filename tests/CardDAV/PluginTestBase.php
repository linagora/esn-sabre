<?php

namespace ESN\CardDAV;

require_once ESN_TEST_BASE. '/DAV/ServerMock.php';

class PluginTestBase extends \ESN\DAV\ServerMock {

    protected $userTestId2 = '5aa1f6639751b711008b4567';

    function setUp() {
        parent::setUp();

        $this->esndb->users->insert([
            '_id'       => new \MongoId($this->userTestId2),
            'firstname' => 'user2',
            'lastname'  => 'test2',
            'accounts'  => [
                [
                    'type' => 'email',
                    'emails' => [
                      'usertest2@mail.com'
                    ]
                ]
            ]
        ]);
        // TODO: move CardDAV mocks from tests/DAV/ServerMock.php to here
    }
}
