<?php

namespace ESN\CalDAV;

require_once ESN_TEST_BASE . '/Sabre/CalDAV/Backend/Mock.php';
require_once ESN_TEST_BASE . '/Sabre/CalDAV/Backend/MockSharing.php';

/**
 * @medium
 */
#[\AllowDynamicProperties]
class SharedCalendarTest extends \PHPUnit\Framework\TestCase {

    protected function getBackend() {
        $mc = new \MongoDB\Client(ESN_MONGO_SABREURI);
        $db = $mc->{ESN_MONGO_SABREDB};
        $db->drop();

        $principalBackendMock = $this->createMock(\Sabre\DAVACL\PrincipalBackend\BackendInterface::class);

        return new \ESN\CalDAV\Backend\Esn($db, $principalBackendMock);
    }

    protected $calendarId = '54b64eadf6d7d8e41d263e0f';

    function testGetACLIfNoPublicRight() {
        $backend = $this->getBackend();

        $calendarESN = new \ESN\CalDAV\CalendarHome($backend, ['uri' => 'principals/user/' . $this->calendarId]);
        $calendarSabre = new \Sabre\CalDAV\CalendarHome($backend, ['uri' => 'principals/user/' . $this->calendarId]);

        $sharedCalendarESN =  $calendarESN->getChild($this->calendarId);
        $sharedCalendarSabre =  $calendarSabre->getChild($this->calendarId);

        $this->assertTrue($sharedCalendarESN->getACL() == $sharedCalendarSabre->getACL());
    }


