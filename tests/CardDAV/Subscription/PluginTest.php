<?php

namespace ESN\CardDAV\Subscription;

use Sabre\DAV\ServerPlugin;
use Sabre\VObject\Document;
use Sabre\VObject\ITip\Message;

require_once ESN_TEST_BASE. '/CardDAV/PluginTestBase.php';

class PluginTest extends \ESN\CardDAV\PluginTestBase {

    function setUp() {
        parent::setUp();

        // TODO: the plugin is added in tests/DAV/ServerMock.php hence we do not
        // add it again in this file. We will need to move those mocks and test cases to this file
        // and uncomment 2 lines below
        //$plugin = new Plugin();
        //$this->server->addPlugin($plugin);
    }

    function testPropFindRequestSubscriptionAddressBook() {
        $this->carddavBackend->createSubscription(
            'principals/users/' . $this->userTestId2,
            'user2subscription1',
            [
                '{DAV:}displayname' => 'user2subscription1',
                '{http://open-paas.org/contacts}source' => new \Sabre\DAV\Xml\Property\Href('addressbooks/' . $this->userTestId1 . '/book1', false)
            ]
        );

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PROPFIND',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/' . $this->userTestId2 . '/user2subscription1.json',
        ));

        $body = '{"properties": ["uri","{http://open-paas.org/contacts}source","acl"]}';
        $request->setBody($body);
        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString(), true);

        $this->assertEquals(200, $response->status);
        $this->assertEquals($jsonResponse['uri'], 'user2subscription1');
        $this->assertEquals($jsonResponse['{http://open-paas.org/contacts}source'], '/addressbooks/' . $this->userTestId1 . '/book1.json');
        $this->assertEquals($jsonResponse['acl'][0], array(
            "privilege" => "{DAV:}all",
            "principal" => "principals/users/" . $this->userTestId2,
            "protected" => true
        ));
    }
}
