<?php

namespace ESN\CalDAV\Backend;

use ESN\Utils\Utils as Utils;
use MongoDB\Database;
use Sabre\DAVACL\PrincipalBackend\BackendInterface;

/**
 * OpenPaas Esn specific calendering backend
 *
 * Leverage Mongo Backend
 *
 * @see \ESN\CalDAV\Backend\Mongo
 * @package \ESN\CalDAV\Backend
 */
class Esn extends Mongo {

    const EVENTS_URI = 'events';

    private $principalBackend;

    public function __construct(Database $db, BackendInterface $principalBackend, $schedulingObjectTTLInDays = 0)
    {
        parent::__construct($db, $schedulingObjectTTLInDays);

        $this->principalBackend = $principalBackend;
    }

    /**
     * Get the principal backend
     *
     * @return BackendInterface
     */
    public function getPrincipalBackend()
    {
        return $this->principalBackend;
    }

    /**
     *
     * Get user calendars.
     *
     * Ensures a default calendar always exists for the principal.
     * If principal is a resource, calendar name is set to resource name.
     *
     * This method now checks for the existence of a default calendar (not just an empty calendar list)
     * to handle cases where a user has delegated calendars but no personal default calendar yet.
     * See issue #206.
     *
     * @param $principalUri
     * @return array of user calendar
     * @throws \Sabre\DAV\Exception throwed by parent::createCalendar()
     */
    function getCalendarsForUser($principalUri) {
        $calendars = parent::getCalendarsForUser($principalUri);

        // Extract userId from principalUri (e.g., 'principals/users/123' -> '123')
        $principalExploded = explode('/', $principalUri);
        if (count($principalExploded) < 3) {
            // Invalid principalUri format - return calendars as-is
            return $calendars;
        }
        $userId = $principalExploded[2];

        // Check if default calendar exists
        // Check for both EVENTS_URI (new behavior) and userId (legacy behavior)
        $hasDefaultCalendar = false;
        foreach ($calendars as $calendar) {
            if ($calendar['uri'] === self::EVENTS_URI || $calendar['uri'] === $userId) {
                $hasDefaultCalendar = true;
                break;
            }
        }

        if (!$hasDefaultCalendar) {
            // Create default calendar
            $properties = [];
            if (Utils::isResourceFromPrincipal($principalUri)) {
                $principal = $this->principalBackend->getPrincipalByPath($principalUri);
                $properties['{DAV:}displayname'] = $principal['{DAV:}displayname'];
            }

            parent::createCalendar($principalUri, self::EVENTS_URI, $properties);
            $calendars = parent::getCalendarsForUser($principalUri);
        }

        return $calendars;
    }
}
