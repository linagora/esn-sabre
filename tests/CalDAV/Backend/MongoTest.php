<?php

namespace ESN\CalDAV\Backend;

use ESN\DAV\ChangeLog\ChangeLog;
use ESN\DAV\ChangeLog\ChangeLogPruner;

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

    function testFindCalendarObjectsByUidShouldFilterSchedulingRecipient() {
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
        $this->assertCount(3, $backend->findCalendarObjectsByUid('event-123', [$personal[0], $destination[0]]));

        $this->assertSame([
            ['calendarid' => $destination[0], 'uri' => 'renamed.ics']
        ], $backend->findCalendarObjectsByUid('event-123', [$destination[0]], ['metadata.schedulingRecipientPrincipalUri' => $alice]));
        $this->assertSame([], $backend->findCalendarObjectsByUid('event-123', [$personal[0]], ['metadata.schedulingRecipientPrincipalUri' => $alice]));
        $this->assertSame([], $backend->findCalendarObjectsByUid('event-12', [$destination[0]], ['metadata.schedulingRecipientPrincipalUri' => $alice]));
        $this->assertSame([], $backend->findCalendarObjectsByUid('event-123', [$destination[0]], ['metadata.schedulingRecipientPrincipalUri' => 'principals/users/unknown']));
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
            $backend->findCalendarObjectsByUid('reply-event', [$organizer[0], $attendee[0]], ['metadata.schedulingRecipientPrincipalUri' => null]));
        $this->assertSame([], $backend->findCalendarObjectsByUid('reply-event', [$attendee[0]], ['metadata.schedulingRecipientPrincipalUri' => null]));
        $this->assertSame([], $backend->findCalendarObjectsByUid('other-event', [$organizer[0]], ['metadata.schedulingRecipientPrincipalUri' => null]));
    }

    function testConstruct() {
        $backend = $this->getBackend();
        $this->assertTrue($backend instanceof Mongo);
    }

    function testWeekQueryOnlyPostFiltersRecurringEvents() {
        $backend = $this->getBackend();
        $calendarId = $backend->createCalendar('principals/users/alice', 'calendar', []);
        $backend->createCalendarObject($calendarId, 'single.ics', $this->event('single', "DTSTART:20350102T090000Z\r\nDTEND:20350102T100000Z\r\n"));
        $backend->createCalendarObject($calendarId, 'single-outside.ics', $this->event('single-outside', "DTSTART:20350110T090000Z\r\nDTEND:20350110T100000Z\r\n"));
        $backend->createCalendarObject($calendarId, 'legacy.ics', $this->event('legacy', "DTSTART:20350103T090000Z\r\nDTEND:20350103T100000Z\r\n"));
        $backend->createCalendarObject($calendarId, 'weekly.ics', $this->event('weekly', "DTSTART:20341225T090000Z\r\nDTEND:20341225T100000Z\r\nRRULE:FREQ=WEEKLY;COUNT=3\r\n"));
        // Its occurrence hull overlaps the range, none of its instances does
        $backend->createCalendarObject($calendarId, 'monthly.ics', $this->event('monthly', "DTSTART:20341215T090000Z\r\nDTEND:20341215T100000Z\r\nRRULE:FREQ=MONTHLY;COUNT=3\r\n"));
        // Stored before the recurring marker existed
        $collection = (new \MongoDB\Client(ESN_MONGO_SABREURI))->{ESN_MONGO_SABREDB}->calendarobjects;
        $collection->updateOne(['uri' => 'legacy.ics'], ['$unset' => ['recurring' => '']]);

        $filters = [
            'name' => 'VCALENDAR',
            'comp-filters' => [[
                'name' => 'VEVENT',
                'comp-filters' => [],
                'prop-filters' => [],
                'is-not-defined' => false,
                'time-range' => ['start' => new \DateTime('20350101T000000Z'), 'end' => new \DateTime('20350108T000000Z')],
            ]],
            'prop-filters' => [],
            'is-not-defined' => false,
            'time-range' => null,
        ];

        $parsed = [];
        foreach ($backend->calendarQueryWithAllData($calendarId, $filters) as $object) {
            $parsed[$object['uri']] = $object['vObject'] !== null;
        }

        $this->assertEquals(['single.ics' => false, 'legacy.ics' => true, 'weekly.ics' => true], $parsed);
        $this->assertEqualsCanonicalizing(['single.ics', 'legacy.ics', 'weekly.ics'], $backend->calendarQuery($calendarId, $filters));
    }

    function testChangesAreDatedForTheirPruning() {
        $backend = $this->getBackend();
        $calendarId = $backend->createCalendar('principals/users/alice', 'calendar', []);
        $backend->createCalendarObject($calendarId, 'event.ics', $this->event('event', "DTSTART;VALUE=DATE:20120101\r\n"));

        $change = $this->changes()->findOne(['uri' => 'event.ics']);

        $this->assertInstanceOf(\MongoDB\BSON\UTCDateTime::class, $change['date']);
        $this->assertEqualsWithDelta(time(), $change['date']->toDateTime()->getTimestamp(), 60);
    }

    function testChangesAreIndexedByDateWithoutExpiry() {
        $this->getBackend()->ensureIndexes();

        $dateIndexes = array_values(array_filter(
            iterator_to_array($this->changes()->listIndexes()),
            fn ($index) => $index->getKey() === ['date' => 1]
        ));
        $this->assertCount(1, $dateIndexes);
        $this->assertFalse($dateIndexes[0]->isTtl());
    }

    function testSyncTokenBelowThePrunedSyncTokenIsInvalid() {
        $backend = $this->getBackend();
        $calendarId = $backend->createCalendar('principals/users/alice', 'calendar', []);
        $this->writeEvents($backend, $calendarId, ['e1.ics', 'e2.ics', 'e3.ics']);

        $this->setPrunedSyncToken($calendarId, 3);

        $this->assertNull($backend->getChangesForCalendar($calendarId, 1, 1));
        $this->assertNull($backend->getChangesForCalendar($calendarId, 2, 1));
        $this->assertEquals(
            ['syncToken' => 4, 'added' => ['e3.ics'], 'modified' => [], 'deleted' => []],
            $backend->getChangesForCalendar($calendarId, 3, 1)
        );
        $this->assertEqualsCanonicalizing(
            ['e1.ics', 'e2.ics', 'e3.ics'],
            $backend->getChangesForCalendar($calendarId, null, 1)['added']
        );
    }

    function testSyncTokenStaysValidWithoutPrunedSyncToken() {
        $backend = $this->getBackend();
        $calendarId = $backend->createCalendar('principals/users/alice', 'calendar', []);
        $this->writeEvents($backend, $calendarId, ['e1.ics', 'e2.ics']);

        $this->assertEquals(
            ['syncToken' => 3, 'added' => ['e1.ics', 'e2.ics'], 'modified' => [], 'deleted' => []],
            $backend->getChangesForCalendar($calendarId, 1, 1)
        );
    }

    function testPruningInvalidatesTheSyncTokensOfThePrunedChangesOnly() {
        $backend = $this->getBackend();
        $calendarId = $backend->createCalendar('principals/users/alice', 'calendar', []);
        $this->writeEvents($backend, $calendarId, ['e1.ics', 'e2.ics', 'e3.ics']);
        $this->backdateChanges(['e1.ics', 'e2.ics'], 100);

        $this->pruner()->prune(ChangeLog::calendars(), 90);

        $this->assertSame(1, $this->changes()->countDocuments());
        $this->assertNull($backend->getChangesForCalendar($calendarId, 2, 1));
        $this->assertEquals(
            ['syncToken' => 4, 'added' => ['e3.ics'], 'modified' => [], 'deleted' => []],
            $backend->getChangesForCalendar($calendarId, 3, 1)
        );
        $this->assertEquals(
            ['syncToken' => 4, 'added' => [], 'modified' => [], 'deleted' => []],
            $backend->getChangesForCalendar($calendarId, 4, 1)
        );
    }

    private function writeEvents($backend, $calendarId, array $uris) {
        array_map(
            fn ($uri) => $backend->createCalendarObject($calendarId, $uri, $this->event($uri, "DTSTART;VALUE=DATE:20120101\r\n")),
            $uris
        );
    }

    private function setPrunedSyncToken($calendarId, int $prunedSyncToken) {
        $this->database()->calendars->updateOne(
            ['_id' => new \MongoDB\BSON\ObjectId($calendarId[0])],
            ['$set' => [ChangeLog::PRUNED_SYNC_TOKEN => $prunedSyncToken]]
        );
    }

    private function backdateChanges(array $uris, int $days) {
        $this->changes()->updateMany(
            ['uri' => ['$in' => $uris]],
            ['$set' => ['date' => new \MongoDB\BSON\UTCDateTime((time() - $days * 86400) * 1000)]]
        );
    }

    private function pruner() {
        return new ChangeLogPruner($this->database(), new \Psr\Log\NullLogger());
    }

    private function changes() {
        return $this->database()->calendarchanges;
    }

    private function database() {
        return (new \MongoDB\Client(ESN_MONGO_SABREURI))->{ESN_MONGO_SABREDB};
    }

    private function event($uid, $dates) {
        return "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:" . $uid . "\r\n" . $dates . "END:VEVENT\r\nEND:VCALENDAR\r\n";
    }
}
