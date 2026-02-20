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
     * @param $principalUri
     * @return array of user calendar
     * @throws \Sabre\DAV\Exception throwed by parent::createCalendar()
     */
    function getCalendarsForUser($principalUri) {
        $calendars = parent::getCalendarsForUser($principalUri);

        $ownCalendars = array_filter($calendars, function ($cal) {
            $access = isset($cal['share-access']) ? (int) $cal['share-access'] : \Sabre\DAV\Sharing\Plugin::ACCESS_NOTSHARED;
            return $access === \Sabre\DAV\Sharing\Plugin::ACCESS_NOTSHARED
                || $access === \Sabre\DAV\Sharing\Plugin::ACCESS_SHAREDOWNER;
        });

        if (count($ownCalendars) == 0) {
            $properties = [];
            $principalExploded = explode('/', $principalUri);

            if (Utils::isResourceFromPrincipal($principalUri)) {
                $principal = $this->principalBackend->getPrincipalByPath($principalUri);
                $properties['{DAV:}displayname'] = $principal['{DAV:}displayname'];
            }

            parent::createCalendar($principalUri, $principalExploded[2], $properties);
            $calendars = parent::getCalendarsForUser($principalUri);
        }

        return $calendars;
    }
}
