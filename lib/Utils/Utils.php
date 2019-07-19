<?php

namespace ESN\Utils;

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

    static function isUserPrincipal($principal) {
        return strpos($principal, '/users/') !== false;
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

    static function getEventObjectFromAnotherPrincipalHome($principalUri, $eventUid, $method, \Sabre\DAV\Server $server) {
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

        list($calendarUri, $eventUri) = explode('/', $eventPath);
        $calendar = $home->getChild($calendarUri);
        $event = $calendar->getChild($eventUri);

        $eventFullPath = $homePath . $eventPath;

        return [$eventFullPath, $event->get()];
    }

    static function formatIcal($data, $modified) {
        if (is_resource($data)) {
            $data = stream_get_contents($data);
        }

        try {
            // If the data starts with a [, we can reasonably assume we're dealing
            // with a jCal object.
            if (substr($data, 0, 1) === '[') {
                $data = \Sabre\VObject\Reader::readJson($data);

                $modified = true;
            } else {
                $data = \Sabre\VObject\Reader::read($data);
            }
        } catch (\Sabre\VObject\ParseException $e) {
            throw new \Sabre\DAV\Exception\UnsupportedMediaType('This resource only supports valid iCalendar 2.0 data. Parse error: ' . $e->getMessage());
        }

        return [$data, $modified];
    }

    static function getPrincipalIdFromPrincipalUri($principalUri) {
        $parts = explode('/', trim($principalUri, '/'));

        if (count($parts) !== 3) return;
        if ($parts[0] !== 'principals') return;

        if (!in_array($parts[1], ['users', 'domains'])) return;

        return $parts[2];
    }

    static function getArrayValue($array, $key, $default = null){
        return isset($array[$key]) ? $array[$key] : $default;
    }

    static function getJsonValue($jsonData, $key, $default = null) {
        return isset($jsonData->{$key}) ? $jsonData->{$key} : $default;
    }

    static function hidePrivateEventInfoForUser($vCalendar, $parentNode, $userPrincipal) {
        $newEvents = array();
        foreach ($vCalendar->VEVENT as $vevent) {
            if (self::isHiddenPrivateEvent($vevent, $parentNode, $userPrincipal)) {
                $newVevent = clone ($vevent);

                $children = $newVevent->children();
                foreach ($children as $child) {
                    $newVevent->remove($child->name);
                }
                $newVevent->UID = $vevent->UID;
                $newVevent->SUMMARY = 'Busy';
                $newVevent->CLASS = 'PRIVATE';
                $newVevent->ORGANIZER = $vevent->ORGANIZER;
                $newVevent->DTSTART = $vevent->DTSTART;

                if (!!$vevent->DTEND) {
                    $newVevent->DTEND = $vevent->DTEND;
                }

                if (!!$vevent->DURATION) {
                    $newVevent->DURATION = $vevent->DURATION;
                }

                $vevent = $newVevent;
            }
            $newEvents[] = $vevent;
        }
        $vCalendar->remove('VEVENT');
        foreach ($newEvents as $vevent) {
            $vCalendar->add($vevent);
        }
        return $vCalendar;
    }

    static function isHiddenPrivateEvent($vevent, $node, $userPrincipal) {
        return $vevent->CLASS == 'PRIVATE' && ($node->getOwner() !== $userPrincipal);
    }
}
