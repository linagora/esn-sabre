<?php

namespace ESN\Utils;

#[\AllowDynamicProperties]
class Utils {

    static function firstEmailAddress($user) {
        if (is_array($user) && array_key_exists('accounts', $user)) {
            $accounts = $user['accounts'];
        } elseif (is_object($user) && isset($user->accounts)) {
            $accounts = $user->accounts;
        } else {
            return null;
        }

        foreach ($accounts as $account) {
            if (self::isEmailAccount($account)) {
                return is_array($account) ? $account['emails'][0] : $account->emails[0];
            }
        }

        return null;
    }

    private static function isEmailAccount($account) {
        if (is_array($account)) {
            return $account['type'] === 'email';
        }

        if (is_object($account)) {
            return $account->type === 'email';
        }

        return false;
    }

    static function calendarPathFromUri($principal, $calendarUri) {
        $uriExploded = explode('/', $principal);

        return '/calendars/' . $uriExploded[2] . '/' . $calendarUri;
    }

    static function objectPathFromUri($principal, $calendarUri, $objectUri) {
        $uriExploded = explode('/', $principal);

        return '/calendars/' . $uriExploded[2] . '/' . $calendarUri . '/' . $objectUri;
    }

    static function getCalendarHomePathFromEventPath($eventPath) {
        list($namespace, $homeId, $calendarUri, $objectUri) = explode('/', $eventPath);

        return $namespace . '/' . $homeId;
    }

    static function isResourceFromPrincipal($principal) {
        if ($principal === null) {
            return false;
        }
        return strpos($principal, 'resources') !== false;
    }

    static function isTeamCalendarFromPrincipal($principal) {
        if ($principal === null) {
            return false;
        }
        return strpos($principal, 'team-calendars') !== false;
    }

    static function isUserPrincipal($principal) {
        if ($principal === null) {
            return false;
        }
        return strpos($principal, '/users/') !== false;
    }

    static function getPrincipalByUri($uri, \Sabre\DAV\Server $server) {
        // Issue #242: Handle null or empty URI to prevent TypeError in substr()
        if ($uri === null || $uri === '') {
            error_log('3.7;getPrincipalByUri called with null or empty URI.');
            return;
        }

        $aclPlugin = $server->getPlugin('acl');

        if (!$aclPlugin) {
            error_log('No aclPlugin');
            return;
        }

        $principalUri = $aclPlugin->getPrincipalByUri(self::normalizeCalendarLookupUri($uri));
        if (!$principalUri) {
            error_log("3.7;Could not find principal for $uri.");

            return;
        }

        return $principalUri;
    }

    private static function normalizeCalendarLookupUri($uri) {
        if (!is_string($uri)) {
            return $uri;
        }

        if (stripos($uri, 'mailto:') === 0) {
            return 'mailto:' . substr($uri, 7);
        }

        return $uri;
    }

    static function getEventObjectFromAnotherPrincipalHome($principalUri, $eventUid, $method, \Sabre\DAV\Server $server) {
        if ($principalUri === null) {
            return;
        }

        $aclPlugin = $server->getPlugin('acl');

        if (!$aclPlugin) {
            error_log('No aclPlugin');
            return;
        }

        $caldavNS = '{' . \Sabre\CalDAV\Schedule\Plugin::NS_CALDAV . '}';

        // We have a principal URL, now we need to find its inbox.
        // Unfortunately we may not have sufficient privileges to find this, so
        // we are temporarily turning off ACL to let this come through.
        $server->removeListener('propFind', [$aclPlugin, 'propFind']);
        try {
            $result = $server->getProperties(
                $principalUri,
                [
                    '{DAV:}principal-URL',
                    $caldavNS . 'calendar-home-set',
                    '{http://sabredav.org/ns}email-address',
                ]
            );
        } finally {
            // Re-registering the ACL event
            $server->on('propFind', [$aclPlugin, 'propFind'], 20);
        }
        if (!isset($result[$caldavNS . 'calendar-home-set'])) {
            error_log('5.2;Could not locate a calendar-home-set');
            return;
        }
        $homePath = $result[$caldavNS . 'calendar-home-set']->getHref();
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
        // Clone the entire VCalendar once to avoid multiple serializations
        $clonedCalendar = self::safeCloneVObject($vCalendar);

        foreach ($clonedCalendar->VEVENT as $vevent) {
            if (self::isHiddenPrivateEvent($vevent, $parentNode, $userPrincipal)) {
                self::hidePrivateEventInfo($vevent);
            }
        }

        return $clonedCalendar;
    }

