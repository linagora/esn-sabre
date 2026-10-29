<?php

namespace ESN\CalDAV\Schedule;

use ESN\CalDAV\BinaryAttachmentPlugin;
use ESN\CalDAV\VideoConferencePlugin;
use Sabre\DAV\ServerPlugin;
use Sabre\VObject\Document;
use Sabre\VObject\ITip\Message;

/**
 * @medium
 */
#[\AllowDynamicProperties]
class ITipPluginTest extends \PHPUnit\Framework\TestCase
{

    use \Sabre\VObject\PHPUnitAssertions;

    protected $iTipRequestData = [
        'method' => 'REPLY',
        'uid' => 'recur',
        'sequence' => '1',
        'sender' => 'a@linagora.com',
        'recipient' => 'b@linagora.com',
        'ical' => 'BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
CREATED:30120313T142342Z
UID:event1
DTEND;TZID=Europe/Berlin:20120227T000000
TRANSP:OPAQUE
SUMMARY:Monday 0h
DTSTART;TZID=Europe/Berlin:30120227T000000
DTSTAMP:30120313T142416Z
SEQUENCE:4
ORGANIZER;CN=B:mailto:b@linagora.com
ATTENDEE:mailto:b@linagora.com
ATTENDEE:mailto:a@linagora.com
END:VEVENT
END:VCALENDAR'
    ];

    function setUp(): void
    {
        $this->iTipPlugin = new StoredOrganizerITipPlugin(['principals/users/b' => 'mailto:b@linagora.com']);
        $this->calDavPlugin = new CalDavPluginMock();
        $this->authPlugin = new AuthPluginMock('principals/users/b');
        $this->aclPlugin = new AclPluginMock([
            'mailto:a@linagora.com' => 'principals/users/a',
            'mailto:b@linagora.com' => 'principals/users/b',
            'mailto:c@linagora.com' => 'principals/users/c',
            'mailto:x@linagora.com' => 'principals/users/x',
        ]);
        $this->server = new \Sabre\DAV\Server([]);
        $this->server->addPlugin($this->authPlugin);
        $this->server->addPlugin($this->aclPlugin);
        $this->server->addPlugin($this->iTipPlugin);
        $this->server->addPlugin($this->calDavPlugin);
    }

    private function setAuthenticatedPrincipal(string $principal): void
    {
        $this->authPlugin->setCurrentPrincipal($principal);
    }

