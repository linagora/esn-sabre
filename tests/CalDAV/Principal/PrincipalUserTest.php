<?php

namespace ESN\CalDAV\Principal;

use Sabre\DAVACL;

class PrincipalUserTest extends \PHPUnit\Framework\TestCase {

    function getInstance() {

        $backend = new DAVACL\PrincipalBackend\Mock();
        $backend->addPrincipal([
            'uri' => 'principals/user/calendar-proxy-read',
        ]);
        $backend->addPrincipal([
            'uri' => 'principals/user/calendar-proxy-write',
        ]);
        $backend->addPrincipal([
            'uri' => 'principals/user/random',
        ]);
        return new PrincipalUser($backend, [
            'uri' => 'principals/user',
        ]);

    }

    function testGetACL() {

        $expected = [
            [
                'privilege' => '{DAV:}all',
                'principal' => '{DAV:}owner',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}read',
                'principal' => 'principals/user/calendar-proxy-read',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}read',
                'principal' => 'principals/user/calendar-proxy-write',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}read',
                'principal' => '{DAV:}authenticated',
                'protected' => true,
            ]
        ];

        $u = $this->getInstance();
        $this->assertEquals($expected, $u->getACL());

    }

}
