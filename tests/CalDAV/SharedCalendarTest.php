<?php

namespace ESN\CalDAV;

require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/CalDAV/Backend/MockSharing.php';

/**
 * @medium
 */
class SharedCalendarTest extends \PHPUnit_Framework_TestCase {

    protected function getBackend() {
        $mc = new \MongoClient(ESN_MONGO_SABREURI);
        $db = $mc->selectDB(ESN_MONGO_SABREDB);
        $db->drop();

        return new \ESN\CalDAV\Backend\Esn($db);
    }

    function testGetACLIfNoPublicRight() {
        $backend = $this->getBackend();

        $calendarESN = new \ESN\CalDAV\CalendarHome($backend, ['uri' => 'principals/user/54b64eadf6d7d8e41d263e0f']);
        $calendarSabre = new \Sabre\CalDAV\CalendarHome($backend, ['uri' => 'principals/user/54b64eadf6d7d8e41d263e0f']);

        $sharedCalendarESN =  $calendarESN->getChild('events');
        $sharedCalendarSabre =  $calendarSabre->getChild('events');

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
                'privilege' => '{DAV:}write-acl',
                'principal' => 'principals/sharee/54b64eadf6d7d8e41d263e0e',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}write-acl',
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

        $calendarESN = new \ESN\CalDAV\CalendarHome($backend, ['uri' => 'principals/user/54b64eadf6d7d8e41d263e0f']);
        $calendarSabre = new \Sabre\CalDAV\CalendarHome($backend, ['uri' => 'principals/user/54b64eadf6d7d8e41d263e0f']);

        $sharedCalendarESN =  $calendarESN->getChild('events');
        $sharedCalendarSabre =  $calendarSabre->getChild('events');

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

        $calendarESN = new \ESN\CalDAV\CalendarHome($backend, ['uri' => 'principals/user/54b64eadf6d7d8e41d263e0f']);
        $sharedCalendarESN =  $calendarESN->getChild('events');
        
        $childACLOrig = $sharedCalendarESN->getChildACL();
        $sharedCalendarESN->savePublicRight('{DAV:}write');
        
        $this->assertTrue($this->updatePublicWriteAcl($childACLOrig) == $sharedCalendarESN->getChildACL());
    }

    function testSavePublicRight() {
        $backend = $this->getBackend();

        $calendarESN = new \ESN\CalDAV\CalendarHome($backend, ['uri' => 'principals/user/54b64eadf6d7d8e41d263e0f']);
        $sharedCalendarESN =  $calendarESN->getChild('events');

        $privilege = 'droit';
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

        $calendarESN = new \ESN\CalDAV\CalendarHome($backend, ['uri' => 'principals/user/54b64eadf6d7d8e41d263e0f']);
        $sharedCalendarESN =  $calendarESN->getChild('events');

        $privilege = 'droit';
        $sharedCalendarESN->savePublicRight($privilege);

        $this->assertEquals($sharedCalendarESN->getPublicRight(), $privilege);
    }

    function testIsPublic() {
        $backend = $this->getBackend();

        $calendarESN = new \ESN\CalDAV\CalendarHome($backend, ['uri' => 'principals/user/54b64eadf6d7d8e41d263e0f']);
        $sharedCalendarESN =  $calendarESN->getChild('events');

        $privilege = 'droit';
        $sharedCalendarESN->savePublicRight($privilege);

        $this->assertTrue($sharedCalendarESN->isPublic());
    }

    function testNotIsPublic() {
        $backend = $this->getBackend();

        $calendarESN = new \ESN\CalDAV\CalendarHome($backend, ['uri' => 'principals/user/54b64eadf6d7d8e41d263e0f']);
        $sharedCalendarESN =  $calendarESN->getChild('events');

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
            'uri'                                       => 'sharedcal',
        ];

        $backend = new SimpleBackendMock([$props], [], []);
        $sharedCalendar = new SharedCalendar(new \Sabre\CalDAV\SharedCalendar($backend, $props));

        $subscribers = $sharedCalendar->getSubscribers();

        $this->assertEquals(count($subscribers), 2);

    }

    function testGetSubscribersWithOptions() {
        $props = [
            'id'                                        => 1,
            '{http://calendarserver.org/ns/}shared-url' => 'calendars/owner/original',
            '{http://sabredav.org/ns}owner-principal'   => 'principals/owner/54b64eadf6d7d8e41d263e0f',
            '{http://sabredav.org/ns}read-only'         => false,
            'share-access'                              => \ESN\DAV\Sharing\Plugin::ACCESS_ADMINISTRATION,
            'principaluri'                              => 'principals/owner/54b64eadf6d7d8e41d263e0f',
            'uri'                                       => 'sharedcal',
        ];

        $backend = new SimpleBackendMock([$props], [], []);
        $sharedCalendar = new SharedCalendar(new \Sabre\CalDAV\SharedCalendar($backend, $props));

        $subscribers = $sharedCalendar->getSubscribers(['baseUri' => 'baseuri/', 'extension' => '.json']);

        $this->assertEquals(count($subscribers), 1);

    }

    function testGetInviteStatus() {
        $backend = $this->getBackend();

        $calendarESN = new \ESN\CalDAV\CalendarHome($backend, ['uri' => 'principals/user/54b64eadf6d7d8e41d263e0f']);
        $sharedCalendarESN =  $calendarESN->getChild('events');

        $inviteStatus = 2;
        $this->assertEquals($sharedCalendarESN->getInviteStatus(), $inviteStatus);
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
            'source'       => '/calendars/54b64eadf6d7d8e41d263e0f/sharedcal'
        ];
        $subscribers[] = [
            'principaluri' => 'principals/subscriber/5sdf64eadf6d7d8e41d23e54',
            'uri'          => 'subscription2',
            'source'       => '/calendars/54b64eadf6d7d8e41d263e0f/sharedcal'
        ];
        $subscribers[] = [
            'principaluri' => 'principals/subscriber/5sdf64eadf6d7d8e41d23e54',
            'uri'          => 'subscription3',
            'source'       => 'baseuri/calendars/54b64eadf6d7d8e41d263e0f/sharedcal.json'
        ];

        $match = array_keys(array_column($subscribers, 'source'), $source);
          return $match;
    }
}