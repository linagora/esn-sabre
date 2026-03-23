<?php
namespace ESN\CalDAV\Schedule;

use Sabre\DAV;

/**
 * Residual IMip plugin — active only when AMQP_SCHEDULING_ENABLED=true.
 *
 * In the standard PUT/POST flow, AMQPSchedulePlugin handles all propagation
 * and notification via AMQP. This plugin plays no role in that path.
 *
 * It exists as a stub to avoid registering the parent Sabre IMipPlugin's
 * 'schedule' listener, which would otherwise do per-recipient MongoDB reads
 * and publish to calendar:event:notificationEmail:send synchronously for
 * every attendee — exactly what the new async architecture replaces.
 *
 * Extend here if COUNTER handling via the HTTP ITIP method is needed.
 */
class MinimalIMipPlugin extends \Sabre\CalDAV\Schedule\IMipPlugin {

    protected $amqpPublisher;
    protected $server;

    function __construct($amqpPublisher) {
        $this->amqpPublisher = $amqpPublisher;
    }

    function initialize(DAV\Server $server) {
        // Do not call parent::initialize() — we do not want to register
        // the Sabre parent's 'schedule' listener.
        $this->server = $server;
    }
}
