<?php

namespace ESN\CalDAV;

use ESN\CalDAV\Exception\UnauthorizedOrganizer;
use ESN\DAV\Sharing\Plugin as SharingPlugin;
use ESN\Utils\Utils;
use Sabre\CalDAV\ICalendarObject;
use Sabre\CalDAV\Schedule\ISchedulingObject;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

class OrganizerValidationPlugin extends ServerPlugin {

    protected $server;

    function initialize(Server $server) {
        $this->server = $server;
        $server->on('calendarObjectChange', [$this, 'calendarObjectChange'], Plugin::PRIORITY_BEFORE_SCHEDULING - 10);
        $server->on('beforeMove', [$this, 'beforeMove'], 45);
        $server->on('beforeCopy', [$this, 'beforeCopy'], 45);
    }

    function getPluginName() {
        return 'caldav-organizer-validation';
    }

    function calendarObjectChange(
        RequestInterface $request,
        ResponseInterface $response,
        VCalendar $vCal,
        $calendarPath,
        &$modified,
        $isNew
    ) {
        if ($request->getMethod() === 'ITIP') {
            return;
        }

        if ($request->getMethod() === 'PUT' && array_key_exists('import', $request->getQueryParameters())) {
            return;
        }

        // An attendee's copy of an invitation keeps the inviter as ORGANIZER, so
        // updates (e.g. PARTSTAT changes) are allowed as long as the ORGANIZER is
        // unchanged from the stored object: a foreign organizer can only be there
        // because scheduling delivered it or a previously validated PUT wrote it.
        $existingOrganizerUri = !$isNew && !$this->isTeamCalendarPath($calendarPath)
            ? $this->getExistingOrganizerUri($request->getPath())
            : null;
        $this->validateCalendarOrganizer($vCal, $calendarPath, $existingOrganizerUri);
    }

    function beforeMove($sourcePath, $destinationPath) {
        $this->validateTransferredOrganizer($sourcePath, $destinationPath);
    }

    function beforeCopy($sourcePath, $destinationPath, $depth = null) {
        $this->validateTransferredOrganizer($sourcePath, $destinationPath);
    }

    /**
     * SabreDAV runs COPY and MOVE through Tree::copy()/Tree::move(), which never emit
     * calendarObjectChange and therefore skip the validation a PUT goes through. Validate the
     * transferred object against the destination calendar, so that a transfer cannot introduce
     * an ORGANIZER that a PUT to that same calendar would have rejected.
     */
    private function validateTransferredOrganizer($sourcePath, $destinationPath): void {
        list($calendarPath,) = Utils::splitEventPath('/' . ltrim($destinationPath, '/'));
        // Not a calendar object path: collection level transfers are left to the ACL plugin.
        if (!$calendarPath) return;

        try {
            $source = $this->server->tree->getNodeForPath($sourcePath);
        } catch (\Sabre\DAV\Exception) {
            return;
        }
        if (!$source instanceof ICalendarObject || $source instanceof ISchedulingObject) return;

        $calendarData = $source->get();
        if (is_resource($calendarData)) $calendarData = stream_get_contents($calendarData);
        $calendar = Reader::read($calendarData);
        if (!$calendar instanceof VCalendar) {
            $calendar->destroy();
            return;
        }

        try {
            $this->validateCalendarOrganizer($calendar, $calendarPath);
        } catch (UnauthorizedOrganizer $e) {
            // An attendee's copy of an invitation keeps the inviter as ORGANIZER, so rearranging
            // one within a single calendar home stays allowed: the object is already there and
            // the transfer hands it to nobody new.
            if (!$this->isTransferWithinSameCalendarHome($sourcePath, $calendarPath)) {
                throw $e;
            }
        } finally {
            $calendar->destroy();
        }
    }

    private function isTransferWithinSameCalendarHome($sourcePath, $destinationCalendarPath): bool {
        list($sourceCalendarPath,) = Utils::splitEventPath('/' . ltrim($sourcePath, '/'));
        if (!$sourceCalendarPath) return false;

        if ($this->calendarHomeOf($sourceCalendarPath) !== $this->calendarHomeOf($destinationCalendarPath)) {
            return false;
        }
        
        return !$this->isSharedInstance($destinationCalendarPath);
    }

    private function calendarHomeOf(string $calendarPath): string {
        // $calendarPath is 'calendars/{baseId}/{calendarUri}'.
        return explode('/', $calendarPath)[1];
    }

    private function isSharedInstance($calendarPath): bool {
        $calendarNode = $this->getCalendarNode($calendarPath);

        return $calendarNode instanceof SharedCalendar && $calendarNode->isSharedInstance();
    }

    private function validateCalendarOrganizer(VCalendar $calendar, $calendarPath, ?string $existingOrganizerUri = null): void {
        $vevents = $calendar->select('VEVENT');
        if (empty($vevents) || !($organizerUri = $this->extractOrganizerUri($vevents))) return;
        if ($existingOrganizerUri !== null && $organizerUri === $existingOrganizerUri) return;

        $this->validateOrganizerAuthorized($organizerUri, $calendarPath);
    }

