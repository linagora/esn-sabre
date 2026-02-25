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

        $plugin = new MobileRequestPlugin();
        $this->server->addPlugin($plugin);
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
        $response = $this->request($delegationRequest);

        $this->assertEquals(200, $response->status);


        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PROPFIND',
            'HTTP_CONTENT_TYPE' => 'application/xml',
            'HTTP_ACCEPT'       => 'application/xml',
            'REQUEST_URI'       => 'calendars/54b64eadf6d7d8e41d263e0f',
        ));

        $response = $this->request($request);

        $this->assertEquals($response->status, 207);

        $propFindXml = $this->server->xml->expect('{DAV:}multistatus', $response->getBodyAsString());
        $xmlResponses = $propFindXml->getResponses();

        $sharedDisplayNames = [];

        foreach($xmlResponses as $index => $xmlResponse) {
            $responseProps = $xmlResponse->getResponseProperties();
            $resourceType = isset($responseProps[200]['{DAV:}resourcetype']) ? $responseProps[200]['{DAV:}resourcetype'] : null;

            if (isset($resourceType) && ($resourceType->is("{http://calendarserver.org/ns/}shared") || $resourceType->is("{http://calendarserver.org/ns/}subscribed"))) {
                $displayName = $responseProps[200]['{DAV:}displayname'];
                $this->assertNotEmpty($displayName);
                $sharedDisplayNames[] = $displayName;
            }
        }

        $this->assertNotEmpty($sharedDisplayNames);
    }
}