    function makeRequest($body)
    {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'ITIP',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f',
        ));

        $request->setBody(json_encode($body));

        return $request;
    }

    function testITipShouldReturn400IfUIDIsMissing()
    {
        $this->iTipRequestData['uid'] = null;
        $request = $this->makeRequest($this->iTipRequestData);

        $this->iTipPlugin->iTip($request);

        $response = $this->server->httpResponse;

        $this->assertEquals(400, $response->getStatus());
    }
    
    function testITipShouldReturn400IfRecipientIsMissing()
    {
        $this->iTipRequestData['recipient'] = null;
        $request = $this->makeRequest($this->iTipRequestData);

        $this->iTipPlugin->iTip($request);

        $response = $this->server->httpResponse;

        $this->assertEquals(400, $response->getStatus());
    }

    function testITipShouldReturn400IfSenderIsMissing()
    {
        $this->iTipRequestData['sender'] = null;
        $request = $this->makeRequest($this->iTipRequestData);
       
        $this->iTipPlugin->iTip($request);

        $response = $this->server->httpResponse;

        $this->assertEquals(400, $response->getStatus());
    }

    function testITipShouldReturn400IfIcalIsMissing()
    {
        
        $this->iTipRequestData['ical'] = null;
        $request = $this->makeRequest($this->iTipRequestData);
        
        $this->iTipPlugin->iTip($request);

        $response = $this->server->httpResponse;

        $this->assertEquals(400, $response->getStatus());
    }

    private function assertRawBodyIsRejected(string $body)
    {
        $request = $this->makeRequest([]);
        $request->setBody($body);

        $this->assertFalse($this->iTipPlugin->iTip($request));
        $this->assertEquals(400, $this->server->httpResponse->getStatus());
    }

    function testITipShouldReturn400IfSenderIsAbsent()
    {
        $this->assertRawBodyIsRejected('{"uid":"x","recipient":"b@linagora.com","ical":"X"}');
    }

    function testITipShouldReturn400IfBodyIsNotAnObject()
    {
        $this->assertRawBodyIsRejected('"not an object"');
    }

    function testITipShouldReturn400IfBodyIsNotJson()
    {
        $this->assertRawBodyIsRejected('not json');
    }

    function testITipShouldReturn400IfIcalIsNotParsable()
    {
        $this->iTipRequestData['ical'] = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nEND:VEVE:T\r\nEND:VCALENDAR\r\n";

        $this->assertRawBodyIsRejected(json_encode($this->iTipRequestData));
    }

    function testITipShouldReturn400IfIcalIsNotICalendar()
    {
        $this->iTipRequestData['ical'] = 'not ical';

        $this->assertRawBodyIsRejected(json_encode($this->iTipRequestData));
    }

    function testITipShouldReturn400IfIcalIsAVCard()
    {
        $this->iTipRequestData['ical'] = "BEGIN:VCARD\r\nVERSION:4.0\r\nFN:a\r\nEND:VCARD\r\n";

        $this->assertRawBodyIsRejected(json_encode($this->iTipRequestData));
    }

    function testITipShouldReturn400IfSenderIsNotAString()
    {
        $this->iTipRequestData['sender'] = ['a@linagora.com'];

        $this->assertRawBodyIsRejected(json_encode($this->iTipRequestData));
    }

    function testITipShouldReturn400IfReplyToIsNotAString()
    {
        $this->iTipRequestData['replyTo'] = 42;

        $this->assertRawBodyIsRejected(json_encode($this->iTipRequestData));
    }

    function testITipShouldReturn400IfMethodIsNotAScalar()
    {
        $this->iTipRequestData['method'] = ['COUNTER'];

        $this->assertRawBodyIsRejected(json_encode($this->iTipRequestData));
    }

    function testITipCounterShouldReturn400WhenDtstartIsNotParsable()
    {
        $this->iTipRequestData['method'] = 'COUNTER';
        $this->iTipRequestData['sender'] = 'b@linagora.com';
        $this->iTipRequestData['ical'] = str_replace('DTSTART;TZID=Europe/Berlin:30120227T000000',
            'DTSTART:', $this->iTipRequestData['ical']);

        $this->assertRawBodyIsRejected(json_encode($this->iTipRequestData));
    }

    function testITipRequestShouldReturn204WhenAttendeeAddressDiffersFromRecipient()
    {
        // The ICS carries an arbitrary participant address (organizer b, attendees b/a) that
        // does not string-match the targeted recipient c. The routing decision (the URL /
        // recipient resolving to the authenticated principal) is authoritative, so the iTIP
        // must still be delivered onto c's calendar rather than rejected with a 400.
        $this->iTipRequestData['method'] = 'REQUEST';
        $this->iTipRequestData['recipient'] = 'c@linagora.com';
        $this->setAuthenticatedPrincipal('principals/users/c');
        $request = $this->makeRequest($this->iTipRequestData);

        $this->iTipPlugin->iTip($request);

        $response = $this->server->httpResponse;

        $this->assertEquals(204, $response->getStatus());
    }
    
    function testITipShouldReturn204IfRecipientIsConcernedByEvent()
    {
        $request = $this->makeRequest($this->iTipRequestData);

        $this->iTipPlugin->iTip($request);

        $response = $this->server->httpResponse;

        $this->assertEquals(204, $response->getStatus());
    }

    function testITipRequestFiltersUntrustedLinksBeforeLocalDelivery()
    {
        $this->server->addPlugin(new BinaryAttachmentPlugin(
            BinaryAttachmentPlugin::MODE_FILTER, 'https://{fdqn}-drive.linagora.com'));
        $this->server->addPlugin(new VideoConferencePlugin('https://meet.linagora.com'));
        $this->iTipRequestData['method'] = 'REQUEST';
        $this->iTipRequestData['ical'] = str_replace('END:VEVENT',
            "X-OPENPAAS-VIDEOCONFERENCE:https://meet.linagora.com/room\n" .
            "X-OPENPAAS-VIDEOCONFERENCE:https://meet.linagora.com.evil.test/room\n" .
            "CONFERENCE;VALUE=URI;FEATURE=VIDEO:https://meet.linagora.com.evil.test/other\n" .
            "ATTACH:https://tung-drive.linagora.com/file.pdf\n" .
            "ATTACH:https://tung-drive.linagora.com.evil.test/file.pdf\nEND:VEVENT",
            $this->iTipRequestData['ical']);

        $this->iTipPlugin->iTip($this->makeRequest($this->iTipRequestData));

        $this->assertEquals(204, $this->server->httpResponse->getStatus());
        $delivered = $this->calDavPlugin->deliveredMessage->message->serialize();
        $this->assertStringContainsString('https://meet.linagora.com/room', $delivered);
        $this->assertStringContainsString('https://tung-drive.linagora.com/file.pdf', $delivered);
        $this->assertStringNotContainsString('meet.linagora.com.evil.test', $delivered);
        $this->assertStringNotContainsString('tung-drive.linagora.com.evil.test', $delivered);
    }
    
    function testITipShouldEmitITipIfMethodeNotCounter()
    {
        $iTipCalled = false;
        $scheduleCalled = false;
        $this->server->on('iTip', function() use (&$iTipCalled) {
            $iTipCalled = true;
        });
        $this->server->on('schedule', function() use (&$scheduleCalled) {
            $scheduleCalled = true;
        });
        $request = $this->makeRequest($this->iTipRequestData);

        $this->iTipPlugin->itip($request);

        $response = $this->server->httpResponse;

        $this->assertTrue($iTipCalled);
        $this->assertFalse($scheduleCalled);
        $this->assertEquals(204, $response->getStatus());
    }
    
    function testITipShouldEmitScheduleIfMethodeCounter()
    {
        $iTipCalled = false;
        $scheduleCalled = false;
        $this->server->on('iTip', function() use (&$iTipCalled) {
            $itipCalled = true;
        });
        $this->server->on('schedule', function() use (&$scheduleCalled) {
            $scheduleCalled = true;
        });
        $this->iTipRequestData['method'] = 'COUNTER';
        $this->iTipRequestData['sender'] = 'b@linagora.com';
        $request = $this->makeRequest($this->iTipRequestData);

        $this->iTipPlugin->iTip($request);

        $response = $this->server->httpResponse;

        $this->assertTrue($scheduleCalled);
        $this->assertFalse($iTipCalled);
        $this->assertEquals(204, $response->getStatus());
    }

    function testITipReplyWithoutOrganizerShouldReturn204WhenSenderIsAttendee()
    {
        $this->iTipRequestData['method'] = 'REPLY';
        $this->iTipRequestData['recipient'] = 'b@linagora.com';
        $this->iTipRequestData['sender'] = 'a@linagora.com';
        $this->iTipRequestData['ical'] = 'BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
CREATED:20120313T142342Z
UID:event1
DTEND;TZID=Europe/Berlin:20120227T000000
TRANSP:OPAQUE
SUMMARY:Monday 0h
DTSTART;TZID=Europe/Berlin:20120227T000000
DTSTAMP:20120313T142416Z
SEQUENCE:4
ATTENDEE:mailto:b@linagora.com
ATTENDEE:mailto:a@linagora.com
END:VEVENT
END:VCALENDAR';

        $request = $this->makeRequest($this->iTipRequestData);
        $this->iTipPlugin->iTip($request);

        $response = $this->server->httpResponse;
        $this->assertEquals(204, $response->getStatus());
    }

    function testITipReplyShouldReturn204WhenOrganizerAddressDiffersFromRecipient()
    {
        // REPLY whose ORGANIZER (b) is an arbitrary address that does not match the targeted
        // recipient c. Participant-identity string matching must not cause a rejection: the
        // request targets c's calendar, so it is delivered.
        $this->iTipRequestData['method'] = 'REPLY';
        $this->iTipRequestData['recipient'] = 'c@linagora.com';
        $this->iTipRequestData['sender'] = 'x@linagora.com';
        $this->setAuthenticatedPrincipal('principals/users/c');
        $this->iTipRequestData['ical'] = 'BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
CREATED:20120313T142342Z
UID:event1
DTEND;TZID=Europe/Berlin:20120227T000000
TRANSP:OPAQUE
SUMMARY:Monday 0h
DTSTART;TZID=Europe/Berlin:20120227T000000
DTSTAMP:20120313T142416Z
SEQUENCE:4
ORGANIZER;CN=B:mailto:b@linagora.com
ATTENDEE:mailto:b@linagora.com
ATTENDEE:mailto:a@linagora.com
END:VEVENT
END:VCALENDAR';

        $request = $this->makeRequest($this->iTipRequestData);
        $this->iTipPlugin->iTip($request);

        $response = $this->server->httpResponse;
        $this->assertEquals(204, $response->getStatus());
    }

    function testITipShouldReturn403IfMethodIsNotCounterAndAuthenticatedIsNotRecipient()
    {
        $this->setAuthenticatedPrincipal('principals/users/a');
        $request = $this->makeRequest($this->iTipRequestData);

        $this->iTipPlugin->iTip($request);

        $response = $this->server->httpResponse;
        $this->assertEquals(403, $response->getStatus());
    }

    function testITipCounterShouldReturn403WhenAuthenticatedCannotActAsSender()
    {
        $this->iTipRequestData['method'] = 'COUNTER';
        $request = $this->makeRequest($this->iTipRequestData);

        $this->iTipPlugin->iTip($request);

        $response = $this->server->httpResponse;
        $this->assertEquals(403, $response->getStatus());
    }

    function testITipCounterShouldReturn204WhenAuthenticatedHasWriteAccessToSender()
    {
        $this->iTipRequestData['method'] = 'COUNTER';
        $this->aclPlugin->setMemberships([
            'principals/users/a/calendar-proxy-write' => ['principals/users/b'],
        ]);
        $request = $this->makeRequest($this->iTipRequestData);

        $this->iTipPlugin->iTip($request);

        $response = $this->server->httpResponse;
        $this->assertEquals(204, $response->getStatus());
    }

    function testITipCounterShouldReturn403WhenRecipientIsNotStoredOrganizer()
    {
        $scheduleCalled = false;
        $this->server->on('schedule', function() use (&$scheduleCalled) {
            $scheduleCalled = true;
        });
        $this->iTipRequestData['method'] = 'COUNTER';
        $this->iTipRequestData['sender'] = 'b@linagora.com';
        $this->iTipRequestData['recipient'] = 'c@linagora.com';

        $this->iTipPlugin->iTip($this->makeRequest($this->iTipRequestData));

        $this->assertEquals(403, $this->server->httpResponse->getStatus());
        $this->assertFalse($scheduleCalled);
    }

    function testITipCounterShouldReturn403WhenRecipientIsExternalAddress()
    {
        $this->iTipRequestData['method'] = 'COUNTER';
        $this->iTipRequestData['sender'] = 'b@linagora.com';
        $this->iTipRequestData['recipient'] = 'qa-not-a-user@example.com';

        $this->iTipPlugin->iTip($this->makeRequest($this->iTipRequestData));

        $this->assertEquals(403, $this->server->httpResponse->getStatus());
    }

    function testITipCounterShouldIgnoreOrganizerOfSubmittedIcs()
    {
        $this->iTipRequestData['method'] = 'COUNTER';
        $this->iTipRequestData['sender'] = 'b@linagora.com';
        $this->iTipRequestData['recipient'] = 'c@linagora.com';
        $this->iTipRequestData['ical'] = str_replace('ORGANIZER;CN=B:mailto:b@linagora.com',
            'ORGANIZER:mailto:c@linagora.com', $this->iTipRequestData['ical']);

        $this->iTipPlugin->iTip($this->makeRequest($this->iTipRequestData));

        $this->assertEquals(403, $this->server->httpResponse->getStatus());
    }

    function testITipCounterShouldReturn403WhenStoredEventIsNotFound()
    {
        $this->iTipPlugin->setStoredOrganizers([]);
        $this->iTipRequestData['method'] = 'COUNTER';
        $this->iTipRequestData['sender'] = 'b@linagora.com';

        $this->iTipPlugin->iTip($this->makeRequest($this->iTipRequestData));

        $this->assertEquals(403, $this->server->httpResponse->getStatus());
    }

    function testITipCounterShouldMatchStoredOrganizerCaseInsensitively()
    {
        $this->iTipPlugin->setStoredOrganizers(['principals/users/b' => 'MAILTO:B@linagora.com']);
        $this->iTipRequestData['method'] = 'COUNTER';
        $this->iTipRequestData['sender'] = 'b@linagora.com';

        $this->iTipPlugin->iTip($this->makeRequest($this->iTipRequestData));

        $this->assertEquals(204, $this->server->httpResponse->getStatus());
    }

}

