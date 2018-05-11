<?php

namespace ESN\DAV\Sharing;

require_once ESN_TEST_BASE. '/DAV/ServerMock.php';

class PluginTest extends \ESN\DAV\ServerMock {

    protected $aclMock;

    function setUp() {
        parent::setUp();

        $this->sharePlugin = new Plugin();
        $this->server->addPlugin($this->sharePlugin);


        $this->aclMock = $this->getMockBuilder(\Sabre\DAVACL\Plugin::class)
                       ->setMethods(['checkPrivileges'])
                       ->getMock();

        $this->server->addPlugin($this->aclMock);
    }

    function testShareResourceCalledWithRightParameters() {

        $path = '/calendars/54b64eadf6d7d8e41d263e0f/calendar1';

        $sharees = [];
        $sharees[] = new \Sabre\DAV\Xml\Element\Sharee([
            'href'       => 'mailto:user1@open-paas.org',
            'properties' => array(),
            'access'     => Plugin::ACCESS_ADMINISTRATION,
            'comment'    => ''
        ]);
        $sharees[] = new \Sabre\DAV\Xml\Element\Sharee([
            'href'       => 'mailto:user2@open-paas.org',
            'properties' => array(),
            'access'     => Plugin::ACCESS_READ,
            'comment'    => ''
        ]);

        $this->aclMock->expects($this->once())
            ->method('checkPrivileges')
            ->with(
                $this->equalTo($path),
                $this->equalTo('{DAV:}share')
            );

        $this->sharePlugin->shareResource($path, $sharees);
    }
}
