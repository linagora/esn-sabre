<?php

namespace ESN\CalDAV\Schedule;

/**
 * @medium
 */
class IMipPluginTest extends \PHPUnit_Framework_TestCase {

    function setUp() {
        $this->ical = join("\r\n", [
            'BEGIN:VCALENDAR',
            'BEGIN:VEVENT',
            'UID:123123',
            'SUMMARY:Hello',
            'END:VEVENT',
            'END:VCALENDAR']);
        $this->config = [
            "fromName" => "test",
            "from" => "test@example.com",
            "hostname" => "localhost",
            "port" => 1234,
            "sslmethod" => "tls",
            "timeout" => 2345,
            "username" => "fred",
            "password" => "george"
        ];
    }


    private function init($sendResult = true) {
        $plugin = new IMipPluginMock($this->config, $sendResult);
        $plugin->initialize(new \Sabre\DAV\Server());

        $this->msg = new \Sabre\VObject\ITip\Message();
        if ($this->ical) {
            $this->msg->message = \Sabre\VObject\Reader::read($this->ical);
        }
        return $plugin;
    }


    function testScheduleNoconfig() {
        $this->config = null;
        $plugin = $this->init();
        $plugin->schedule($this->msg);
        $this->assertEquals($this->msg->scheduleStatus, '5.2');
    }

    function testScheduleNotSignificant() {
        $plugin = $this->init();
        $this->msg->significantChange = false;

        $plugin->schedule($this->msg);
        $this->assertEquals($this->msg->scheduleStatus, '1.0');
    }

    function testNotMailto() {
        $plugin = $this->init();
        $this->msg->sender = 'http://example.com';
        $this->msg->recipient = 'http://example.com';
        $this->msg->scheduleStatus = 'unchanged';

        $plugin->schedule($this->msg);
        $this->assertEquals($this->msg->scheduleStatus, 'unchanged');

        $this->msg->sender = 'mailto:valid';

        $plugin->schedule($this->msg);
        $this->assertEquals($this->msg->scheduleStatus, 'unchanged');
    }

    function testSendSuccess() {
        $plugin = $this->init(true);

        $this->msg->sender = 'mailto:test@example.com';
        $this->msg->recipient = 'mailto:test2@example.com';
        $this->msg->method = "REQUEST";

        $plugin->schedule($this->msg);
        $this->assertEquals($this->msg->scheduleStatus, '1.2');

        $mailer = $plugin->mailer;
        $this->assertEquals($mailer->From, $this->config["from"]);
        $this->assertEquals($mailer->FromName, $this->config["fromName"]);
        $this->assertEquals($mailer->Port, $this->config["port"]);
        $this->assertEquals($mailer->SMTPSecure, $this->config["sslmethod"]);
        $this->assertEquals($mailer->Timeout, $this->config["timeout"]);
        $this->assertEquals($mailer->SMTPAuth, true);
        $this->assertEquals($mailer->Username, $this->config["username"]);
        $this->assertEquals($mailer->Password, $this->config["password"]);
        $this->assertEquals($mailer->Body, $this->ical . "\r\n");
        $this->assertEquals($mailer->Subject, "Hello");
        $this->assertEquals($mailer->ContentType,
            'text/calendar; charset=UTF-8; method=REQUEST');
    }
    function testSubjectREPLY() {
        $plugin = $this->init(true);

        $this->msg->sender = 'mailto:test@example.com';
        $this->msg->recipient = 'mailto:test2@example.com';
        $this->msg->method = "REPLY";

        $plugin->schedule($this->msg);
        $this->assertEquals($plugin->mailer->Subject, "Re: Hello");
    }

    function testSubjectCANCEL() {
        $plugin = $this->init(true);

        $this->msg->sender = 'mailto:test@example.com';
        $this->msg->recipient = 'mailto:test2@example.com';
        $this->msg->method = "CANCEL";

        $plugin->schedule($this->msg);
        $this->assertEquals($plugin->mailer->Subject, "Cancelled: Hello");
    }

    function testSendFailed() {
        $plugin = $this->init(false);

        $this->msg->sender = 'mailto:test@example.com';
        $this->msg->recipient = 'mailto:test2@example.com';
        $this->msg->method = "CANCEL";

        $plugin->schedule($this->msg);
        $this->assertEquals($this->msg->scheduleStatus, '5.1');
    }

    function testPortDetectSSL() {
        unset($this->config['port']);
        $this->config['sslmethod'] = 'ssl';

        $plugin = $this->init(true);

        $this->msg->sender = 'mailto:test@example.com';
        $this->msg->recipient = 'mailto:test2@example.com';
        $this->msg->method = 'REQUEST';

        $plugin->schedule($this->msg);
        $this->assertEquals($plugin->mailer->Port, 465);
    }

    function testPortDetectTLS() {
        unset($this->config['port']);
        $this->config['sslmethod'] = 'tls';

        $plugin = $this->init(true);

        $this->msg->sender = 'mailto:test@example.com';
        $this->msg->recipient = 'mailto:test2@example.com';
        $this->msg->method = 'REQUEST';

        $plugin->schedule($this->msg);
        $this->assertEquals($plugin->mailer->Port, 587);
    }
}


class IMipPluginMock extends IMipPlugin {

    public $mailer;

    function __construct($server, $sendResult) {
        parent::__construct($server);
        $this->sendResult = $sendResult;
    }

    protected function newMailer() {
        $this->mailer = new PHPMailerMock($this->sendResult);
        return $this->mailer;
    }
}

class PHPMailerMock extends \PHPMailer {
    function __construct($sendResult) {
        $this->sendResult = $sendResult;
    }

    function send() {
        return $this->sendResult;
    }
}
