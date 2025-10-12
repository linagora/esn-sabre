<?php

namespace ESN\CalDAV;

#[\AllowDynamicProperties]
class CalDAVBackendMock extends \Sabre\CalDAV\Backend\AbstractBackend {
    // @codingStandardsIgnoreStart
    function getCalendarsForUser($principalUri) { return []; }
    function createCalendar($principalUri,$calendarUri,array $properties) {}
    function deleteCalendar($calendarId) {}
    function getCalendarObjects($calendarId) { return []; }
    function getCalendarObject($calendarId,$objectUri) { return null; }
    function getMultipleCalendarObjects($calendarId, array $uris) { return []; }
    function createCalendarObject($calendarId,$objectUri,$calendarData) { return null; }
    function updateCalendarObject($calendarId,$objectUri,$calendarData) { return null; }
    function deleteCalendarObject($calendarId,$objectUri) {}
    function getInvites() { return []; }
    function getSubscribers() { return []; }
    // @codingStandardsIgnoreEnd
}


#[\AllowDynamicProperties]
class MockAuthBackend {
    function getAuthCookies() {
        return "coookies!!!";
    }
}

#[\AllowDynamicProperties]
class MockedDocument extends \Sabre\VObject\Document {

}