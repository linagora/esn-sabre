<?php

namespace ESN\CalDAV\Schedule;

require_once ESN_TEST_BASE. '/CalDAV/Schedule/IMipPluginTestBase.php';

class IMipPluginCommonTest extends IMipPluginTestBase {

    private $iTipMessage;
    private $iTipMessageIcal;
    
    public function setUp()
    {
        parent::setUp();

        $this->iTipMessageIcal = join("\r\n", [
            'BEGIN:VCALENDAR',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:daab17fe-fac4-4946-9105-0f2cdb30f5ab',
            'SUMMARY:Hello',
            'DTSTART:'. $this->afterCurrentDate.'T030000Z',
            'DTEND:' . $this->afterCurrentDate.'T040000Z',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        $this->iTipMessage = new \Sabre\VObject\ITip\Message();
        $this->iTipMessage->message = \Sabre\VObject\Reader::read($this->iTipMessageIcal);
    }

    function testScheduleNotSignificant() {
        $plugin = $this->getPlugin();
        $this->iTipMessage->sender = 'mailto:test@example.com';
        $this->iTipMessage->recipient = 'mailto:johndoe@example.org';
        $this->iTipMessage->significantChange = false;
        $this->iTipMessage->hasChange = false;

        $plugin->schedule($this->iTipMessage);
        $this->assertEquals($this->iTipMessage->scheduleStatus, '1.0');
    }

    function testNotMailto() {
        $plugin = $this->getPlugin();
        $this->iTipMessage->sender = 'http://example.com';
        $this->iTipMessage->recipient = 'http://example.com';
        $this->iTipMessage->scheduleStatus = 'unchanged';

        $plugin->schedule($this->iTipMessage);
        $this->assertEquals($this->iTipMessage->scheduleStatus, 'unchanged');

        $this->iTipMessage->sender = 'mailto:valid';

        $plugin->schedule($this->iTipMessage);
        $this->assertEquals($this->iTipMessage->scheduleStatus, 'unchanged');
    }
}
