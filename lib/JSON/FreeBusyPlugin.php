<?php
namespace ESN\JSON;

use \Sabre\VObject;
use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;
use DateTimeZone;
use ESN\Utils\Utils;

class FreeBusyPlugin extends \ESN\JSON\BasePlugin {

    /**
     * This is the official CalDAV namespace
     */
    const NS_CALDAV = 'urn:ietf:params:xml:ns:caldav';

    function initialize(Server $server) {
        parent::initialize($server);

        $server->on('method:POST', [$this, 'httpPost'], 80);
    }

    /**
     * Returns a plugin name.
     *
     * Using this name other plugins will be able to access other plugins
     * using DAV\Server::getPlugin
     *
     * @return string
     */
    function getPluginName() {
        return 'caldav-freebusy';
    }

    /**
     * Returns a bunch of meta-data about the plugin.
     *
     * Providing this information is optional, and is mainly displayed by the
     * Browser plugin.
     *
     * The description key in the returned array may contain html and will not
     * be sanitized.
     *
     * @return array
     */
    function getPluginInfo() {
        return [
            'name'        => $this->getPluginName(),
            'description' => 'Adds Freebusy support for CalDAV',
            'link'        => 'http://sabre.io/dav/caldav/',
        ];
    }

    function httpPost($request, $response) {
        if (!$this->acceptJson()) {
            return true;
        }

        $path = $request->getPath();
        $code = null;
        $body = null;

        if ($path == 'calendars/freebusy') {
            list($code, $body) = $this->getBulkFreeBusy(
                json_decode($request->getBodyAsString())
            );
        } else {
            return true;
        }

        return $this->send($code, $body);
    }

    function getBulkFreeBusy($params) {
        $start = $params->start;
        $body = (object) [
            'start' => $params->start,
            'end' => $params->end,
            'users' => []
        ];

        foreach ($params->users as $key => $userId) {
            $nodePath = 'calendars/' . $userId;
            $node = $this->server->tree->getNodeForPath($nodePath);
            if (!is_null($node)) {
                $principal = $node->getOwner();
                $email = Utils::getPrincipalEmail($principal, $this->server);
                $calendars = $this->getFreeBusyCalendars($nodePath, $node, $params, $email);
              
                array_push($body->users, (object) [
                    'id' => $userId,
                    'calendars' => $calendars
                ]);
            }
        }

        return [200, $body];
    }

    function getFreeBusyCalendars($nodePath, $node, $params, $email) {
        $calendars = $node->getChildren();

        $items = [];
        foreach ($calendars as $calendar) {
            if ($this->isCalendar($calendar) && $this->hasFreebusyRight($nodePath, $calendar)) {
                $busyEventUris = $calendar->calendarQuery([
                    'name'         => 'VCALENDAR',
                    'comp-filters' => [
                        [
                            'name'           => 'VEVENT',
                            'comp-filters'   => [],
                            'prop-filters'   => [],
                            'is-not-defined' => false,
                            'time-range'     => [
                                'start' => new \DateTime($params->start),
                                'end'   => new \DateTime($params->end),
                            ],
                        ],
                    ],
                    'prop-filters'   => [],
                    'is-not-defined' => false,
                    'time-range'     => null,
                ]);

                $busyEvents = array_map(function($eventUri) use ($calendar, $email) {
                    $obj = $calendar->getChild($eventUri)->get();
                    $vObject = VObject\Reader::read($obj);
                    $vevent = $vObject->VEVENT;

                    if ($vevent->ATTENDEE && Utils::isPrincipalNotAttendingEvent($vevent, $email)) {
                        return;
                    }

                    $timeZone = new DateTimeZone('UTC');

                    $freebusy = [
                        'uid' => $vevent->UID->getValue(),
                        'start' => $vevent->DTSTART->getDateTime()->format('Ymd\\THis\\Z')
                    ];

                    if (isset($vevent->DTEND)) {
                        $endDate = $vevent->DTEND->getDateTime();
                    } elseif (isset($vevent->DURATION)) {
                        $endDate = clone $vevent->DTSTART->getDateTime();
                        $endDate = $endDate->add(VObject\DateTimeParser::parse($vevent->DURATION->getValue()));
                    }

                    if (isset($endDate)) {
                        $freebusy['end'] = $endDate->format('Ymd\\THis\\Z');
                    }

                    return (object) $freebusy;
                }, $busyEventUris);

                $busyEvents = array_filter($busyEvents);
                $filteredBusyEvent = isset($params->uids)
                    ? array_values(array_filter($busyEvents, function ($busy) use ($params) {
                        return !in_array($busy->uid, $params->uids);
                    }))
                    : $busyEvents;

                $items[] = (object) [
                    'id' => $calendar->getName(),
                    'busy' =>$filteredBusyEvent
                ];
            }
        }

        return $items;
    }

    function isCalendar($calendar) {
        return ($calendar instanceof \ESN\CalDAV\SharedCalendar) && !$calendar->isSharedInstance();
    }

    function hasFreebusyRight($nodePath, $calendar) {
        $right = '{' . Plugin::NS_CALDAV . '}read-free-busy';

        return $this->server->getPlugin('acl')->checkPrivileges($nodePath . '/' . $calendar->getName(), $right, \Sabre\DAVACL\Plugin::R_PARENT, false);
    }
}