    function testGetACLAdministrationShareAccess() {
        $props = [
            'id'                                        => 1,
            '{http://calendarserver.org/ns/}shared-url' => 'calendars/owner/original',
            '{http://sabredav.org/ns}owner-principal'   => 'principals/owner/54b64eadf6d7d8e41d263e0f',
            '{http://sabredav.org/ns}read-only'         => false,
            'share-access'                              => \ESN\DAV\Sharing\Plugin::ACCESS_ADMINISTRATION,
            'principaluri'                              => 'principals/sharee/54b64eadf6d7d8e41d263e0e',
        ];

        $backend = new SimpleBackendMock([$props], [], []);

        $sharedCalendar = new SharedCalendar(new \Sabre\CalDAV\SharedCalendar($backend, $props));

        $expected = [
            [
                'privilege' => '{DAV:}read',
                'principal' => 'principals/sharee/54b64eadf6d7d8e41d263e0e',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}read',
                'principal' => 'principals/sharee/54b64eadf6d7d8e41d263e0e' . '/calendar-proxy-read',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}read',
                'principal' => 'principals/sharee/54b64eadf6d7d8e41d263e0e' . '/calendar-proxy-write',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}write',
                'principal' => 'principals/sharee/54b64eadf6d7d8e41d263e0e',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}write',
                'principal' => 'principals/sharee/54b64eadf6d7d8e41d263e0e' . '/calendar-proxy-write',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}write-properties',
                'principal' => 'principals/sharee/54b64eadf6d7d8e41d263e0e',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}write-properties',
                'principal' => 'principals/sharee/54b64eadf6d7d8e41d263e0e' . '/calendar-proxy-write',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}read-acl',
                'principal' => 'principals/sharee/54b64eadf6d7d8e41d263e0e',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}read-acl',
                'principal' => 'principals/sharee/54b64eadf6d7d8e41d263e0e' . '/calendar-proxy-write',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}share',
                'principal' => 'principals/sharee/54b64eadf6d7d8e41d263e0e',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}share',
                'principal' => 'principals/sharee/54b64eadf6d7d8e41d263e0e' . '/calendar-proxy-write',
                'protected' => true,
            ],
            [
                'privilege' => '{' . Plugin::NS_CALDAV . '}read-free-busy',
                'principal' => '{DAV:}authenticated',
                'protected' => true,
            ]
        ];

        $this->assertEquals($sharedCalendar->getACL(), $expected);
    }

    function testGetACLFreeBusyShareAccess() {
        $props = [
            'id'                                        => 1,
            '{http://calendarserver.org/ns/}shared-url' => 'calendars/owner/original',
            '{http://sabredav.org/ns}owner-principal'   => 'principals/owner/54b64eadf6d7d8e41d263e0f',
            '{http://sabredav.org/ns}read-only'         => false,
            'share-access'                              => \ESN\DAV\Sharing\Plugin::ACCESS_FREEBUSY          ,
            'principaluri'                              => 'principals/sharee/54b64eadf6d7d8e41d263e0e',
        ];

        $backend = new SimpleBackendMock([$props], [], []);

        $sharedCalendar = new SharedCalendar(new \Sabre\CalDAV\SharedCalendar($backend, $props));

        $expected = [
            [
                'privilege' => '{' . Plugin::NS_CALDAV . '}read-free-busy',
                'principal' => '{DAV:}authenticated',
                'protected' => true,
            ]
        ];

        $this->assertEquals($sharedCalendar->getACL(), $expected);
    }


    function testGetChildACLAdministrationShareAccess() {
        $props = [
            'id'                                        => 1,
            '{http://calendarserver.org/ns/}shared-url' => 'calendars/owner/original',
            '{http://sabredav.org/ns}owner-principal'   => 'principals/owner/54b64eadf6d7d8e41d263e0f',
            '{http://sabredav.org/ns}read-only'         => false,
            'share-access'                              => \ESN\DAV\Sharing\Plugin::ACCESS_ADMINISTRATION,
            'principaluri'                              => 'principals/sharee/54b64eadf6d7d8e41d263e0e',
        ];

        $backend = new SimpleBackendMock([$props], [], []);

        $sharedCalendar = new SharedCalendar(new \Sabre\CalDAV\SharedCalendar($backend, $props));

        $expected = [
            [
                'privilege' => '{DAV:}write',
                'principal' => 'principals/sharee/54b64eadf6d7d8e41d263e0e',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}write',
                'principal' => 'principals/sharee/54b64eadf6d7d8e41d263e0e/calendar-proxy-write',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}read',
                'principal' => 'principals/sharee/54b64eadf6d7d8e41d263e0e',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}read',
                'principal' => 'principals/sharee/54b64eadf6d7d8e41d263e0e/calendar-proxy-write',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}read',
                'principal' => 'principals/sharee/54b64eadf6d7d8e41d263e0e/calendar-proxy-read',
                'protected' => true,
            ],
            [
                'privilege' => '{' . Plugin::NS_CALDAV . '}read-free-busy',
                'principal' => '{DAV:}authenticated',
                'protected' => true,
            ]
        ];

        $this->assertEquals($sharedCalendar->getChildACL(), $expected);
    }

    function getAuthentificated($calendar) {
        $acl = $calendar->getACL();

        $authenticatedACE = null;
        foreach ($acl as &$ace) {
            if (strcmp($ace['principal'], '{DAV:}authenticated') === 0) {
                $authenticatedACE = $ace;
            }
        }

        return $authenticatedACE;
    }

    function testGetACLCalendarWithPublicRight() {
        $backend = $this->getBackend();

        $calendarESN = new \ESN\CalDAV\CalendarHome($backend, ['uri' => 'principals/user/' . $this->calendarId]);
        $calendarSabre = new \Sabre\CalDAV\CalendarHome($backend, ['uri' => 'principals/user/' . $this->calendarId]);

        $sharedCalendarESN =  $calendarESN->getChild($this->calendarId);
        $sharedCalendarSabre =  $calendarSabre->getChild($this->calendarId);

        $childACLOrig = $sharedCalendarSabre->getChildACL();
        array_push($childACLOrig, $this->getAuthentificated($sharedCalendarESN));

        $this->assertTrue($childACLOrig == $sharedCalendarESN->getChildACL());
    }

    function updatePublicWriteAcl($acl) {
        $index = array_search('{DAV:}authenticated', array_column($acl, 'principal'));
        if ($index) {
            $acl[$index]['privilege'] = '{DAV:}write';
            $acl[] = [
                'privilege' => '{DAV:}read',
                'principal' => '{DAV:}authenticated',
                'protected' => true,
            ];
        }

        return $acl;
    }

    function testGetACLCalendarWithWritePublicRight() {
        $backend = $this->getBackend();

        $calendarESN = new \ESN\CalDAV\CalendarHome($backend, ['uri' => 'principals/user/' . $this->calendarId]);
        $sharedCalendarESN =  $calendarESN->getChild($this->calendarId);

        $childACLOrig = $sharedCalendarESN->getChildACL();
        $sharedCalendarESN->savePublicRight('{DAV:}write');

        $this->assertTrue($this->updatePublicWriteAcl($childACLOrig) == $sharedCalendarESN->getChildACL());
    }

    function testSavePublicRight() {
        $backend = $this->getBackend();

        $calendarESN = new \ESN\CalDAV\CalendarHome($backend, ['uri' => 'principals/user/' . $this->calendarId]);
        $sharedCalendarESN =  $calendarESN->getChild($this->calendarId);

        $privilege = 'privilege';
        $sharedCalendarESN->savePublicRight($privilege);

        $acl = $sharedCalendarESN->getACL();
        $isPresent = true;
        foreach ($acl as &$ace) {
            if (strcmp($ace['privilege'], $privilege) === 0) {
                $isPresent = true;
            }
        }

        $this->assertTrue($isPresent);
    }

    function testGetPublicRight() {
        $backend = $this->getBackend();

        $calendarESN = new \ESN\CalDAV\CalendarHome($backend, ['uri' => 'principals/user/' . $this->calendarId]);
        $sharedCalendarESN =  $calendarESN->getChild($this->calendarId);

        $privilege = 'privilege';
        $sharedCalendarESN->savePublicRight($privilege);

        $this->assertEquals($sharedCalendarESN->getPublicRight(), $privilege);
    }

    function testIsPublic() {
        $backend = $this->getBackend();

        $calendarESN = new \ESN\CalDAV\CalendarHome($backend, ['uri' => 'principals/user/' . $this->calendarId]);
        $sharedCalendarESN =  $calendarESN->getChild($this->calendarId);

        $privilege = '{DAV:}read';
        $sharedCalendarESN->savePublicRight($privilege);

        $this->assertTrue($sharedCalendarESN->isPublic());
    }

    function testNotIsPublic() {
        $backend = $this->getBackend();

        $calendarESN = new \ESN\CalDAV\CalendarHome($backend, ['uri' => 'principals/user/' . $this->calendarId]);
        $sharedCalendarESN =  $calendarESN->getChild($this->calendarId);

        $this->assertFalse($sharedCalendarESN->isPublic());
    }

    function testNotIsPublicPrivateRight() {
        $backend = $this->getBackend();

        $calendarESN = new \ESN\CalDAV\CalendarHome($backend, ['uri' => 'principals/user/' . $this->calendarId]);
        $sharedCalendarESN =  $calendarESN->getChild($this->calendarId);

        $privilege = '';
        $sharedCalendarESN->savePublicRight($privilege);

        $this->assertFalse($sharedCalendarESN->isPublic());
    }

    function testNotIsPublicUnknownRight() {
        $backend = $this->getBackend();

        $calendarESN = new \ESN\CalDAV\CalendarHome($backend, ['uri' => 'principals/user/' . $this->calendarId]);
        $sharedCalendarESN =  $calendarESN->getChild($this->calendarId);

        $privilege = 'privilege';
        $sharedCalendarESN->savePublicRight($privilege);

        $this->assertFalse($sharedCalendarESN->isPublic());
    }

    function testGetSubscribers() {
        $props = [
            'id'                                        => 1,
            '{http://calendarserver.org/ns/}shared-url' => 'calendars/owner/original',
            '{http://sabredav.org/ns}owner-principal'   => 'principals/owner/54b64eadf6d7d8e41d263e0f',
            '{http://sabredav.org/ns}read-only'         => false,
            'share-access'                              => \ESN\DAV\Sharing\Plugin::ACCESS_ADMINISTRATION,
            'principaluri'                              => 'principals/owner/54b64eadf6d7d8e41d263e0f',
            'uri'                                       => 'publicCal1',
        ];

        $backend = new SimpleBackendMock([$props], [], []);
        $sharedCalendar = new SharedCalendar(new \Sabre\CalDAV\SharedCalendar($backend, $props));

        $subscribers = $sharedCalendar->getSubscribers();

        $this->assertEquals(count($subscribers), 2);

    }

    function testGetInviteStatus() {
        $backend = $this->getBackend();

        $calendarESN = new \ESN\CalDAV\CalendarHome($backend, ['uri' => 'principals/user/' . $this->calendarId]);
        $sharedCalendarESN =  $calendarESN->getChild($this->calendarId);

        $inviteStatus = 2;
        $this->assertEquals($sharedCalendarESN->getInviteStatus(), $inviteStatus);
    }

    function testUpdateInviteStatus() {
        $backend = $this->getBackend();

        $calendarESN = new \ESN\CalDAV\CalendarHome($backend, ['uri' => 'principals/user/' . $this->calendarId]);
        $sharedCalendarESN =  $calendarESN->getChild($this->calendarId);

        $status = 5;
        $sharedCalendarESN->updateInviteStatus($status);

        $calendarESN = new \ESN\CalDAV\CalendarHome($backend, ['uri' => 'principals/user/' . $this->calendarId]);
        $sharedCalendarESN =  $calendarESN->getChild($this->calendarId);

        $this->assertEquals($status, $sharedCalendarESN->getInviteStatus());
    }

    function testDefaultIsNotSharedInstance() {
        $backend = $this->getBackend();

        $calendarESN = new \ESN\CalDAV\CalendarHome($backend, ['uri' => 'principals/user/' . $this->calendarId]);
        $sharedCalendarESN =  $calendarESN->getChild($this->calendarId);

        $this->assertFalse($sharedCalendarESN->isSharedInstance());
    }

    function testOwnerIsNotSharedInstance() {
        $props = [
            'id'                                        => 1,
            'share-access'                              => \ESN\DAV\Sharing\Plugin::ACCESS_SHAREDOWNER,
        ];

        $backend = new SimpleBackendMock([$props], [], []);

        $sharedCalendar = new SharedCalendar(new \Sabre\CalDAV\SharedCalendar($backend, $props));

        $this->assertFalse($sharedCalendar->isSharedInstance());
    }

    function testReaderIsSharedInstance() {
        $props = [
            'id'                                        => 1,
            'share-access'                              => \ESN\DAV\Sharing\Plugin::ACCESS_READ,
        ];

        $backend = new SimpleBackendMock([$props], [], []);

        $sharedCalendar = new SharedCalendar(new \Sabre\CalDAV\SharedCalendar($backend, $props));

        $this->assertTrue($sharedCalendar->isSharedInstance());
    }

    function testGetOwner() {
        $backend = $this->getBackend();

        $calendarESN = new \ESN\CalDAV\CalendarHome($backend, ['uri' => 'principals/user/' . $this->calendarId]);
        $sharedCalendarESN =  $calendarESN->getChild($this->calendarId);

        $this->assertEquals($sharedCalendarESN->getOwner(), 'principals/user/54b64eadf6d7d8e41d263e0f');
    }

}

class SimpleBackendMock extends \Sabre\CalDAV\Backend\MockSharing {
    function getCalendarPublicRight() {
        return null;
    }

    function getSubscribers($source) {
        $subscribers = [];
        $subscribers[] = [
            'principaluri' => 'principals/subscriber/56664eadf6d7d8e41d263esz',
            'uri'          => 'subscription',
            'source'       => 'calendars/54b64eadf6d7d8e41d263e0f/publicCal1'
        ];
        $subscribers[] = [
            'principaluri' => 'principals/subscriber/5sdf64eadf6d7d8e41d23e54',
            'uri'          => 'subscription2',
            'source'       => 'calendars/54b64eadf6d7d8e41d263e0f/publicCal1'
        ];

        $match = array_keys(array_column($subscribers, 'source'), $source);
          return $match;
    }
}
