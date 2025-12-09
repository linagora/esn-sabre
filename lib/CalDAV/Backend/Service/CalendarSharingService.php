<?php

namespace ESN\CalDAV\Backend\Service;

use ESN\CalDAV\Backend\DAO\CalendarInstanceDAO;
use Sabre\Event\EventEmitter;

/**
 * Calendar Sharing Service
 *
 * Handles all calendar sharing operations including:
 * - Managing sharees (invites)
 * - Public rights management
 * - Invite status updates
 */
class CalendarSharingService {
    const INVITES_PROJECTION = [
        'principaluri' => 1,
        'access' => 1,
        'share_href' => 1,
        'share_invitestatus' => 1,
        'share_displayname' => 1
    ];

    private $calendarInstanceDAO;
    private $eventEmitter;

    public function __construct(CalendarInstanceDAO $calendarInstanceDAO, EventEmitter $eventEmitter) {
        $this->calendarInstanceDAO = $calendarInstanceDAO;
        $this->eventEmitter = $eventEmitter;
    }

    /**
     * Transform MongoDB row to Sharee object
     *
     * @param array $row MongoDB document
     * @return \Sabre\DAV\Xml\Element\Sharee|null Sharee object or null if invalid
     */
    private function asSharee($row) {
        // Skip invalid invites
        if ($row['share_invitestatus'] === \Sabre\DAV\Sharing\Plugin::INVITE_INVALID) {
            return null;
        }

        return new \Sabre\DAV\Xml\Element\Sharee([
            'href' => isset($row['share_href'])
                ? $row['share_href']
                : \Sabre\HTTP\encodePath($row['principaluri']),
            'access' => (int) $row['access'],
            'inviteStatus' => (int) $row['share_invitestatus'],
            'properties' => !empty($row['share_displayname'])
                ? [ '{DAV:}displayname' => $row['share_displayname'] ]
                : [],
            'principal' => $row['principaluri']
        ]);
    }

    /**
     * Update calendar invites/sharees
     *
     * @param array $calendarId [calendarId, instanceId]
     * @param array $sharees Array of Sharee objects
     * @return array Calendar instances affected by changes
     */
    public function updateInvites($calendarId, array $sharees) {
        list($calendarId, $instanceId) = $calendarId;

        $currentInvites = $this->getInvites([$calendarId, $instanceId]);
        $existingInstance = $this->calendarInstanceDAO->findInstanceById($instanceId, ['_id' => 0]);

        $calendarInstances = [];

        foreach($sharees as $sharee) {
            $result = $this->processSharee($calendarId, $sharee, $currentInvites, $existingInstance);
            if ($result) {
                $calendarInstances[] = $result;
            }
        }

        $this->eventEmitter->emit('esn:updateSharees', [$calendarInstances]);

        return $calendarInstances;
    }

    /**
     * Process a single sharee (create, update, or delete)
     *
     * @param string $calendarId
     * @param object $sharee
     * @param array $currentInvites
     * @param array $existingInstance
     * @return array|null Calendar instance data or null
     */
    private function processSharee($calendarId, $sharee, $currentInvites, $existingInstance) {
        if ($sharee->access === \Sabre\DAV\Sharing\Plugin::ACCESS_NOACCESS) {
            return $this->removeSharee($calendarId, $sharee);
        }

        $this->setShareeInviteStatus($sharee);

        // Check if sharee already exists (update case)
        foreach($currentInvites as $oldSharee) {
            if ($oldSharee->href === $sharee->href) {
                return $this->updateSharee($calendarId, $sharee, $oldSharee);
            }
        }

        // New sharee (create case)
        return $this->createSharee($calendarId, $sharee, $existingInstance);
    }

    /**
     * Remove a sharee from calendar
     *
     * @param string $calendarId
     * @param object $sharee
     * @return array|null Calendar instance data or null
     */
    private function removeSharee($calendarId, $sharee) {
        $uri = $this->calendarInstanceDAO->findInstanceByCalendarIdAndShareHref($calendarId, $sharee->href);
        $this->calendarInstanceDAO->deleteInstanceByCalendarIdAndShareHref($calendarId, $sharee->href);

        if ($uri) {
            return [
                'uri' => $uri['uri'],
                'type' => 'delete',
                'sharee' => $sharee
            ];
        }

        return null;
    }

    /**
     * Update existing sharee access and properties
     *
     * @param string $calendarId
     * @param object $sharee
     * @param object $oldSharee
     * @return array Calendar instance data
     */
    private function updateSharee($calendarId, $sharee, $oldSharee) {
        $sharee->properties = array_merge($oldSharee->properties, $sharee->properties);

        $updateData = [
            'access' => $sharee->access,
            'share_displayname' => isset($sharee->properties['{DAV:}displayname'])
                ? $sharee->properties['{DAV:}displayname']
                : null,
            'share_invitestatus' => $sharee->inviteStatus ?: $oldSharee->inviteStatus
        ];

        $this->calendarInstanceDAO->updateShareeAccess($calendarId, $sharee->href, $updateData);

        $uri = $this->calendarInstanceDAO->findInstanceByCalendarIdAndShareHref($calendarId, $sharee->href);

        return [
            'uri' => $uri['uri'],
            'type' => 'update',
            'sharee' => $sharee
        ];
    }