    private function getExistingOrganizerUri(string $objectPath): ?string {
        try {
            $node = $this->server->tree->getNodeForPath($objectPath);
        } catch (\Sabre\DAV\Exception $e) {
            return null;
        }

        if (!$node instanceof \Sabre\CalDAV\ICalendarObject) {
            return null;
        }

        try {
            $data = $node->get();
            if (is_resource($data)) {
                $data = stream_get_contents($data);
            }
            $existingVCal = \Sabre\VObject\Reader::read($data);
        } catch (\Exception $e) {
            return null;
        }

        $organizerValues = [];
        foreach ($existingVCal->select('VEVENT') as $vevent) {
            if (isset($vevent->ORGANIZER)) {
                $organizerValues[] = strtolower((string) $vevent->ORGANIZER);
            }
        }

        if (count(array_unique($organizerValues)) !== 1) {
            return null;
        }

        return reset($organizerValues);
    }

    private function extractOrganizerUri(array $vevents): ?string {
        $organizerValues = $this->collectOrganizerValues($vevents);

        if (empty($organizerValues)) {
            return null;
        }

        if (count(array_unique($organizerValues)) > 1) {
            throw new Forbidden('All VEVENT components must share the same ORGANIZER property.');
        }

        return reset($organizerValues);
    }

    private function collectOrganizerValues(array $vevents): array {
        $organizerValues = [];
        foreach ($vevents as $vevent) {
            if (isset($vevent->ATTENDEE) && !isset($vevent->ORGANIZER)) {
                throw new Forbidden('A VEVENT with ATTENDEE properties must also have an ORGANIZER.');
            }
            if (isset($vevent->ORGANIZER)) {
                $organizerValues[] = strtolower((string) $vevent->ORGANIZER);
            }
        }
        return $organizerValues;
    }

    private function validateOrganizerAuthorized(string $organizerUri, $calendarPath): void {
        $aclPlugin = $this->server->getPlugin('acl');
        if (!$aclPlugin) {
            return;
        }

        $organizerPrincipal = $aclPlugin->getPrincipalByUri($organizerUri);
        if ($organizerPrincipal === null) {
            throw new UnauthorizedOrganizer('The ORGANIZER must be either the calendar owner or the authenticated user.');
        }

        if ($this->isTeamCalendarPath($calendarPath)) {
            if ($this->isWriteEnabledCalendarSharee($organizerPrincipal, $calendarPath)) {
                return;
            }

            throw new UnauthorizedOrganizer('The ORGANIZER must be a write-enabled team calendar member.');
        }

        if ($organizerPrincipal === $this->getCalendarOwner($calendarPath)) {
            return;
        }

        $authPlugin = $this->server->getPlugin('auth');
        $currentPrincipal = $authPlugin ? $authPlugin->getCurrentPrincipal() : null;
        if ($organizerPrincipal === $currentPrincipal) {
            return;
        }

        throw new Forbidden('The ORGANIZER must be either the calendar owner or the authenticated user.');
    }

    private function isTeamCalendarPath($calendarPath): bool {
        $calendarNode = $this->getCalendarNode($calendarPath);
        $owner = ($calendarNode && method_exists($calendarNode, 'getOwner')) ? $calendarNode->getOwner() : null;

        return Utils::isTeamCalendarFromPrincipal($owner);
    }

    private function isWriteEnabledCalendarSharee(string $organizerPrincipal, $calendarPath): bool {
        $calendarNode = $this->getCalendarNode($calendarPath);
        if (!$calendarNode || !method_exists($calendarNode, 'getInvites')) {
            return false;
        }

        foreach ($calendarNode->getInvites() as $sharee) {
            if ($this->normalizePrincipal($sharee->principal ?? null) === $this->normalizePrincipal($organizerPrincipal)
                && $this->isWriteEnabledAccess((int) ($sharee->access ?? 0))) {
                return true;
            }
        }

        return false;
    }

    private function isWriteEnabledAccess(int $access): bool {
        return in_array($access, [SharingPlugin::ACCESS_READWRITE, SharingPlugin::ACCESS_ADMINISTRATION], true);
    }

    private function getCalendarOwner($calendarPath) {
        $calendarNode = $this->getCalendarNode($calendarPath);
        if (!$calendarNode || !method_exists($calendarNode, 'getOwner')) {
            return null;
        }

        return $calendarNode->getOwner();
    }

    private function getCalendarNode($calendarPath) {
        try {
            return $this->server->tree->getNodeForPath($calendarPath);
        } catch (\Sabre\DAV\Exception\NotFound $e) {
            return null;
        }
    }

    private function normalizePrincipal(?string $principal): ?string {
        return $principal === null ? null : trim($principal, '/');
    }
}
