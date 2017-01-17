<?php

namespace ESN\CalDAV;

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

        $calendarESN = new \ESN\CalDAV\CalendarHome($backend, ['uri' => 'principals/user2']);
        $calendarSabre = new \Sabre\CalDAV\CalendarHome($backend, ['uri' => 'principals/user2']);

        $sharedCalendarESN =  $calendarESN->getChild('events');
        $sharedCalendarSabre =  $calendarSabre->getChild('events');

        $this->assertTrue($sharedCalendarESN->getACL() == $sharedCalendarSabre->getACL());
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

        $calendarESN = new \ESN\CalDAV\CalendarHome($backend, ['uri' => 'principals/user2']);
        $calendarSabre = new \Sabre\CalDAV\CalendarHome($backend, ['uri' => 'principals/user2']);

        $sharedCalendarESN =  $calendarESN->getChild('events');
        $sharedCalendarSabre =  $calendarSabre->getChild('events');

        $childACLOrig = $sharedCalendarSabre->getChildACL();
        array_push($childACLOrig, $this->getAuthentificated($sharedCalendarESN));

        $this->assertTrue($childACLOrig == $sharedCalendarESN->getChildACL());
    }

    function testSavePublicRight() {
        $backend = $this->getBackend();

        $calendarESN = new \ESN\CalDAV\CalendarHome($backend, ['uri' => 'principals/user2']);
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
}