#[\AllowDynamicProperties]
class StoredOrganizerITipPlugin extends ITipPlugin
{
    // Stored ORGANIZER of the event, by principal whose calendar holds a copy.
    private $storedOrganizers;
    // ORGANIZERs of the Team Calendar copies, by principal writing to these Team Calendars.
    private $teamCalendarOrganizers = [];

    function __construct(array $storedOrganizers)
    {
        $this->storedOrganizers = $storedOrganizers;
    }

    function setStoredOrganizers(array $storedOrganizers): void
    {
        $this->storedOrganizers = $storedOrganizers;
    }

    function setTeamCalendarOrganizers(array $teamCalendarOrganizers): void
    {
        $this->teamCalendarOrganizers = $teamCalendarOrganizers;
    }

    protected function findStoredOrganizer(string $principalUri, Message $message): ?string
    {
        return $this->storedOrganizers[$principalUri] ?? null;
    }

    protected function findTeamCalendarOrganizers(string $principalUri, Message $message): array
    {
        return $this->teamCalendarOrganizers[$principalUri] ?? [];
    }
}

#[\AllowDynamicProperties]
class CalDavPluginMock extends ServerPlugin
{
    public $deliveredMessage;

    function getPluginName()
    {
        return 'caldav-schedule';
    }

    function initialize(\Sabre\DAV\Server $server)
    {
        $this->server = $server;
    }