    /**
     * Create new sharee instance
     *
     * @param string $calendarId
     * @param object $sharee
     * @param array $existingInstance Base instance to clone from
     * @return array Calendar instance data
     */
    private function createSharee($calendarId, $sharee, $existingInstance) {
        $newInstance = $existingInstance;
        unset($newInstance['_id']); // Ensure MongoDB generates a new _id

        $newInstance['calendarid'] = new \MongoDB\BSON\ObjectId($calendarId);
        $newInstance['principaluri'] = $sharee->principal;
        $newInstance['access'] = $sharee->access;
        $newInstance['uri'] = \Sabre\DAV\UUIDUtil::getUUID();
        $newInstance['share_href'] = $sharee->href;
        $newInstance['share_displayname'] = isset($sharee->properties['{DAV:}displayname'])
            ? $sharee->properties['{DAV:}displayname']
            : null;
        $newInstance['share_invitestatus'] = $sharee->inviteStatus ?: \Sabre\DAV\Sharing\Plugin::INVITE_NORESPONSE;

        $this->calendarInstanceDAO->createInstance($newInstance);

        return [
            'uri' => $newInstance['uri'],
            'type' => 'create',
            'sharee' => $sharee
        ];
    }

    /**
     * Set invite status for sharee based on principal validity
     *
     * @param object $sharee
     */
    private function setShareeInviteStatus($sharee) {
        if (is_null($sharee->principal)) {
            $sharee->inviteStatus = \Sabre\DAV\Sharing\Plugin::INVITE_INVALID;
        } else {
            $sharee->inviteStatus = \Sabre\DAV\Sharing\Plugin::INVITE_ACCEPTED;
        }
    }

    /**
     * Get all invites for a calendar
     *
     * @param array $calendarId [calendarId, instanceId]
     * @return array Array of Sharee objects
     */
    public function getInvites($calendarId) {
        $calendarId = $calendarId[0];

        $res = $this->calendarInstanceDAO->findInvitesByCalendarId($calendarId, self::INVITES_PROJECTION);

        $result = [];
        foreach ($res as $row) {
            $sharee = $this->asSharee($row);
            if ($sharee !== null) {
                $result[] = $sharee;
            }
        }

        return $result;
    }

    /**
     * Save calendar public right privilege
     *
     * @param array $calendarId [calendarId, instanceId]
     * @param string $privilege Privilege level
     * @param array $calendarInfo Calendar metadata
     * @param callable $deleteSubscribersCallback Callback to delete subscribers
     * @param callable $getCalendarPathCallback Callback to get calendar path
     */
    public function saveCalendarPublicRight($calendarId, $privilege, $calendarInfo, $deleteSubscribersCallback, $getCalendarPathCallback) {
        $calendarId = $this->prepareRequestForCalendarPublicRight($calendarId);

        $this->calendarInstanceDAO->updatePublicRight($calendarId, $privilege);

        $calendarPath = $getCalendarPathCallback($calendarInfo['principaluri'], $calendarInfo['uri']);

        if (!in_array($privilege, ['{DAV:}read', '{DAV:}write'])) {
            $this->eventEmitter->emit('esn:updatePublicRight', [$calendarPath, false]);
            $deleteSubscribersCallback($calendarInfo['principaluri'], $calendarInfo['uri']);
        } else {
            $this->eventEmitter->emit('esn:updatePublicRight', [$calendarPath]);
        }
    }

    /**
     * Get calendar public right privilege
     *
     * @param array $calendarId [calendarId, instanceId]
     * @return string|null Public right privilege or null
     */
    public function getCalendarPublicRight($calendarId) {
        $calendarId = $this->prepareRequestForCalendarPublicRight($calendarId);

        $mongoRes = $this->calendarInstanceDAO->getPublicRight($calendarId);

        return isset($mongoRes['public_right']) ? $mongoRes['public_right'] : null;
    }

    /**
     * Save calendar invite status
     *
     * @param array $calendarId [calendarId, instanceId]
     * @param int $status Invite status
     */
    public function saveCalendarInviteStatus($calendarId, $status) {
        $instanceId = $calendarId[1];

        $this->calendarInstanceDAO->updateInviteStatus($instanceId, $status);
    }

    /**
     * Prepare calendar ID for public right operations (extract calendarId from array)
     *
     * @param array $calendarId [calendarId, instanceId]
     * @return string Calendar ID
     */
    public function prepareRequestForCalendarPublicRight($calendarId) {
        return $calendarId[0];
    }
}
