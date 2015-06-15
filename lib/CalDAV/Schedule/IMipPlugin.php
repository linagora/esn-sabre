<?php

namespace ESN\CalDAV\Schedule;

use \Sabre\DAV;
use \Sabre\VObject;
use \Sabre\VObject\ITip;
use \Sabre\HTTP;
use \Sabre\HTTP\RequestInterface;
use \Sabre\HTTP\ResponseInterface;

class IMipPlugin extends \Sabre\CalDAV\Schedule\IMipPlugin {
    protected $server;
    protected $httpClient;
    protected $apiroot;

    const SCHEDSTAT_SUCCESS_PENDING = '1.0';
    const SCHEDSTAT_SUCCESS_UNKNOWN = '1.1';
    const SCHEDSTAT_SUCCESS_DELIVERED = '1.2';
    const SCHEDSTAT_FAIL_TEMPORARY = '5.1';
    const SCHEDSTAT_FAIL_PERMANENT = '5.2';

    function __construct($apiroot, $authBackend) {
        $this->apiroot = $apiroot;
        $this->authBackend = $authBackend;
    }

    function schedule(ITip\Message $iTipMessage) {
        if (!$this->apiroot) {
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

        if (parse_url($iTipMessage->sender, PHP_URL_SCHEME)!=='mailto') {
            return;
        }

        if (parse_url($iTipMessage->recipient, PHP_URL_SCHEME)!=='mailto') {
            return;
        }

        $body = json_encode([
          'emails' => [ substr($iTipMessage->recipient, 7) ],
          'method' => $iTipMessage->method,
          'event' => $iTipMessage->message->serialize(),
          'notify' => true
        ]);

        $url = $this->apiroot . '/calendars/inviteattendees';
        $request = new HTTP\Request('POST', $url);
        $request->setHeader('Content-type', 'application/json');
        $request->setHeader('Content-length', strlen($body));
        $cookie = $this->authBackend->getAuthCookies();
        $request->setHeader('Cookie', $cookie);
        $request->setBody($body);

        $response = $this->httpClient->send($request);
        $status = $response->getStatus();

        if (floor($status / 100) == 2) {
            $iTipMessage->scheduleStatus = self::SCHEDSTAT_SUCCESS_DELIVERED;
        } else {
            $iTipMessage->scheduleStatus = self::SCHEDSTAT_FAIL_TEMPORARY;
            error_log("iTip Delivery failed for " . $iTipMessage->recipient .
                      ": " . $status . " " . $response->getStatusText());
        }
    }

    function initialize(DAV\Server $server) {
        parent::initialize($server);
        $this->server = $server;
        $this->httpClient = new HTTP\Client();
    }
}
