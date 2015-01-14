<?php
namespace ESN\CalDAV;

use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;
use \Sabre\HTTP;
use \Sabre\HTTP\RequestInterface;
use \Sabre\HTTP\ResponseInterface;
use \Sabre\VObject\Component\VCalendar;

class CollaborationMembersPlugin extends ServerPlugin {
    function __construct($esnDb, $collectionName) {
        $this->db = $esnDb;
        $this->collectionName = $collectionName;
        $this->collection = $this->db->selectCollection($collectionName);
    }

    function initialize(Server $server) {
        $this->server = $server;
        $this->httpClient = new HTTP\Client();
        $server->on('calendarObjectChange', [$this, 'calendarObjectChange'], 85);
    }

    function calendarObjectChange(RequestInterface $request, ResponseInterface $response, VCalendar $vCal, $calendarPath, &$modified, $isNew) {
        // Only handle VEVENTs
        $vevent = $vCal->getBaseComponent("VEVENT");
        if (!$isNew || !$vevent || $vevent->ORGANIZER || $vevent->ATTENDEE) {
            return;
        }

        // Only handle calendars for this collaboration
        $calendarNode = $this->server->tree->getNodeForPath($calendarPath);
        $owner = $calendarNode->getOwner();
        $parts = explode('/', $owner);
        if ($parts[1] != $this->collectionName || count($parts) != 3) {
            return;
        }

        // Find all members in the collaboration
        $query = [ '_id' => new \MongoId($parts[2]) ];
        $fields = ['members'];
        $res = $this->collection->findOne($query, $fields);
        $members  = [];
        foreach ($res['members'] as $memberObject) {
            $member = $memberObject['member'];
            if ($member['objectType'] == 'user') {
                $members[] = $member['id'];
            }
        }

        // Add the current user to the list of attendees
        $authplugin = $this->server->getPlugin('auth');
        $organizerPrincipal = $authplugin->getCurrentPrincipal();
        $parts = explode('/', $organizerPrincipal);
        $organizerId = new \MongoId($parts[2]);
        $members[] = $organizerId;

        // Retrieve all collaboration members from the database
        $query = [ '_id' => [ '$in' => $members] ];
        $fields = ['firstname', 'lastname', 'emails', '_id'];
        $userData = $this->db->users->find($query, $fields);

        // Add one ATTENDEE property per collaboration member
        foreach ($userData as $user) {
            $params = [
                'CN' => $user['firstname'] . ' ' . $user['lastname'],
                'PARTSTAT' => 'NEEDS-ACTION',
                'ROLE' => 'REQ-PARTICIPANT'
            ];

            // If this member is the organizer, assume he is going and add him
            // as an organizer.
            if ($user['_id'] == $organizerId) {
                $params['PARTSTAT'] = 'ACCEPTED';
                $vevent->ORGANIZER = 'mailto:' . $user['emails'][0];
            }

            // Add this member as an attendee
            $vevent->add('ATTENDEE', 'mailto:' . $user['emails'][0], $params);
        }

        // We've made changes, signal this to the caller
        $modified = true;
    }

    function getPluginName() {
        return "collaboration-members";
    }

    function getPluginInfo() {
        return [
            'name'        => $this->getPluginName(),
            'description' => 'Automatically invite members of a group calendar to events created on calendars.'
        ];
    }
}
