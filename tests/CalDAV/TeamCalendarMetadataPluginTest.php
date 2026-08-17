<?php

namespace ESN\CalDAV;

use Sabre\DAV\Server;
use Sabre\DAV\SimpleCollection;
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

    private function newServer(string $owner): array {
        $calendar = new TeamCalendarMetadataCalendarTestDouble('team-calendar', $owner);
        $server = new Server([
            new SimpleCollection('calendars', [
                new SimpleCollection('team-home', [$calendar]),
            ]),
        ]);
        $server->addPlugin(new TeamCalendarMetadataPlugin());

        return [$server, $calendar];
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