    function scheduleLocalDelivery($message)
    {
        $this->deliveredMessage = $message;
    }

}

#[\AllowDynamicProperties]
class AuthPluginMock extends ServerPlugin
{
    private $currentPrincipal;

    function __construct(string $currentPrincipal)
    {
        $this->currentPrincipal = $currentPrincipal;
    }

    function getPluginName()
    {
        return 'auth';
    }

    function initialize(\Sabre\DAV\Server $server)
    {
        $this->server = $server;
    }

    function setCurrentPrincipal(string $principal): void
    {
        $this->currentPrincipal = $principal;
    }

    function getCurrentPrincipal(): string
    {
        return $this->currentPrincipal;
    }
}

#[\AllowDynamicProperties]
class AclPluginMock extends ServerPlugin
{
    private $principalMap;
    private $writablePaths = [];
    private $memberships = [];

    function __construct(array $principalMap)
    {
        $this->principalMap = $principalMap;
    }

    function getPluginName()
    {
        return 'acl';
    }

    function initialize(\Sabre\DAV\Server $server)
    {
        $this->server = $server;
    }

    function setWritablePaths(array $paths): void
    {
        $this->writablePaths = $paths;
    }

    function setMemberships(array $memberships): void
    {
        $this->memberships = $memberships;
    }

    function getPrincipalByUri($uri)
    {
        if (!is_string($uri)) {
            return null;
        }

        $normalized = strtolower($uri);
        return $this->principalMap[$normalized] ?? null;
    }

    function principalMatchesPrincipal($candidate, $current): bool
    {
        $c = strtolower(trim((string)$candidate, '/'));
        $p = strtolower(trim((string)$current, '/'));
        if ($c === $p) return true;
        return in_array($p, $this->memberships[$c] ?? [], true);
    }

    function checkPrivileges($path, $privilege, $recursion = null, $throwExceptions = null): bool
    {
        return in_array(ltrim((string)$path, '/'), $this->writablePaths, true);
    }
}
