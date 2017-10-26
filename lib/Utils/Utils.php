<?php

namespace ESN\Utils;

use \Sabre\VObject;

class Utils {


    static function firstEmailAddress($user) {
        if (array_key_exists('accounts', $user)) {
            foreach ($user['accounts'] as $account) {
                if ($account['type'] === 'email') {
                    return $account['emails'][0];
                }
            }
        }

        return null;
    }

    static function calendarPathFromUri($principal, $calendarUri) {
        $uriExploded = explode('/', $principal);

        return '/calendars/' . $uriExploded[2] . '/' . $calendarUri;
    }

    static function objectPathFromUri($principal, $calendarUri, $objectUri) {
        $uriExploded = explode('/', $principal);

        return '/calendars/' . $uriExploded[2] . '/' . $calendarUri . '/' . $objectUri;
    }

    static function isResourceFromPrincipal($principal) {
        return strpos($principal, 'resources') !== false;
    }

    static function getPrincipalByUri($uri, \Sabre\DAV\Server $server) {
        $aclPlugin = $server->getPlugin('acl');

        if (!$aclPlugin) {
            error_log('No aclPlugin');
            return;
        }

        $principalUri = $aclPlugin->getPrincipalByUri($uri);
        if (!$principalUri) {
            error_log("3.7;Could not find principal for $uri.");

            return;
        }

        return $principalUri;
    }

    static function getEventPathForItip($principalUri, $eventUid, $method, \Sabre\DAV\Server $server) {
        $aclPlugin = $server->getPlugin('acl');

        if (!$aclPlugin) {
            error_log('No aclPlugin');
            return;
        }

        $caldavNS = '{' . \Sabre\CalDAV\Schedule\Plugin::NS_CALDAV . '}';

        // We have a principal URL, now we need to find its inbox.
        // Unfortunately we may not have sufficient privileges to find this, so
        // we are temporarily turning off ACL to let this come through.
        //
        // Once we support PHP 5.5, this should be wrapped in a try..finally
        // block so we can ensure that this privilege gets added again after.
        $server->removeListener('propFind', [$aclPlugin, 'propFind']);
        $result = $server->getProperties(
            $principalUri,
            [
                '{DAV:}principal-URL',
                 $caldavNS . 'calendar-home-set',
                 $caldavNS . 'schedule-inbox-URL',
                 $caldavNS . 'schedule-default-calendar-URL',
                '{http://sabredav.org/ns}email-address',
            ]
        );
        // Re-registering the ACL event
        $server->on('propFind', [$aclPlugin, 'propFind'], 20);
        if (!isset($result[$caldavNS . 'schedule-inbox-URL'])) {
            error_log('5.2;Could not find local inbox');
            return;
        }
        if (!isset($result[$caldavNS . 'calendar-home-set'])) {
            error_log('5.2;Could not locate a calendar-home-set');
            return;
        }
        if (!isset($result[$caldavNS . 'schedule-default-calendar-URL'])) {
            error_log('5.2;Could not find a schedule-default-calendar-URL property');
            return;
        }
        $calendarPath = $result[$caldavNS . 'schedule-default-calendar-URL']->getHref();
        $homePath = $result[$caldavNS . 'calendar-home-set']->getHref();
        $inboxPath = $result[$caldavNS . 'schedule-inbox-URL']->getHref();
        if ($method === 'REPLY') {
            $privilege = 'schedule-deliver-reply';
        } else {
            $privilege = 'schedule-deliver-invite';
        }
        if (!$aclPlugin->checkPrivileges($inboxPath, $caldavNS . $privilege, \Sabre\DAVACL\Plugin::R_PARENT, false)) {
            error_log('3.8;organizer did not have the ' . $privilege . ' privilege on the attendees inbox');
            return;
        }
        // Next, we're going to find out if the item already exits in one of
        // the users' calendars.
        $home = $server->tree->getNodeForPath($homePath);
        $eventPath = $home->getCalendarObjectByUID($eventUid);

        if (!$eventPath) {
            error_log("5.0;Event $eventUid not found in home $homePath.");
            return;
        }

        $uriExploded = explode('/', $eventPath);
        $calendar = $home->getChild($uriExploded[0]);
        $event = $calendar->getChild($uriExploded[1]);

        return [$homePath, $eventPath, $event->get()];
    }

}