    private static function hidePrivateEventInfo($vevent) {
        $properties = self::getPrivateEventPropertiesToPreserve($vevent);
        self::removeEventProperties($vevent);
        self::addPrivateEventProperties($vevent, $properties);
    }

    private static function getPrivateEventPropertiesToPreserve($vevent) {
        // Preserve the original read and clone order because VObject properties
        // keep references to their parent component.
        $uid = isset($vevent->UID) ? $vevent->UID->getValue() : null;
        $organizer = isset($vevent->ORGANIZER) ? $vevent->ORGANIZER->getValue() : null;
        $dtstart = isset($vevent->DTSTART) ? clone $vevent->DTSTART : null;
        $dtend = isset($vevent->DTEND) ? clone $vevent->DTEND : null;
        $duration = isset($vevent->DURATION) ? clone $vevent->DURATION : null;
        $rrule = isset($vevent->RRULE) ? clone $vevent->RRULE : null;
        $rdate = isset($vevent->RDATE) ? clone $vevent->RDATE : null;
        $exdate = isset($vevent->EXDATE) ? clone $vevent->EXDATE : null;
        $recurrenceId = isset($vevent->{'RECURRENCE-ID'}) ? clone $vevent->{'RECURRENCE-ID'} : null;

        return [
            'uid' => $uid,
            'organizer' => $organizer,
            'dtstart' => $dtstart,
            'dtend' => $dtend,
            'duration' => $duration,
            'rrule' => $rrule,
            'rdate' => $rdate,
            'exdate' => $exdate,
            'recurrenceId' => $recurrenceId
        ];
    }

    private static function removeEventProperties($vevent) {
        $propertiesToRemove = [];
        foreach ($vevent->children() as $child) {
            if ($child instanceof \Sabre\VObject\Property) {
                $propertiesToRemove[] = $child->name;
            }
        }

        foreach ($propertiesToRemove as $propName) {
            unset($vevent->{$propName});
        }
    }

    private static function addPrivateEventProperties($vevent, $properties) {
        if ($properties['uid']) {
            $vevent->UID = $properties['uid'];
        }
        $vevent->SUMMARY = 'Busy';
        $vevent->CLASS = 'PRIVATE';
        if ($properties['organizer']) {
            $vevent->ORGANIZER = $properties['organizer'];
        }

        foreach (['dtstart', 'dtend', 'duration', 'rrule', 'rdate', 'exdate', 'recurrenceId'] as $propertyName) {
            if ($properties[$propertyName]) {
                $vevent->add($properties[$propertyName]);
            }
        }
    }

    static function isHiddenPrivateEvent($vevent, $node, $userPrincipal) {
        if ($vevent->CLASS != 'PRIVATE' && $vevent->CLASS != 'CONFIDENTIAL') {
            return false;
        }

        // For subscriptions, check against the source calendar owner
        if (method_exists($node, 'getSourceOwner')) {
            return $node->getSourceOwner() !== $userPrincipal;
        }

        if (self::isTeamCalendarSharedInstance($node, $userPrincipal)) {
            return false;
        }

        return $node->getOwner() !== $userPrincipal;
    }

