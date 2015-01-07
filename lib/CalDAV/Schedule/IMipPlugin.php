<?php

namespace ESN\CalDAV\Schedule;

use Sabre\DAV;
use Sabre\VObject;
use Sabre\VObject\ITip;

class IMipPlugin extends \Sabre\CalDAV\Schedule\IMipPlugin {

    const SCHEDSTAT_SUCCESS_PENDING = '1.0';
    const SCHEDSTAT_SUCCESS_UNKNOWN = '1.1';
    const SCHEDSTAT_SUCCESS_DELIVERED = '1.2';
    const SCHEDSTAT_FAIL_TEMPORARY = '5.1';
    const SCHEDSTAT_FAIL_PERMANENT = '5.2';

    function __construct($config) {
        $this->config = $config;
    }

    function schedule(ITip\Message $iTipMessage) {
        if (!$this->config) {
            $iTipMessage->scheduleStatus = self::SCHEDSTAT_FAIL_PERMANENT;
            return;
        }

        // Not sending any emails if the system considers the update
        // insignificant.
        if (!$iTipMessage->significantChange) {
            if (!$iTipMessage->scheduleStatus) {
                $iTipMessage->scheduleStatus = self::SCHEDSTAT_SUCCESS_PENDING;
            }
            return;
        }

        $summary = $iTipMessage->message->VEVENT->SUMMARY;

        if (parse_url($iTipMessage->sender, PHP_URL_SCHEME)!=='mailto') {
            return;
        }

        if (parse_url($iTipMessage->recipient, PHP_URL_SCHEME)!=='mailto') {
            return;
        }

        $subject = 'Invitation';
        switch(strtoupper($iTipMessage->method)) {
            case 'REPLY' :
                $subject = 'Re: ' . $summary;
                break;
            case 'REQUEST' :
                $subject = $summary;
                break;
            case 'CANCEL' :
                $subject = 'Cancelled: ' . $summary;
                break;
        }

        $m = $this->initMailer();

        $m->Subject = $subject;
        $m->ContentType = 'text/calendar; charset=UTF-8; method=' . $iTipMessage->method;
        $m->Body = $iTipMessage->message->serialize();

        $sender = substr($iTipMessage->sender, 7);
        $name = $iTipMessage->senderName ? $iTipMessage->senderName : '';
        $m->addReplyTo($sender, $name);

        $recipient = substr($iTipMessage->recipient, 7);
        $name = $iTipMessage->recipientName ? $iTipMessage->recipientName : '';
        $m->addAddress($recipient, $name);

        if ($m->send()) {
            $iTipMessage->scheduleStatus = self::SCHEDSTAT_SUCCESS_DELIVERED;
        } else {
            $iTipMessage->scheduleStatus = self::SCHEDSTAT_FAIL_TEMPORARY;
            error_log($m->ErrorInfo);
        }
    }

    /**
     * This will be mocked by tests and never called
     * @codeCoverageIgnore
     */
    protected function newMailer() {
        return new \PHPMailer($this->server->debugExceptions);
    }

    protected function initMailer() {
        $m = $this->newMailer();
        $c = $this->config;
        $m->isSMTP();

        $m->SMTPDebug = $this->server->debugExceptions ? 3 : 0;
        $m->Debugoutput = 'error_log';


        $m->setFrom($c['from'], $c['fromName']);

        if (DAV\Server::$exposeVersion) {
            $m->CustomHeaders['X-Sabre-Version'] = DAV\Version::VERSION;
        }

        $sslmethod = isset($c['sslmethod']) ? $c['sslmethod'] : null;
        $port = isset($c['port']) ? $c['port'] : null;

        if ($port) {
            $m->Port = $c['port'];
        } else if ($sslmethod == 'ssl') {
            $m->Port = 465;
        } else { // also covers sslmethod = tls
            $m->Port = 587;
        }

        if ($sslmethod) {
            $m->SMTPSecure = $sslmethod;
        }

        $m->Host = $c['hostname'];
        if (isset($c['timeout'])) {
            $m->Timeout = $c['timeout'];
        }

        if (isset($c['username'])) {
            $m->SMTPAuth = true;
            $m->Username = $c['username'];
            $m->Password = $c['password'];
        }

        return $m;
    }

    function initialize(DAV\Server $server) {
        parent::initialize($server);
        $this->server = $server;
    }
}
