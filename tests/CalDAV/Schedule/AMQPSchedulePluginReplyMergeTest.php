<?php

namespace ESN\CalDAV\Schedule;

require_once __DIR__ . '/AMQPSchedulePluginPayloadTest.php';

/**
 * The broker emits one REPLY per changed occurrence, and they are merged into a
 * single delivery. Two REPLYs answering for the same occurrence must not leave
 * two VEVENTs for it in the merged message: the latest answer wins.
 */
class AMQPSchedulePluginReplyMergeTest extends \PHPUnit\Framework\TestCase {

    const EVENT_PATH = '/calendars/54b64eadf6d7d8e41d263e0f/calendar1/series.ics';
    const OCCURRENCE_UTC = 'RECURRENCE-ID:20351006T090000Z';
    const OCCURRENCE_FLOATING = 'RECURRENCE-ID:20351006T090000';

    private $publisher;
    private $plugin;

    function setUp(): void {
        $server = new \Sabre\DAV\Server();
        $server->httpRequest = new \Sabre\HTTP\Request('PUT', self::EVENT_PATH);

        $this->publisher = new RecordingAmqpPublisher();
        $this->plugin = new AMQPSchedulePlugin($this->publisher);
        $server->addPlugin($this->plugin);
    }

    function testMergedReplyHasOneVeventPerOccurrence() {
        $this->plugin->scheduleLocalDelivery($this->reply([
            $this->vevent(null, 'ACCEPTED'),
            $this->vevent(self::OCCURRENCE_UTC, 'ACCEPTED'),
        ]));
        $this->plugin->scheduleLocalDelivery($this->reply([$this->vevent(self::OCCURRENCE_UTC, 'DECLINED')]));
        $this->plugin->afterWrite();

        $this->assertSame(['master' => 'ACCEPTED', '2075274000' => 'DECLINED'], $this->publishedPartstats());
    }

    function testFloatingAndUtcRecurrenceIdDesignateTheSameOccurrence() {
        $this->plugin->scheduleLocalDelivery($this->reply([$this->vevent(self::OCCURRENCE_UTC, 'ACCEPTED')]));
        $this->plugin->scheduleLocalDelivery($this->reply([$this->vevent(self::OCCURRENCE_FLOATING, 'TENTATIVE')]));
        $this->plugin->afterWrite();

        $this->assertSame(['2075274000' => 'TENTATIVE'], $this->publishedPartstats());
    }

    function testRepliesForDistinctOccurrencesAreAllKept() {
        $this->plugin->scheduleLocalDelivery($this->reply([$this->vevent(null, 'ACCEPTED')]));
        $this->plugin->scheduleLocalDelivery($this->reply([$this->vevent(self::OCCURRENCE_UTC, 'DECLINED')]));
        $this->plugin->afterWrite();

        $this->assertSame(['master' => 'ACCEPTED', '2075274000' => 'DECLINED'], $this->publishedPartstats());
    }

    private function publishedPartstats(): array {
        $this->assertCount(1, $this->publisher->published, 'the REPLYs should be merged into one delivery');
        $message = \Sabre\VObject\Reader::read(json_decode($this->publisher->published[0][1], true)['message']);
        $occurrences = array_map(
            fn($event) => isset($event->{'RECURRENCE-ID'})
                ? (string)$event->{'RECURRENCE-ID'}->getDateTime(new \DateTimeZone('UTC'))->getTimestamp()
                : 'master',
            $message->select('VEVENT')
        );
        $partstats = array_map(fn($event) => (string)$event->ATTENDEE['PARTSTAT'], $message->select('VEVENT'));
        $this->assertSame(array_values(array_unique($occurrences)), array_values($occurrences), 'merged REPLY repeats an occurrence');
        return array_combine($occurrences, $partstats);
    }

    private function reply(array $vevents): \Sabre\VObject\ITip\Message {
        $message = new \Sabre\VObject\ITip\Message();
        $message->method = 'REPLY';
        $message->uid = 'series';
        $message->sender = 'mailto:alice@local.test';
        $message->recipient = 'mailto:organizer@local.test';
        $message->hasChange = true;
        $message->message = \Sabre\VObject\Reader::read(implode("\r\n", array_merge(
            ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Test//Test//EN', 'METHOD:REPLY'],
            ...array_merge($vevents, [['END:VCALENDAR', '']])
        )));
        return $message;
    }

    private function vevent(?string $recurrenceId, string $partstat): array {
        return array_merge(
            ['BEGIN:VEVENT', 'UID:series', 'DTSTAMP:20260101T000000Z', 'DTSTART:20351006T090000Z'],
            $recurrenceId ? [$recurrenceId] : [],
            [
                'ORGANIZER:mailto:organizer@local.test',
                'ATTENDEE;PARTSTAT=' . $partstat . ':mailto:alice@local.test',
                'END:VEVENT',
            ]
        );
    }
}