    private static function isTeamCalendarSharedInstance($node, $userPrincipal) {
        if ($userPrincipal === null) {
            return false;
        }
        if (!method_exists($node, 'getOwner')) {
            return false;
        }
        if (!method_exists($node, 'isSharedInstance')) {
            return false;
        }

        return self::isTeamCalendarFromPrincipal($node->getOwner()) && $node->isSharedInstance();
    }

    /**
     * Generates a list of DAV items in a JSON format with status for each individual item based on a list of file properties.
     *
     * If 'strip404s' is set to true, all 404 items will be removed.
     *
     * @param array $responseDetails
     * <p>$responseDetails['fileProperties'] array An array of the file properties to analyze, could contain VEVENTs or VCARDs.</p>
     * <p>$responseDetails['dataKey'] string The data key used to get the data of a VEVENT or VCARD in a file property.</p>
     * <p>$responseDetails['baseUri'] string The base URI of the Sabre server.</p>
     * <p>$responseDetails['strip404s'] boolean Should strip 404s out of the results or not.</p>
     * @return array The array of JSON items with status of each item
     */
    static function generateJSONMultiStatus(array $responseDetails = []) {
        $params = array_replace([
            'fileProperties' => [],
            'dataKey' => '',
            'baseUri' => '',
            'strip404s' => false
        ], $responseDetails);

        $items = [];

        foreach ($params['fileProperties'] as $entry) {
            if (count((array)$entry[404])) {
                if (!$params['strip404s']) {
                    $items[] = [
                        '_links' => [
                            'self' => ['href' => $params['baseUri'] . $entry['href']]
                        ],
                        'status' => 404
                    ];
                }

                continue;
            }

            $items[] = [
                '_links' => [
                    'self' => [ 'href' => $params['baseUri'] . $entry['href'] ]
                ],
                'etag' => $entry[200]['{DAV:}getetag'],
                'data' => $entry[200][$params['dataKey']],
                'status' => 200
            ];
        }

        return $items;
    }

    /**
     * Split an event path into a calendar node path and an event's URI.
     *
     * @param $eventPath string The event path, must be in the following format:
     *                          /calendars/calendarHomeId/calendarId/eventUid.ics'
     * @return array An array where the first element is the calendar node path, and the second one is the event's URI.
     *               If validation fails, an array with two 'null' elements will be returned instead.
     */
    static function splitEventPath($eventPath) {
        if (!preg_match('~^/calendars/[\w-]+/[\w-]+/[\w.-]+\.ics$~', $eventPath)) {
            return [null, null];
        }

        $lastSlashPos = strrpos($eventPath, '/');

        return [substr($eventPath, 1, $lastSlashPos - 1), substr($eventPath, $lastSlashPos + 1)];
    }

    static function getEventUriFromPath($eventPath) {
        list(,,, $eventURI) = explode('/', $eventPath);

        return $eventURI;
    }

    /**
     * @param VEVENT $vevent    the event object VEVENT.
     * @param String $email     the user email to be used in the participation check.
     *
     * @return Boolean          true if he is not attending the event, false otherwise.
     */
    static function isPrincipalNotAttendingEvent($vevent, $email) {
        if (empty($email)) {
            return true;
        }

        $emailLower = strtolower($email);
        foreach ($vevent->ATTENDEE as $attendee) {
            if (strtolower($attendee->getValue()) !== $emailLower) {
                continue;
            }

            // Return on the first matching attendee to avoid scanning events
            // with hundreds of attendees, as the original implementation did.
            return self::isAttendeeNotParticipating($attendee);
        }

        return true;
    }

    private static function isAttendeeNotParticipating($attendee) {
        if (!isset($attendee['PARTSTAT'])) {
            return true;
        }

        $partstat = strtoupper(trim($attendee['PARTSTAT']->getValue()));

        return ($partstat === 'NEEDS-ACTION' || $partstat === 'DECLINED');
    }

