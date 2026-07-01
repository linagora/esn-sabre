<?php
namespace ESN\CalDAV;

use Sabre\CalDAV\ICalendarObjectContainer;
use Sabre\DAV\Server;
use Sabre\VObject\Reader;

/**
 * Answers the common filter-less calendar-query REPORT (the one issued by the
 * reindex task, see linagora/esn-sabre#403) straight from a single Mongo query
 * returning uri + calendardata + etag for every object, skipping the extra node
 * resolution round-trip that getPropertiesForMultiplePaths performs.
 *
 * We stay conservative: anything with a real filter, an expand, a non-Mongo
 * backend, or a requested property we cannot synthesise from the query result
 * is left to the caller, which falls back to the standard (batched) path.
 */
class SingleQueryReport {
    const NS_CALDAV = \Sabre\CalDAV\Plugin::NS_CALDAV;

    private $server;

    /** @var SharedCalendar The calendar the report runs against. */
    private $node;

    /** @var string|null The current user principal, resolved once per build. */
    private $currentUser;

    /** @var bool Whether PRIVATE / CONFIDENTIAL events must be sanitized. */
    private $sanitizeForDelegate;

    /** @var bool Whether calendar-data must be emitted as calendar+json. */
    private $needsJson;

    function __construct(Server $server) {
        $this->server = $server;
    }

    /**
     * Builds the multistatus entries for a filter-less calendar-query REPORT
     * directly from calendarQueryWithAllData(), or returns null when the report
     * cannot be served from a single query and the caller must fall back to the
     * standard (batched) path.
     *
     * @param \Sabre\CalDAV\Xml\Request\CalendarQueryReport $report
     * @param string $path
     * @param ICalendarObjectContainer $node
     * @return array|null
     */
    function tryBuild($report, $path, ICalendarObjectContainer $node) {
        if (!$this->canReportFromSingleQuery($report, $node)) {
            return null;
        }

        $calendarDataProp = '{' . self::NS_CALDAV . '}calendar-data';
        $etagProp = '{DAV:}getetag';

        $wantsCalendarData = in_array($calendarDataProp, $report->properties, true);
        $wantsEtag = in_array($etagProp, $report->properties, true);

        $this->prepareRenderContext($report, $node);

        $result = [];
        foreach ($node->getBackend()->calendarQueryWithAllData($node->getFullCalendarId(), $report->filters) as $object) {
            $properties = [];

            if ($wantsCalendarData) {
                $properties[$calendarDataProp] = $this->renderCalendarData($object);
            }
            if ($wantsEtag) {
                $properties[$etagProp] = $object['etag'];
            }

            $result[] = [
                200 => $properties,
                404 => [],
                'href' => $path . '/' . $object['uri']
            ];
        }

        return $result;
    }

    /**
     * Resolves the per-request state renderCalendarData() relies on: the target
     * calendar, the current user and whether delegate sanitization applies.
     *
     * @param \Sabre\CalDAV\Xml\Request\CalendarQueryReport $report
     * @param SharedCalendar $node
     * @return void
     */
    private function prepareRenderContext($report, SharedCalendar $node) {
        $this->node = $node;
        $this->needsJson = $report->contentType === 'application/calendar+json';
        $this->currentUser = $this->getCurrentUserPrincipal();

        $calendarOwner = $node->getOwner();
        // Same rule as PrivateEventPlugin: a delegate (not the calendar owner)
        // only sees a sanitized version of PRIVATE / CONFIDENTIAL events.
        $this->sanitizeForDelegate = $this->currentUser !== null && $calendarOwner !== null && $calendarOwner !== $this->currentUser;
    }

    /**
     * Whether the calendar-query REPORT can be answered straight from a single
     * Mongo query (uri + calendardata + etag) without going through
     * getPropertiesForMultiplePaths at all.
     *
     * This is the common filter-less case (the reindex REPORT): the objects are
     * already returned with their data by the query, so we skip the extra node
     * resolution round-trip entirely and parse each object at most once.
     *
     * @param \Sabre\CalDAV\Xml\Request\CalendarQueryReport $report
     * @param ICalendarObjectContainer $node
     * @return bool
     */
    private function canReportFromSingleQuery($report, ICalendarObjectContainer $node) {
        if ($report->expand) {
            return false;
        }

        if (!$this->isMongoSharedCalendar($node)) {
            return false;
        }

        if ($this->hasFilters($report)) {
            return false;
        }

        return $this->requestsOnlyServableProperties($report);
    }

    private function isMongoSharedCalendar(ICalendarObjectContainer $node) {
        return $node instanceof SharedCalendar && $node->getBackend() instanceof Backend\Mongo;
    }

    private function hasFilters($report) {
        $filters = $report->filters;

        return !empty($filters['prop-filters']) || !empty($filters['comp-filters']);
    }

    private function requestsOnlyServableProperties($report) {
        if (empty($report->properties)) {
            return false;
        }

        $servable = ['{DAV:}getetag', '{' . self::NS_CALDAV . '}calendar-data'];
        foreach ($report->properties as $property) {
            if (!in_array($property, $servable, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Renders the calendar-data of a single query result, applying the same
     * private-event sanitization PrivateEventPlugin would apply on the standard
     * propFind path. Non-private objects are returned verbatim without being
     * parsed, so their serialization is byte-for-byte identical to the standard
     * path.
     *
     * @param array $object Query result with uri/calendardata/etag/classification
     * @return string
     */
    private function renderCalendarData($object) {
        $classification = isset($object['classification']) ? strtoupper($object['classification']) : null;
        $needsSanitization = $this->sanitizeForDelegate
            && ($classification === 'PRIVATE' || $classification === 'CONFIDENTIAL');

        if (!$this->needsJson && !$needsSanitization) {
            return $object['calendardata'];
        }

        $vObject = isset($object['vObject']) && $object['vObject'] !== null
            ? $object['vObject']
            : Reader::read($object['calendardata']);

        $rendered = $vObject;
        if ($needsSanitization) {
            $rendered = \ESN\Utils\Utils::hidePrivateEventInfoForUser($vObject, $this->node, $this->currentUser);
        }

        $data = $this->needsJson ? json_encode($rendered->jsonSerialize()) : $rendered->serialize();

        if ($rendered !== $vObject) {
            $rendered->destroy();
        }
        $vObject->destroy();

        return $data;
    }

    private function getCurrentUserPrincipal() {
        $authPlugin = $this->server->getPlugin('auth');

        return $authPlugin ? $authPlugin->getCurrentPrincipal() : null;
    }
}
