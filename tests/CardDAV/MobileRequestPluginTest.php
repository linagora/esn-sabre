<?php

namespace ESN\CardDAV;

use Sabre\DAV\ServerPlugin;
use Sabre\VObject\Document;
use Sabre\VObject\ITip\Message;

require_once ESN_TEST_BASE. '/DAV/ServerMock.php';

/**
 * @medium
 */
class MobileRequestPluginTest extends \ESN\DAV\ServerMock {

    use \Sabre\VObject\PHPUnitAssertions;

    protected $userTestId = '5aa1f6639751b711008b4567';

    function setUp(): void {
        parent::setUp();

        $aclPlugin = new \Sabre\DAVACL\Plugin();
        $aclPlugin->principalCollectionSet = ['principals/users'];
        $this->server->addPlugin($aclPlugin);

        $this->calddavSubscriptionsPlugin = new \Sabre\CalDAV\Subscriptions\Plugin();
        $this->server->addPlugin($this->calddavSubscriptionsPlugin);

        $plugin = new \ESN\JSON\Plugin('json');
        $this->server->addPlugin($plugin);

        $plugin = new MobileRequestPlugin();
        $this->server->addPlugin($plugin);
    }

    function testAfterPropFind() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PROPFIND',
            'HTTP_CONTENT_TYPE' => 'application/xml',
            'HTTP_ACCEPT'       => 'application/xml',
            'HTTP_USER_AGENT'   => 'DAVdroid/1.10.1.1-ose (2/13/18; dav4android; okhttp3) Android/8.1.0',
            'REQUEST_URI'       => '/addressbooks/54b64eadf6d7d8e41d263e0f/',
        ));

        $request->setBody('<?xml version="1.0" encoding="utf-8" ?>
            <D:propfind xmlns:D="DAV:">
                <D:prop>
                    <D:displayname/>
                    <D:resourcetype/>
                </D:prop>
            </D:propfind>'
        );

        $response = $this->request($request);

        $this->assertEquals($response->status, 207);

        $propFindXml = $this->server->xml->expect('{DAV:}multistatus', $response->getBodyAsString());
        $xmlResponses = $propFindXml->getResponses();

        $displayNames = [];

        foreach($xmlResponses as $index => $xmlResponse) {
            $responseProps = $xmlResponse->getResponseProperties();
            $resourceType = isset($responseProps[200]['{DAV:}resourcetype']) ? $responseProps[200]['{DAV:}resourcetype'] : null;
            
            if (isset($resourceType) && ($resourceType->is("{urn:ietf:params:xml:ns:carddav}addressbook"))) {
                $displayNames[] = $responseProps[200]['{DAV:}displayname'];
            }
        }

        // My default address books
        $this->assertContains('My Collected Contacts', $displayNames);
        $this->assertContains('My Contacts', $displayNames);

        // Normal address book
        $this->assertContains('Book 1', $displayNames);
    }

    function testAfterPropFindWithGroupAddressBook() {
        $DOMAIN_ID = '54b64eadf6d7d8e41d263e7e';
        $USER_ID = '54b64eadf6d7d8e41d263e8f';

        $this->esndb->domains->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId($DOMAIN_ID),
            'name' => 'test',
            'administrators' => [
                [
                    'user_id' => $USER_ID
                ]
            ]
        ]);

        $this->esndb->users->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId($USER_ID),
            'firstname' => 'foo',
            'lastname' => 'bar',
            'accounts' => [
                [
                    'type' => 'email',
                    'emails' => [
                      'foobar@lng.com'
                    ]
                ]
                    ],
            'domains' => [ [ 'domain_id' => $DOMAIN_ID ] ]
        ]);

        $this->carddavBackend->createAddressBook('principals/domains/' . $DOMAIN_ID, 'gab', [ '{DAV:}acl' => [ '{DAV:}read' ] ]);

        $this->authBackend->setAuthTenant(new \ESN\Utils\AuthTenant($USER_ID, $DOMAIN_ID));

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PROPFIND',
            'HTTP_CONTENT_TYPE' => 'application/xml',
            'HTTP_ACCEPT'       => 'application/xml',
            'HTTP_USER_AGENT'   => 'DAVdroid/1.10.1.1-ose (2/13/18; dav4android; okhttp3) Android/8.1.0',
            'REQUEST_URI'       => '/addressbooks/' . $USER_ID,
        ));

        $request->setBody('<?xml version="1.0" encoding="utf-8" ?>
            <D:propfind xmlns:D="DAV:">
                <D:prop>
                    <D:displayname/>
                    <D:resourcetype/>
                </D:prop>
            </D:propfind>'
        );

        $response = $this->request($request);

        $this->assertEquals($response->status, 207);

        $propFindXml = $this->server->xml->expect('{DAV:}multistatus', $response->getBodyAsString());
        $xmlResponses = $propFindXml->getResponses();

        $displayNames = [];

        foreach($xmlResponses as $index => $xmlResponse) {
            $responseProps = $xmlResponse->getResponseProperties();
            $resourceType = isset($responseProps[200]['{DAV:}resourcetype']) ? $responseProps[200]['{DAV:}resourcetype'] : null;

            if (isset($resourceType) && ($resourceType->is("{urn:ietf:params:xml:ns:carddav}addressbook"))) {
                $displayNames[] = $responseProps[200]['{DAV:}displayname'];
            }
        }

        $this->assertCount(2, $displayNames);

        // My default address books
        $this->assertContains('My Collected Contacts', $displayNames);
        $this->assertContains('My Contacts', $displayNames);
    }

    function testAfterPropFindWithGroupAddressBookWhenBookIdIsOwnerId() {
        $DOMAIN_ID = '54b64eadf6d7d8e41d263e7e';
        $USER_ID = '54b64eadf6d7d8e41d263e8f';

        $this->esndb->domains->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId($DOMAIN_ID),
            'name' => 'test',
            'administrators' => [
                [
                    'user_id' => $USER_ID
                ]
            ]
        ]);

        $this->esndb->users->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId($USER_ID),
            'firstname' => 'foo',
            'lastname' => 'bar',
            'accounts' => [
                [
                    'type' => 'email',
                    'emails' => [
                      'foobar@lng.com'
                    ]
                ]
                    ],
            'domains' => [ [ 'domain_id' => $DOMAIN_ID ] ]
        ]);

        $this->carddavBackend->createAddressBook('principals/domains/' . $DOMAIN_ID, 'gab', [
            '{DAV:}acl'         => [ '{DAV:}read' ],
            '{DAV:}displayname' => 'Domain address book'
        ]);

        $this->authBackend->setAuthTenant(new \ESN\Utils\AuthTenant($USER_ID, $DOMAIN_ID));

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PROPFIND',
            'HTTP_CONTENT_TYPE' => 'application/xml',
            'HTTP_ACCEPT'       => 'application/xml',
            'HTTP_USER_AGENT'   => 'DAVdroid/1.10.1.1-ose (2/13/18; dav4android; okhttp3) Android/8.1.0',
            'REQUEST_URI'       => '/addressbooks/' . $DOMAIN_ID,
        ));

        $request->setBody('<?xml version="1.0" encoding="utf-8" ?>
            <D:propfind xmlns:D="DAV:">
                <D:prop>
                    <D:displayname/>
                    <D:resourcetype/>
                </D:prop>
            </D:propfind>'
        );

        $response = $this->request($request);

        $this->assertEquals($response->status, 207);

        $propFindXml = $this->server->xml->expect('{DAV:}multistatus', $response->getBodyAsString());
        $xmlResponses = $propFindXml->getResponses();

        $displayNames = [];

        foreach($xmlResponses as $index => $xmlResponse) {
            $responseProps = $xmlResponse->getResponseProperties();
            $resourceType = isset($responseProps[200]['{DAV:}resourcetype']) ? $responseProps[200]['{DAV:}resourcetype'] : null;
            
            if (isset($resourceType) && ($resourceType->is("{urn:ietf:params:xml:ns:carddav}addressbook"))) {
                $displayNames[] = $responseProps[200]['{DAV:}displayname'];
            }
        }

        $this->assertCount(1, $displayNames);
        $this->assertEquals('Domain address book - test', $displayNames[0]);
    }

    function testAfterPropFindWithDisabledGroupAddressBookWhenBookIdIsOwnerId() {
        $DOMAIN_ID = '54b64eadf6d7d8e41d263e7e';
        $USER_ID = '54b64eadf6d7d8e41d263e8f';

        $this->esndb->domains->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId($DOMAIN_ID),
            'name' => 'test',
            'administrators' => [
                [
                    'user_id' => $USER_ID
                ]
            ]
        ]);

        $this->esndb->users->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId($USER_ID),
            'firstname' => 'foo',
            'lastname' => 'bar',
            'accounts' => [
                [
                    'type' => 'email',
                    'emails' => [
                      'foobar@lng.com'
                    ]
                ]
                    ],
            'domains' => [ [ 'domain_id' => $DOMAIN_ID ] ]
        ]);

        $this->carddavBackend->createAddressBook('principals/domains/' . $DOMAIN_ID, 'gab', [
            '{DAV:}acl'         => [ '{DAV:}read' ],
            '{DAV:}displayname' => 'Domain address book',
            '{http://open-paas.org/contacts}state' => 'disabled'
        ]);

        $this->authBackend->setAuthTenant(new \ESN\Utils\AuthTenant($USER_ID, $DOMAIN_ID));

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PROPFIND',
            'HTTP_CONTENT_TYPE' => 'application/xml',
            'HTTP_ACCEPT'       => 'application/xml',
            'HTTP_USER_AGENT'   => 'DAVdroid/1.10.1.1-ose (2/13/18; dav4android; okhttp3) Android/8.1.0',
            'REQUEST_URI'       => '/addressbooks/' . $DOMAIN_ID,
        ));

        $request->setBody('<?xml version="1.0" encoding="utf-8" ?>
            <D:propfind xmlns:D="DAV:">
                <D:prop>
                    <D:displayname/>
                    <D:resourcetype/>
                </D:prop>
            </D:propfind>'
        );

        $response = $this->request($request);

        $this->assertEquals($response->status, 207);

        $propFindXml = $this->server->xml->expect('{DAV:}multistatus', $response->getBodyAsString());
        $xmlResponses = $propFindXml->getResponses();

        $displayNames = [];

        foreach($xmlResponses as $index => $xmlResponse) {
            $responseProps = $xmlResponse->getResponseProperties();
            $resourceType = isset($responseProps[200]['{DAV:}resourcetype']) ? $responseProps[200]['{DAV:}resourcetype'] : null;
            
            if (isset($resourceType) && ($resourceType->is("{urn:ietf:params:xml:ns:carddav}addressbook"))) {
                $displayNames[] = $responseProps[200]['{DAV:}displayname'];
            }
        }

        $this->assertCount(0, $displayNames);
    }

    function testRewrittenHrefsAreNotEncodedTwice() {
        $body = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"><d:response>'
            . '<d:href>/addressbooks/54b64eadf6d7d8e41d263e0f/a%2Bb/</d:href><d:propstat><d:prop>'
            . '<d:resourcetype><d:collection/></d:resourcetype></d:prop><d:status>HTTP/1.1 200 OK</d:status>'
            . '</d:propstat></d:response></d:multistatus>';
        $request = new \Sabre\HTTP\Request('PROPFIND', '/addressbooks/54b64eadf6d7d8e41d263e0f/', ['User-Agent' => 'Thunderbird/115.0']);
        $response = new \Sabre\HTTP\Response(207, [], $body);

        $this->server->getPlugin('carddav-mobile-request')->afterMethodPropfind($request, $response);

        $this->assertStringContainsStringIgnoringCase('/addressbooks/54b64eadf6d7d8e41d263e0f/a%2Bb/<', $response->getBodyAsString());
        $this->assertStringNotContainsString('%25', $response->getBodyAsString());
    }
}
