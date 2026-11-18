<?php

namespace ESN\CalDAV\Schedule;

require_once ESN_TEST_BASE . '/DAV/ServerMock.php';

/**
 * Pins down how many user e-mail lookups scheduling an event sends to MongoDB:
 * the recipients are resolved together, so their number must not change it.
 * External attendees take the case-folded fallback, which is batched as well.
 *
 * @medium
 */
class AttendeeLookupBudgetTest extends \ESN\DAV\ServerMock {

    const ORGANIZER = 'robertocarlos@realmadrid.com';
    const EVENT_PATH = '/calendars/54b64eadf6d7d8e41d263e0f/calendar1/lookup-budget.ics';
    const FEW_ATTENDEES = ['johndoe@example.org', 'external@external.test'];
    const MANY_ATTENDEES = [
        'johndoe@example.org', 'johndoe2@example.org', 'janedoe@example.org',
        'external@external.test', 'other-external@external.test'
    ];

    function testSchedulingShouldResolveTheAttendeesTogether() {
        $this->server->addPlugin(new Plugin($this->principalBackend));

        $fewAttendeesLookups = $this->emailLookupsOf(fn() => $this->put('one', self::FEW_ATTENDEES));
        $this->request($this->deleteRequest());

        $manyAttendeesLookups = $this->emailLookupsOf(fn() => $this->put('many', self::MANY_ATTENDEES));

        $this->assertSame($fewAttendeesLookups, $manyAttendeesLookups);
        $this->assertRecipientPrincipalsResolved(
            ['johndoe@example.org', 'johndoe2@example.org', 'janedoe@example.org'],
            ['external@external.test', 'other-external@external.test']
        );
    }

    function testCancellingShouldResolveTheAttendeesTogether() {
        $this->server->addPlugin(new Plugin($this->principalBackend));

        $this->put('one', self::FEW_ATTENDEES);
        $fewAttendeesLookups = $this->emailLookupsOf(fn() => $this->request($this->deleteRequest()));

        $this->put('many', self::MANY_ATTENDEES);
        $manyAttendeesLookups = $this->emailLookupsOf(fn() => $this->request($this->deleteRequest()));

        $this->assertSame($fewAttendeesLookups, $manyAttendeesLookups);
    }

    /**
     * The broker is handed the recipients as they are: nobody needs looking up.
     */
    function testAsynchronousSchedulingShouldNotLookTheAttendeesUp() {
        $this->server->addPlugin(new AMQPSchedulePlugin(new DiscardingAmqpPublisher(), $this->principalBackend));

        $this->assertSame(0, $this->emailLookupsOf(fn() => $this->put('many', self::MANY_ATTENDEES)));
    }

    function testAsynchronousCancellingShouldNotLookTheAttendeesUp() {
        $this->server->addPlugin(new AMQPSchedulePlugin(new DiscardingAmqpPublisher(), $this->principalBackend));
        $this->put('many', self::MANY_ATTENDEES);

        $this->assertSame(0, $this->emailLookupsOf(fn() => $this->request($this->deleteRequest())));
    }

    /**
     * 3.7 is the status of a recipient without principal: the local attendees must
     * still be found, and the external ones still missed.
     */
    private function assertRecipientPrincipalsResolved(array $localAttendees, array $externalAttendees): void {
        $organizerCopy = $this->sabredb->calendarobjects->findOne([
            'calendarid' => $this->cal['id'][0],
            'uri' => basename(self::EVENT_PATH)
        ]);
        $this->assertNotNull($organizerCopy, 'the event should have been stored');
        $calendarData = str_replace("\r\n ", '', $organizerCopy['calendardata']);

        foreach ($localAttendees as $attendee) {
            $this->assertMatchesRegularExpression($this->scheduleStatusPattern('[0-9.]+', $attendee), $calendarData);
            $this->assertDoesNotMatchRegularExpression($this->scheduleStatusPattern('3\.7', $attendee), $calendarData);
        }
        foreach ($externalAttendees as $attendee) {
            $this->assertMatchesRegularExpression($this->scheduleStatusPattern('3\.7', $attendee), $calendarData);
        }
    }

    private function scheduleStatusPattern(string $status, string $attendee): string {
        return '/ATTENDEE;[^\r\n]*SCHEDULE-STATUS=' . $status . '[;:][^\r\n]*mailto:' . preg_quote($attendee, '/') . '\r/';
    }

    private function emailLookupsOf(callable $operation): int {
        $counter = new UserEmailLookupCounter();
        \MongoDB\Driver\Monitoring\addSubscriber($counter);
        try {
            $operation();
        } finally {
            \MongoDB\Driver\Monitoring\removeSubscriber($counter);
        }

        return $counter->lookups;
    }

    private function put(string $summary, array $attendees): void {
        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'REQUEST_METHOD' => 'PUT',
            'REQUEST_URI'    => self::EVENT_PATH,
            'CONTENT_TYPE'   => 'text/calendar'
        ]);
        $request->setBody($this->event($summary, $attendees));

        $response = $this->request($request);
        $this->assertContains($response->status, [201, 204], 'PUT should succeed');
    }

    private function deleteRequest() {
        return \Sabre\HTTP\Sapi::createFromServerArray([
            'REQUEST_METHOD' => 'DELETE',
            'REQUEST_URI'    => self::EVENT_PATH
        ]);
    }

    private function event(string $summary, array $attendees): string {
        return implode("\r\n", array_merge([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Linagora//Twake-Calendar//EN',
            'BEGIN:VEVENT',
            'UID:lookup-budget-' . $summary,
            'DTSTAMP:20260313T142416Z',
            'DTSTART:20260327T090000Z',
            'DTEND:20260327T100000Z',
            'SUMMARY:' . $summary,
            'ORGANIZER:mailto:' . self::ORGANIZER,
            'ATTENDEE;PARTSTAT=ACCEPTED:mailto:' . self::ORGANIZER
        ], array_map(
            fn($attendee) => 'ATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:' . $attendee,
            $attendees
        ), [
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]));
    }
}

/**
 * Counts the find commands looking users up by e-mail address.
 */
class UserEmailLookupCounter implements \MongoDB\Driver\Monitoring\CommandSubscriber {
    public int $lookups = 0;

    function commandStarted(\MongoDB\Driver\Monitoring\CommandStartedEvent $event): void {
        $command = $event->getCommand();
        $isUserEmailLookup = $event->getCommandName() === 'find'
            && $command->find === 'users'
            && str_contains(json_encode($command->filter ?? []), 'accounts.emails');

        if ($isUserEmailLookup) {
            $this->lookups++;
        }
    }

    function commandSucceeded(\MongoDB\Driver\Monitoring\CommandSucceededEvent $event): void {}

    function commandFailed(\MongoDB\Driver\Monitoring\CommandFailedEvent $event): void {}
}

class DiscardingAmqpPublisher {
    function publish($topic, $message) {}
}
