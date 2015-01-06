<?php
namespace ESN\CalDAV;

use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;
use \Sabre\HTTP;
use \Sabre\HTTP\RequestInterface;
use \Sabre\HTTP\ResponseInterface;
use \Sabre\VObject\Component\VCalendar;

class CommunityMembersPlugin extends ServerPlugin {
    function __construct($esnDb) {
        $this->db = $esnDb;
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

        // Only handle community calendars
        $calendarNode = $this->server->tree->getNodeForPath($calendarPath);
        $owner = $calendarNode->getOwner();
        $parts = explode('/', $owner);
        if ($parts[1] != 'communities' || count($parts) != 3) {
            return;
        }

        // Find all members in the community
        $query = [ '_id' => new \MongoId($parts[2]) ];
        $fields = ['members'];
        $res = $this->db->communities->findOne($query, $fields);
        $members  = [];
        foreach ($res['members'] as $memberObject) {
            $member = $memberObject['member'];
            if ($member['objectType'] == 'user') {
                $members[] = $member['id'];
            }
        }

        // Add the current user to the list of attendees
        $aclplugin = $this->server->getPlugin('acl');
        $organizerPrincipal = $aclplugin->getCurrentUserPrincipal();
        $parts = explode('/', $organizerPrincipal);
        $organizerId = new \MongoId($parts[2]);
        $members[] = $organizerId;

        // Retrieve all community members from the database
        $query = [ '_id' => [ '$in' => $members] ];
        $fields = ['firstname', 'lastname', 'emails', '_id'];
        $userData = $this->db->users->find($query, $fields);

        // Add one ATTENDEE property per community user
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
        return "community-members";
    }

    function getPluginInfo() {
        return [
            'name'        => $this->getPluginName(),
            'description' => 'Automatically invite community members to events created on community calendars.'
        ];
    }
}
