<?php

namespace ESN\Utils;

/**
 * Test for issue #178: Infinite loop when cloning VObject
 *
 * @medium
 */
class VObjectCloneTest extends \PHPUnit_Framework_TestCase {

    /**
     * Test that safeCloneVObject doesn't cause infinite recursion
     * Issue #178: Maximum execution time exceeded when cloning vobject
     */
    function testSafeCloneVEventWithParametersDoesNotCauseInfiniteLoop() {
        $icalData = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            'UID:test-event-clone',
            'DTSTART:20251028T100000Z',
            'DTEND:20251028T110000Z',
            'SUMMARY:Test Event',
            'CLASS:PRIVATE',
            'ORGANIZER;CN=John Doe:mailto:john@example.com',
            'ATTENDEE;PARTSTAT=ACCEPTED;ROLE=CHAIR;CN=John Doe:mailto:john@example.com',
            'ATTENDEE;PARTSTAT=NEEDS-ACTION;ROLE=REQ-PARTICIPANT;CN=Jane Smith:mailto:jane@example.com',
            'LOCATION:Conference Room A',
            'DESCRIPTION:Test event with multiple properties and parameters',
            'DTSTAMP:20251027T120000Z',
            'SEQUENCE:0',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        $vCalendar = \Sabre\VObject\Reader::read($icalData);
        $vevent = $vCalendar->VEVENT;

        // Set a time limit to catch infinite loops (5 seconds should be more than enough)
        set_time_limit(5);

        $startTime = microtime(true);

        try {
            // Use safeCloneVObject instead of clone to avoid infinite recursion
            $clonedVevent = \ESN\Utils\Utils::safeCloneVObject($vevent);

            $endTime = microtime(true);
            $duration = $endTime - $startTime;

            // Cloning should be fast (less than 1 second)
            $this->assertLessThan(1.0, $duration,
                'Cloning took too long (' . $duration . ' seconds), possible infinite loop');

            // Verify the clone is a different object
            $this->assertNotSame($vevent, $clonedVevent);

            // Verify the clone has the same UID
            $this->assertEquals($vevent->UID->getValue(), $clonedVevent->UID->getValue());

            // Verify we can modify the clone without affecting the original
            $clonedVevent->SUMMARY = 'Modified Summary';
            $this->assertEquals('Test Event', $vevent->SUMMARY->getValue());
            $this->assertEquals('Modified Summary', $clonedVevent->SUMMARY->getValue());

        } catch (\Exception $e) {
            $this->fail('safeCloneVObject threw an exception: ' . $e->getMessage());
        }
    }

    /**
     * Test Utils::hidePrivateEventInfoForUser which uses clone
     */
    function testHidePrivateEventInfoUsesCloneSafely() {
        $icalData = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            'UID:private-event',
            'DTSTART:20251028T100000Z',
            'DTEND:20251028T110000Z',
            'SUMMARY:Secret Meeting',
            'CLASS:PRIVATE',
            'ORGANIZER;CN=Boss:mailto:boss@example.com',
            'ATTENDEE;CN=Boss:mailto:boss@example.com',
            'LOCATION:Secret Location',
            'DESCRIPTION:Confidential information',
            'DTSTAMP:20251027T120000Z',
            'SEQUENCE:0',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        $vCalendar = \Sabre\VObject\Reader::read($icalData);

        // Mock a parent node
        $parentNode = $this->getMockBuilder('stdClass')
            ->setMethods(['getOwner'])
            ->getMock();
        $parentNode->method('getOwner')->willReturn('principals/users/boss');

        $userPrincipal = 'principals/users/employee';

        // Set time limit to catch infinite loops
        set_time_limit(5);

        $startTime = microtime(true);

        try {
            // This should not cause infinite recursion
            $result = Utils::hidePrivateEventInfoForUser($vCalendar, $parentNode, $userPrincipal);

            $endTime = microtime(true);
            $duration = $endTime - $startTime;

            // Processing should be fast
            $this->assertLessThan(1.0, $duration,
                'hidePrivateEventInfoForUser took too long (' . $duration . ' seconds), possible infinite loop');

            // Verify the result
            $this->assertNotNull($result);
            $this->assertEquals('Busy', $result->VEVENT->SUMMARY->getValue());

        } catch (\Exception $e) {
            $this->fail('hidePrivateEventInfoForUser threw an exception: ' . $e->getMessage());
        }
    }
}