    /**
     * @param String $principal    the pricipal uri for the desired user.
     * @param Object $server       the sabre \Sabre\DAV\Server instance.
     *
     * @return String              the email adress of the principal
     */
    static function getPrincipalEmail($principal, $server) {
        $CUAS = '{urn:ietf:params:xml:ns:caldav}calendar-user-address-set';

        $properties = $server->getProperties(
            $principal,
            [$CUAS]
        );

        if (!isset($properties[$CUAS])) return;

        $addresses = $properties[$CUAS]->getHrefs();

        return $addresses[0];
    }

    /**
     * Safely clone a VObject component by serializing and deserializing
     *
     * This avoids infinite recursion issues that can occur with the clone operator
     * when VObject properties have circular references through their parent pointers.
     *
     * @param \Sabre\VObject\Component $component The component to clone (VCalendar or VEVENT)
     * @return \Sabre\VObject\Component The cloned component
     */
    static function safeCloneVObject($component) {
        // If it's a VCalendar, clone manually to preserve property order and avoid infinite loops
        if ($component instanceof \Sabre\VObject\Component\VCalendar) {
            return self::cloneVCalendar($component);
        }

        // For other components (VEVENT, etc.), serialize the parent VCalendar
        $parent = $component->parent;
        if ($parent instanceof \Sabre\VObject\Component\VCalendar) {
            $clonedComponent = self::cloneComponentFromParentCalendar($component, $parent);
            if ($clonedComponent !== null) {
                return $clonedComponent;
            }
        }

        // Last resort: try using PHP's clone with error suppression
        // This shouldn't happen in practice
        return @clone $component;
    }

    private static function cloneVCalendar($component) {
        // Create empty calendar without default properties
        $clone = new \Sabre\VObject\Component\VCalendar([], false);

        // Copy all children in original order
        foreach ($component->children() as $child) {
            // Use @ to suppress potential warnings from clone
            $clonedChild = @clone $child;
            $clone->add($clonedChild);
        }

        return $clone;
    }

    private static function cloneComponentFromParentCalendar($component, $parent) {
        // Parse the entire parent calendar
        $clonedCalendar = \Sabre\VObject\Reader::read($parent->serialize());
        $componentName = $component->name;
        $clonedComponent = self::findMatchingClonedComponent($component, $clonedCalendar, $componentName);

        if ($clonedComponent !== null) {
            return $clonedComponent;
        }

        // Fallback: return first component of same type
        if (isset($clonedCalendar->{$componentName})) {
            return $clonedCalendar->{$componentName};
        }

        return null;
    }

    private static function findMatchingClonedComponent($component, $clonedCalendar, $componentName) {
        if (!isset($component->UID)) {
            return null;
        }

        $uid = $component->UID->getValue();
        $recurrenceId = isset($component->{'RECURRENCE-ID'})
            ? $component->{'RECURRENCE-ID'}->getValue()
            : null;

        foreach ($clonedCalendar->{$componentName} as $child) {
            if (self::isMatchingClonedComponent($child, $uid, $recurrenceId)) {
                return $child;
            }
        }

        return null;
    }

    private static function isMatchingClonedComponent($child, $uid, $recurrenceId) {
        if (!isset($child->UID) || $child->UID->getValue() !== $uid) {
            return false;
        }

        // Recurring instances must match RECURRENCE-ID; master and
        // non-recurring events must match a component without one.
        if ($recurrenceId !== null) {
            return isset($child->{'RECURRENCE-ID'})
                && $child->{'RECURRENCE-ID'}->getValue() === $recurrenceId;
        }

        return !isset($child->{'RECURRENCE-ID'});
    }

  static function getDatabaseName($dbKind, $connectionString, $dbConfig) {
    $parsedUrl = parse_url($connectionString);
    $pathSegments = explode("/", $parsedUrl['path']);
    $lastSegment = array_pop($pathSegments);
    if ($lastSegment) {
        return $lastSegment;
    } else {
        // support old style database name
        return $dbConfig[$dbKind]['db'];
    }
  }

}
