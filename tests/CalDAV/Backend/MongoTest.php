<?php

namespace ESN\CalDAV\Backend;

require_once 'AbstractDatabaseTestBase.php';
require_once ESN_TEST_BASE . '/DAV/MongoFindCounter.php';

/**
 * @medium
 */
class MongoTest extends AbstractDatabaseTestBase {
    protected function generateId() {
        return [(string) new \MongoDB\BSON\ObjectId(), (string) new \MongoDB\BSON\ObjectId()];
    }

    protected function getBackend() {
        $mc = new \MongoDB\Client(ESN_MONGO_SABREURI);
        $db = $mc->{ESN_MONGO_SABREDB};
        $db->drop();
        return new Mongo($db);
    }

    function testGetMultipleCalendarObjectsQueriesByBatches() {
        $backend = $this->getBackend();
        $calendarId = $backend->createCalendar('principals/users/alice', 'calendar', []);
        // Just over one batch
        $count = \ESN\CalDAV\Backend\Service\CalendarObjectService::MULTIGET_BATCH_SIZE + 10;
        $uris = [];
        for ($i = 0; $i < $count; $i++) {
            $uris[] = 'event' . $i . '.ics';
            $backend->createCalendarObject($calendarId, 'event' . $i . '.ics', "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:event-" . $i . "\r\nDTSTART;VALUE=DATE:20120101\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
        }
        $uris[] = 'missing.ics';

        $queries = \ESN\DAV\MongoFindCounter::count('calendarobjects', function () use ($backend, $calendarId, $uris, &$objects) {
            $objects = $backend->getMultipleCalendarObjects($calendarId, $uris);
        });

        $this->assertEquals(2, $queries);
        $this->assertEqualsCanonicalizing(array_slice($uris, 0, $count), array_column($objects, 'uri'));
    }

    function testSchedulingRecipientShouldRemainInternalAndSurviveContentUpdate() {
        $backend = $this->getBackend();
        $principal = 'principals/users/alice';
        $calendarId = $backend->createCalendar($principal, 'calendar', []);
        $ics = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:event-123\r\nDTSTART;VALUE=DATE:20120101\r\nSUMMARY:Before\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $backend->createCalendarObject($calendarId, 'event.ics', $ics);
        $this->assertNull($backend->getCalendarObjectSchedulingRecipient($calendarId[0], 'event.ics'));
        $this->assertNull($backend->getCalendarObjectSchedulingRecipient($calendarId[0], 'missing.ics'));

        $db = (new \MongoDB\Client(ESN_MONGO_SABREURI))->{ESN_MONGO_SABREDB};
        $dao = new DAO\CalendarObjectDAO($db);
        $dao->updateCalendarObject($calendarId[0], 'event.ics', ['metadata.other' => 'preserved']);
        $before = $backend->getCalendarObject($calendarId, 'event.ics');
        $listing = $backend->getCalendarObjects($calendarId);
        $changes = $backend->getChangesForCalendar($calendarId, null, 1);

        $backend->setCalendarObjectSchedulingRecipient($calendarId[0], 'event.ics', $principal);
        $backend->setCalendarObjectSchedulingRecipient($calendarId[0], 'event.ics', $principal);
        $this->assertSame($principal, $backend->getCalendarObjectSchedulingRecipient($calendarId[0], 'event.ics'));
        $this->assertSame($before, $backend->getCalendarObject($calendarId, 'event.ics'));
        $this->assertSame($listing, $backend->getCalendarObjects($calendarId));
        $this->assertSame($changes, $backend->getChangesForCalendar($calendarId, null, 1));

        $updatedIcs = str_replace('SUMMARY:Before', 'SUMMARY:After', $ics);
        $backend->updateCalendarObject($calendarId, 'event.ics', $updatedIcs);
        $this->assertSame($principal, $backend->getCalendarObjectSchedulingRecipient($calendarId[0], 'event.ics'));
        $object = $backend->getCalendarObject($calendarId, 'event.ics');
        $this->assertSame($updatedIcs, $object['calendardata']);
        $this->assertArrayNotHasKey('metadata', $object);
        $stored = $dao->findByUri([$calendarId[0]], 'event.ics');
        $this->assertSame('preserved', $stored['metadata']['other']);
    }

    function testFindCalendarObjectsBySchedulingRecipientShouldReturnPhysicalLocation() {
        $backend = $this->getBackend();
        $alice = 'principals/users/alice';
        $bob = 'principals/users/bob';
        $personal = $backend->createCalendar($bob, 'personal', []);
        $destination = $backend->createCalendar($alice, 'destination', []);
        $ics = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:event-123\r\nDTSTART;VALUE=DATE:20120101\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $backend->createCalendarObject($personal, 'organizer.ics', $ics);
        $backend->createCalendarObject($destination, 'renamed.ics', $ics);
        $backend->createCalendarObject($destination, 'other-recipient.ics', $ics);
        $backend->setCalendarObjectSchedulingRecipient($destination[0], 'renamed.ics', $alice);
        $backend->setCalendarObjectSchedulingRecipient($destination[0], 'other-recipient.ics', $bob);

        $this->assertSame([
            ['calendarid' => $destination[0], 'uri' => 'renamed.ics']
        ], $backend->findCalendarObjectsBySchedulingRecipient('event-123', $alice, [$destination[0]]));
        $this->assertSame([], $backend->findCalendarObjectsBySchedulingRecipient('event-123', $alice, [$personal[0]]));
        $this->assertSame([], $backend->findCalendarObjectsBySchedulingRecipient('event-12', $alice, [$destination[0]]));
        $this->assertSame([], $backend->findCalendarObjectsBySchedulingRecipient('event-123', 'principals/users/unknown', [$destination[0]]));
    }

    function testSettingSchedulingRecipientOnMissingObjectShouldFail() {
        $backend = $this->getBackend();
        $calendarId = $backend->createCalendar('principals/users/alice', 'calendar', []);
        $this->expectException(\RuntimeException::class);
        $backend->setCalendarObjectSchedulingRecipient($calendarId[0], 'missing.ics', 'principals/users/alice');
    }

    function testReplyCandidatesShouldExcludeMovedAttendeeCopies() {
        $backend = $this->getBackend();
        $organizer = $backend->createCalendar('principals/team-calendars/first', 'first', []);
        $attendee = $backend->createCalendar('principals/team-calendars/second', 'second', []);
        $ics = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:reply-event\r\nDTSTART;VALUE=DATE:20120101\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $backend->createCalendarObject($organizer, 'original.ics', $ics);
        $backend->createCalendarObject($attendee, 'moved.ics', $ics);
        $backend->setCalendarObjectSchedulingRecipient($attendee[0], 'moved.ics', 'principals/users/alice');
        $this->assertSame([['calendarid' => $organizer[0], 'uri' => 'original.ics']],
            $backend->findCalendarObjectsByUidWithoutSchedulingRecipient('reply-event', [$organizer[0], $attendee[0]]));
        $this->assertSame([], $backend->findCalendarObjectsByUidWithoutSchedulingRecipient('reply-event', [$attendee[0]]));
        $this->assertSame([], $backend->findCalendarObjectsByUidWithoutSchedulingRecipient('other-event', [$organizer[0]]));
    }

    function testConstruct() {
        $backend = $this->getBackend();
        $this->assertTrue($backend instanceof Mongo);
    }
}
