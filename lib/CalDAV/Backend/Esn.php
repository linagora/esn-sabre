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
     * If Principal doesn't have any calendar, a default one is created.
     * If principal is a resource, calendar name is set to resource name.
     *
     * @param $principalUri
     * @return array of user calendar
     * @throws \Sabre\DAV\Exception throwed by parent::createCalendar()
     */
    function getCalendarsForUser($principalUri) {
        $calendars = parent::getCalendarsForUser($principalUri);

        if (count($calendars) == 0) {
            $properties = [];
            if (Utils::isResourceFromPrincipal($principalUri)) {
                $principal = $this->principalBackend->getPrincipalByPath($principalUri);

                $properties['{DAV:}displayname'] = $principal['{DAV:}displayname'];
            }

            // No calendars yet, inject our default calendars
            $principalExploded = explode('/', $principalUri);
            parent::createCalendar($principalUri, $principalExploded[2], $properties);

            $calendars = parent::getCalendarsForUser($principalUri);
        }

        return $calendars;
    }
}
