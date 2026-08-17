<?php

namespace ESN\CalDAV;

use Sabre\DAV\Server;
use Sabre\DAV\SimpleCollection;
use Sabre\DAV\SimpleFile;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;
use Sabre\VObject\Reader;

class TeamCalendarMetadataPluginTest extends \PHPUnit\Framework\TestCase {
    function testShouldReplaceClientSuppliedMarkerWithDestinationTeamCalendarId() {
        list($server,) = $this->newServer('principals/team-calendars/team-calendar-id');
        $calendar = Reader::read($this->event('forged-id'));

        $modified = $this->emitPut($server, $calendar);

        $this->assertTrue($modified);
        $this->assertSame('team-calendar-id', $calendar->VEVENT->{TeamCalendarMetadataPlugin::PROPERTY}->getValue());
    }

    function testShouldAddMissingMarkerToEveryVevent() {
        list($server,) = $this->newServer('principals/team-calendars/team-calendar-id');
        $calendar = Reader::read($this->event());

        $modified = $this->emitPut($server, $calendar);

        $this->assertTrue($modified);
        $this->assertSame('team-calendar-id', $calendar->VEVENT->{TeamCalendarMetadataPlugin::PROPERTY}->getValue());
    }

    function testShouldLeavePersonalCalendarEventUntouched() {
        list($server,) = $this->newServer('principals/users/alice');
        $calendar = Reader::read($this->event());

        $modified = $this->emitPut($server, $calendar);

        $this->assertFalse($modified);
        $this->assertFalse(isset($calendar->VEVENT->{TeamCalendarMetadataPlugin::PROPERTY}));
    }

    function testShouldNormalizeWhenScheduleReplyIsDisabled() {
        list($server,) = $this->newServer('principals/team-calendars/team-calendar-id');
        $calendar = Reader::read($this->event('forged-id'));

        $modified = $this->emitPut($server, $calendar, ['Schedule-Reply' => 'F']);

        $this->assertTrue($modified);
        $this->assertSame('team-calendar-id', $calendar->VEVENT->{TeamCalendarMetadataPlugin::PROPERTY}->getValue());
    }

    function testShouldNormalizeImportedEvent() {
        list($server,) = $this->newServer('principals/team-calendars/team-calendar-id');
        $calendar = Reader::read($this->event());

        $modified = $this->emitPut($server, $calendar, [], '?import');

        $this->assertTrue($modified);
        $this->assertSame('team-calendar-id', $calendar->VEVENT->{TeamCalendarMetadataPlugin::PROPERTY}->getValue());
    }

    function testMoveShouldNormalizeDestinationBeforeLaterAfterMoveListeners() {
        list($server, $destinationCalendar) = $this->newServer('principals/team-calendars/team-calendar-id');
        $event = new TeamCalendarMetadataEventTestDouble('event.ics', $this->event('forged-id'));
        $destinationCalendar->addChild($event);
        $observedMarker = null;

        $server->on('afterMove', function () use (&$observedMarker, $event) {
            $calendar = Reader::read($event->get());
            $observedMarker = $calendar->VEVENT->{TeamCalendarMetadataPlugin::PROPERTY}->getValue();
        });
        $server->emit('afterMove', ['calendars/personal-home/personal/event.ics', 'calendars/team-home/team-calendar/event.ics']);

        $this->assertSame(1, $event->putCount);
        $this->assertSame('team-calendar-id', $observedMarker);
    }

    function testMoveShouldEmitCalendarObjectUpdatedByServer() {
        list($server, $destinationCalendar, $sourceCalendar) = $this->newServer('principals/team-calendars/team-calendar-id');
        $sourceCalendar->addChild(new TeamCalendarMetadataEventTestDouble('event.ics', $this->event()));
        $destinationCalendar->addChild(new TeamCalendarMetadataEventTestDouble('event.ics', $this->event()));
        $change = null;

        $server->on('calendarObjectUpdatedByServer', function ($oldData, $newData, $calendarPath, $changeProperties, $localRecipientsOnly) use (&$change) {
            $change = [$oldData, $newData, $calendarPath, $changeProperties, $localRecipientsOnly];
        });

        $sourcePath = 'calendars/personal-home/personal/event.ics';
        $destinationPath = 'calendars/team-home/team-calendar/event.ics';
        $server->emit('beforeMove', [$sourcePath, $destinationPath]);
        $server->emit('afterMove', [$sourcePath, $destinationPath]);

        $this->assertNotNull($change);
        $this->assertFalse(isset(Reader::read($change[0])->VEVENT->{TeamCalendarMetadataPlugin::PROPERTY}));
        $this->assertSame('team-calendar-id', Reader::read($change[1])->VEVENT->{TeamCalendarMetadataPlugin::PROPERTY}->getValue());
        $this->assertSame('calendars/team-home/team-calendar', $change[2]);
        $this->assertSame([TeamCalendarMetadataPlugin::PROPERTY], $change[3]);
        $this->assertTrue($change[4]);
    }

