<?php

namespace ESN\CalDAV;

require_once ESN_TEST_BASE . '/DAV/ServerMock.php';
require_once ESN_TEST_BASE . '/DAV/MongoFindCounter.php';

/**
 * Pins down how many times a request on one event reads it from Mongo.
 *
 * The ACL check, CorePlugin::httpPut and every realtime message used to call
 * Tree::nodeExists, which bypasses the node cache: a PUT on an existing event
 * read the same row up to five times. See ESN\DAV\Tree.
 *
 * @medium
 */
class CalendarObjectReadBudgetTest extends \ESN\DAV\ServerMock {

    const EVENT_PATH = '/calendars/54b64eadf6d7d8e41d263e0f/calendar1/event1.ics';
    const NEW_EVENT_PATH = '/calendars/54b64eadf6d7d8e41d263e0f/calendar1/created.ics';

    private $publisher;

    function setUp(): void {
        parent::setUp();

        $this->publisher = new RecordingPublisher();
        $this->server->addPlugin(new Schedule\AMQPSchedulePlugin(new RecordingPublisher(), $this->principalBackend));
        $this->server->addPlugin(new \ESN\Publisher\CalDAV\EventRealTimePlugin($this->publisher, $this->caldavBackend));
    }

    function testGetShouldReadTheEventOnce() {
        $response = null;
        $finds = $this->countFinds(function () use (&$response) {
            $response = $this->request([
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI'    => self::EVENT_PATH
            ]);
        });

        $this->assertSame(200, $response->status);
        $this->assertSame(1, $finds);
    }

    function testUpdateShouldReadTheEventOnce() {
        $response = null;
        $finds = $this->countFinds(function () use (&$response) {
            $response = $this->request($this->putRequest(self::EVENT_PATH, 'event1', 'Rewritten'));
        });

        $this->assertSame(204, $response->status);
        $this->assertSame(1, $finds);
    }

    function testUpdateShouldPublishTheNewEtag() {
        $response = $this->request($this->putRequest(self::EVENT_PATH, 'event1', 'Rewritten'));

        $this->assertSame(204, $response->status);
        $this->assertNotEmpty($this->publisher->messages);
        $this->assertSame([$response->getHeader('ETag')], $this->publishedEtags());
    }

    function testCreateShouldReadTheEventAtMostThreeTimes() {
        $response = null;
        $finds = $this->countFinds(function () use (&$response) {
            $response = $this->request($this->putRequest(self::NEW_EVENT_PATH, 'created', 'Created'));
        });

        $this->assertSame(201, $response->status);
        // Twice to learn it is absent (ACL check, httpPut), once for the etag of the realtime messages.
        $this->assertLessThanOrEqual(3, $finds);
        $this->assertSame([$response->getHeader('ETag')], $this->publishedEtags());
    }

    function testDeleteShouldNotPublishAnEtag() {
        $response = $this->request([
            'REQUEST_METHOD' => 'DELETE',
            'REQUEST_URI'    => self::EVENT_PATH
        ]);

        $this->assertSame(204, $response->status);
        $this->assertNotEmpty($this->publisher->messages);
        $this->assertSame([], $this->publishedEtags());
    }

    private function countFinds(callable $callback) {
        return \ESN\DAV\MongoFindCounter::count('calendarobjects', $callback);
    }

    private function publishedEtags() {
        return array_values(array_unique(array_filter(array_map(
            fn($message) => json_decode($message['payload'], true)['etag'] ?? null,
            $this->publisher->messages
        ))));
    }

    private function putRequest($path, $uid, $summary) {
        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'REQUEST_METHOD' => 'PUT',
            'REQUEST_URI'    => $path,
            'CONTENT_TYPE'   => 'text/calendar'
        ]);
        $request->setBody(implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Linagora//Twake-Calendar//EN',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:20120313T142416Z',
            'DTSTART:20120227T000000Z',
            'DTEND:20120227T010000Z',
            'SUMMARY:' . $summary,
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]));

        return $request;
    }
}

class RecordingPublisher {
    public $messages = [];

    function publish($topic, $payload) {
        $this->messages[] = ['topic' => $topic, 'payload' => $payload];
    }
}
