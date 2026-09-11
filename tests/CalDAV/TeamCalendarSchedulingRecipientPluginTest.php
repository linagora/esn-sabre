<?php

namespace ESN\CalDAV;

use Sabre\DAV\Server;

class TeamCalendarSchedulingRecipientPluginTest extends \PHPUnit\Framework\TestCase {
    function testMoveShouldSaveCalendarOwnerAsSchedulingRecipient() {
        [$server, $backend] = $this->newSchedulingRecipientMoveServer('mailto:bob@example.com');
        $saved = false;
        $backend->expects($this->once())->method('setCalendarObjectSchedulingRecipient')
            ->with('destination-physical', 'renamed+event.ics', 'principals/users/alice')
            ->willReturnCallback(function () use (&$saved) { $saved = true; });
        $server->on('afterMove', function () use (&$saved) { $this->assertTrue($saved); }, 50);

        $server->emit('beforeMove', ['source/event.ics', 'destination/renamed+event.ics']);
        $this->assertFalse($saved);
        $server->emit('afterMove', ['source/event.ics', 'destination/renamed+event.ics']);
    }

    function testMoveShouldNotBindOrganizerOrUnrelatedOwner() {
        foreach (['mailto:alice@example.com', 'mailto:charlie@example.com'] as $ownerAddress) {
            [$server, $backend] = $this->newSchedulingRecipientMoveServer('mailto:alice@example.com', $ownerAddress);
            $backend->expects($this->never())->method('setCalendarObjectSchedulingRecipient');
            $server->emit('beforeMove', ['source/event.ics', 'destination/event.ics']);
            $server->emit('afterMove', ['source/event.ics', 'destination/event.ics']);
        }
    }

    function testFailedMoveShouldClearCapturedSchedulingRecipient() {
        [$server, $backend] = $this->newSchedulingRecipientMoveServer('mailto:bob@example.com');
        $backend->expects($this->never())->method('setCalendarObjectSchedulingRecipient');
        $server->emit('beforeMove', ['source/event.ics', 'destination/event.ics']);
        $server->emit('exception', [new \RuntimeException('Move failed')]);
        $server->emit('afterMove', ['source/event.ics', 'destination/event.ics']);
    }

    function testMoveShouldPropagateSchedulingRecipientPersistenceFailure() {
        [$server, $backend] = $this->newSchedulingRecipientMoveServer('mailto:bob@example.com');
        $backend->expects($this->once())->method('setCalendarObjectSchedulingRecipient')->willThrowException(new \RuntimeException('Storage failed'));
        $server->emit('beforeMove', ['source/event.ics', 'destination/event.ics']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Storage failed');
        $server->emit('afterMove', ['source/event.ics', 'destination/event.ics']);
    }

    private function newSchedulingRecipientMoveServer(string $organizer, string $ownerAddress = 'mailto:alice@example.com'): array {
        $backend = $this->createMock(\ESN\CalDAV\Backend\Mongo::class);
        $backend->method('getCalendarObjectSchedulingRecipient')->willReturn(null);
        $object = $this->createStub(\Sabre\CalDAV\ICalendarObject::class);
        $object->method('getName')->willReturn('event.ics');
        $object->method('get')->willReturn("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:event\r\nORGANIZER:$organizer\r\nATTENDEE:mailto:alice@example.com\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
        $source = $this->createStub(\ESN\CalDAV\SharedCalendar::class);
        $source->method('getName')->willReturn('source');
        $source->method('getOwner')->willReturn('principals/users/alice');
        $source->method('getCalendarId')->willReturn('source-physical');
        $source->method('getChild')->willReturn($object);
        $destination = $this->createStub(\ESN\CalDAV\SharedCalendar::class);
        $destination->method('getName')->willReturn('destination');
        $destination->method('getOwner')->willReturn('principals/team-calendars/team');
        $destination->method('getCalendarId')->willReturn('destination-physical');
        $server = $this->getMockBuilder(Server::class)->setConstructorArgs([[$source, $destination]])
            ->onlyMethods(['getProperties'])->getMock();
        $property = '{urn:ietf:params:xml:ns:caldav}calendar-user-address-set';
        $server->expects($this->once())->method('getProperties')->with('principals/users/alice', [$property])
            ->willReturn([$property => new \Sabre\DAV\Xml\Property\Href([$ownerAddress])]);
        $server->addPlugin(new \ESN\CalDAV\TeamCalendarSchedulingRecipientPlugin($backend));
        return [$server, $backend];
    }

}