    function testMoveShouldNotTrustMatchingMarkerFromPersonalCalendar() {
        list($server, $destinationCalendar, $sourceCalendar) = $this->newServer('principals/team-calendars/team-calendar-id');
        $sourceCalendar->addChild(new TeamCalendarMetadataEventTestDouble('event.ics', $this->event('team-calendar-id')));
        $destinationEvent = new TeamCalendarMetadataEventTestDouble('event.ics', $this->event('team-calendar-id'));
        $destinationCalendar->addChild($destinationEvent);
        $change = null;
        $server->on('calendarObjectUpdatedByServer', function ($oldData, $newData) use (&$change) {
            $change = [$oldData, $newData];
        });

        $sourcePath = 'calendars/personal-home/personal/event.ics';
        $destinationPath = 'calendars/team-home/team-calendar/event.ics';
        $server->emit('beforeMove', [$sourcePath, $destinationPath]);
        $server->emit('afterMove', [$sourcePath, $destinationPath]);

        $this->assertNotNull($change);
        $this->assertFalse(isset(Reader::read($change[0])->VEVENT->{TeamCalendarMetadataPlugin::PROPERTY}));
        $this->assertSame('team-calendar-id', Reader::read($change[1])->VEVENT->{TeamCalendarMetadataPlugin::PROPERTY}->getValue());
        $this->assertSame(0, $destinationEvent->putCount);
    }

    function testMoveShouldLeaveNonTeamDestinationUntouched() {
        list($server, $destinationCalendar) = $this->newServer('principals/users/alice');
        $event = new TeamCalendarMetadataEventTestDouble('event.ics', $this->event('forged-id'));
        $destinationCalendar->addChild($event);

        $server->emit('afterMove', ['calendars/personal-home/personal/event.ics', 'calendars/team-home/team-calendar/event.ics']);

        $this->assertSame(0, $event->putCount);
        $calendar = Reader::read($event->get());
        $this->assertSame('forged-id', $calendar->VEVENT->{TeamCalendarMetadataPlugin::PROPERTY}->getValue());
    }

    private function newServer(string $owner): array {
        $calendar = new TeamCalendarMetadataCalendarTestDouble('team-calendar', $owner);
        $personalCalendar = new SimpleCollection('personal');
        $server = new Server([
            new SimpleCollection('calendars', [
                new SimpleCollection('team-home', [$calendar]),
                new SimpleCollection('personal-home', [$personalCalendar]),
            ]),
        ]);
        $server->addPlugin(new TeamCalendarMetadataPlugin());

        return [$server, $calendar, $personalCalendar];
    }

    private function emitPut(Server $server, $calendar, array $headers = [], string $query = ''): bool {
        $modified = false;
        $server->emit('calendarObjectChange', [
            new Request('PUT', '/calendars/team-home/team-calendar/event.ics' . $query, $headers),
            new Response(),
            $calendar,
            'calendars/team-home/team-calendar',
            &$modified,
            true,
        ]);

        return $modified;
    }

    private function event(?string $teamCalendarId = null): string {
        $marker = $teamCalendarId ? 'X-OPENPAAS-TEAM-CALENDAR-ID:' . $teamCalendarId . "\r\n" : '';

        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:event\r\nDTSTART:20260101T090000Z\r\n"
            . $marker
            . "END:VEVENT\r\nEND:VCALENDAR\r\n";
    }
}

class TeamCalendarMetadataCalendarTestDouble extends SimpleCollection {
    private $owner;

    function __construct(string $name, string $owner) {
        parent::__construct($name);
        $this->owner = $owner;
    }

    function getOwner() {
        return $this->owner;
    }
}

class TeamCalendarMetadataEventTestDouble extends SimpleFile implements \Sabre\CalDAV\ICalendarObject {
    public $putCount = 0;

    function put($data) {
        if (is_resource($data)) {
            $data = stream_get_contents($data);
        }

        $this->contents = $data;
        $this->putCount++;

        return $this->getETag();
    }
}
