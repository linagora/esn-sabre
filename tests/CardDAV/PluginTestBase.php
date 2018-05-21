<?php

namespace ESN\CardDAV;

require_once ESN_TEST_BASE. '/DAV/ServerMock.php';

class PluginTestBase extends \ESN\DAV\ServerMock {

    protected $userTestEmail1 = 'robertocarlos@realmadrid.com';
    protected $userTestId1 = '54b64eadf6d7d8e41d263e0f';

    protected $userTestEmail2 = 'johndoe@example.org';
    protected $userTestId2 = '54b64eadf6d7d8e41d263e0e';

    protected $userTestEmail3 = 'johndoe2@example.org';
    protected $userTestId3 = '54b64eadf6d7d8e41d263e0d';

    protected $user1Book1Id;
    protected $user2Book1Id;
    protected $user3Book1Id;

    function setUp() {
        parent::setUp();

        $this->user1Book1Id = $this->carddavAddressBook['id'];
        $this->user2Book1Id = $this->createAddressBook('principals/users/' . $this->userTestId2, 'user2book1');
        $this->user3Book1Id = $this->createAddressBook('principals/users/' . $this->userTestId3, 'user3book1');
    }

    final protected function makeRequest($method, $uri, $body =  null) {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => $method,
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => $uri,
        ));

        if ($body) {
            $request->setBody(json_encode($body));
        }

        return $this->request($request);
    }

    final protected function createAddressBook($principalUri, $addressBookUri) {
        $mongoId = $this->carddavBackend->createAddressBook(
            $principalUri,
            $addressBookUri,
            [
                '{DAV:}displayname' => $addressBookUri . ' name',
                '{urn:ietf:params:xml:ns:carddav}addressbook-description' => $addressBookUri . ' description',
                '{http://open-paas.org/contacts}type' => $addressBookUri . ' type',
            ]
        );

        $cards = array(
            $addressBookUri . 'Card1' => "BEGIN:VCARD\r\nFN:d\r\nEND:VCARD\r\n",
            $addressBookUri . 'Card2' => "BEGIN:VCARD\r\nFN:c\r\nEND:VCARD",
            $addressBookUri . 'Card3' => "BEGIN:VCARD\r\nFN:b\r\nEND:VCARD\r\n",
            $addressBookUri . 'Card4' => "BEGIN:VCARD\nFN:a\nEND:VCARD\n",
        );

        foreach ($cards as $uri => $data) {
            $this->carddavBackend->createCard($mongoId, $uri, $data);
        }

        return $mongoId;
    }
}
