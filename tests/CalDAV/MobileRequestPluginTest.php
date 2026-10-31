<?php

namespace ESN\CalDAV;

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

        $this->mobileRequestPlugin = new MobileRequestPlugin();
        $this->server->addPlugin($this->mobileRequestPlugin);
    }

    function testAfterPropFind() {
        $delegationRequest = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0e/publicCal1.json',
        ));

        $sharees = [
            'share' => [
                'set' => [
                    [
                        'dav:href' => 'mailto:robertocarlos@realmadrid.com',
                        'dav:read' => true
                    ]
                ]
            ]
        ];

        $delegationRequest->setBody(json_encode($sharees));
        $this->authBackend->setPrincipal('principals/users/54b64eadf6d7d8e41d263e0e');
        $response = $this->request($delegationRequest);
        $this->authBackend->setPrincipal('principals/users/54b64eadf6d7d8e41d263e0f');

        $this->assertEquals(200, $response->status);


        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PROPFIND',
            'HTTP_CONTENT_TYPE' => 'application/xml',
            'HTTP_ACCEPT'       => 'application/xml',
            'HTTP_USER_AGENT'   => 'DAVdroid/1.10.1.1-ose (2/13/18; dav4android; okhttp3) Android/8.1.0',
            'REQUEST_URI'       => 'calendars/54b64eadf6d7d8e41d263e0f',
        ));

        $response = $this->request($request);

        $this->assertEquals($response->status, 207);

        $propFindXml = $this->server->xml->expect('{DAV:}multistatus', $response->getBodyAsString());
        $xmlResponses = $propFindXml->getResponses();

        foreach($xmlResponses as $index => $xmlResponse) {
            $responseProps = $xmlResponse->getResponseProperties();
            $resourceType = isset($responseProps[200]['{DAV:}resourcetype']) ? $responseProps[200]['{DAV:}resourcetype'] : null;
            
            if (isset($resourceType) && ($resourceType->is("{http://calendarserver.org/ns/}shared") || $resourceType->is("{http://calendarserver.org/ns/}subscribed"))) {
                $this->assertTrue((boolean)preg_match("/.*-.*/", $responseProps[200]['{DAV:}displayname']));
            }
        }
    }

    function testRewrittenHrefsAreNotEncodedTwice() {
        $body = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"><d:response>'
            . '<d:href>/calendars/54b64eadf6d7d8e41d263e0f/a%2Bb/</d:href><d:propstat><d:prop>'
            . '<d:resourcetype><d:collection/></d:resourcetype><d:displayname>#default</d:displayname></d:prop>'
            . '<d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>';
        $request = new \Sabre\HTTP\Request('PROPFIND', '/calendars/54b64eadf6d7d8e41d263e0f/', ['User-Agent' => 'Thunderbird/115.0']);
        $response = new \Sabre\HTTP\Response(207, [], $body);

        $this->server->getPlugin('mobile-request')->afterMethodPropfind($request, $response);

        $this->assertStringContainsStringIgnoringCase('/calendars/54b64eadf6d7d8e41d263e0f/a%2Bb/<', $response->getBodyAsString());
        $this->assertStringNotContainsString('%25', $response->getBodyAsString());
        $this->assertStringContainsString('My agenda', $response->getBodyAsString());
    }

    function testAfterPropFindKeepsAWellFormedResponseWhenADisplayNameIsNotXmlSafe() {
        $body = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"><d:response><d:href>/calendars/54b64eadf6d7d8e41d263e0f/default/</d:href>'
            . '<d:propstat><d:prop><d:resourcetype><d:collection/><cal:calendar xmlns:cal="urn:ietf:params:xml:ns:caldav"/></d:resourcetype>'
            . "<d:displayname>My\x01calendar</d:displayname></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>"
            . '</d:response></d:multistatus>';
        $request = new \Sabre\HTTP\Request('PROPFIND', '/calendars/54b64eadf6d7d8e41d263e0f', ['User-Agent' => 'Thunderbird/115.0']);
        $response = new \Sabre\HTTP\Response(207, [], $body);

        $this->mobileRequestPlugin->afterMethodPropfind($request, $response);

        $this->assertSame(207, $response->getStatus());
        $responseProps = $this->server->xml->expect('{DAV:}multistatus', $response->getBodyAsString())
            ->getResponses()[0]->getResponseProperties();
        $this->assertSame('Mycalendar', $responseProps[200]['{DAV:}displayname']);
    }

    function testAfterPropFindLeavesTheResponseUntouchedWhenItCannotBeParsed() {
        $body = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"><d:response>';
        $request = new \Sabre\HTTP\Request('PROPFIND', '/calendars/54b64eadf6d7d8e41d263e0f', ['User-Agent' => 'Thunderbird/115.0']);
        $response = new \Sabre\HTTP\Response(207, [], $body);

        $this->mobileRequestPlugin->afterMethodPropfind($request, $response);

        $this->assertSame(207, $response->getStatus());
        $this->assertSame($body, $response->getBodyAsString());
    }
}
