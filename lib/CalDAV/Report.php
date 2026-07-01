<?php
namespace ESN\CalDAV;

use DateTimeZone;
use Sabre\CalDAV\ICalendarObjectContainer;
use Sabre\DAV\Server;
use Sabre\VObject\Reader;

/**
 * Holds the ESN specific calendar-query REPORT overrides so that they are
 * isolated from the rest of the MongoDB CalDAV Plugin. The Plugin simply
 * delegates its report handling to this class.
 */
class Report {
    const NS_CALDAV = \Sabre\CalDAV\Plugin::NS_CALDAV;

    private $server;

    function __construct(Server $server) {
        $this->server = $server;
    }

    /**
     * This function handles the calendar-query REPORT.
     *
     * The stock Sabre implementation fetches the matching URIs with a single
     * query but then reloads every object one at a time through
     * getPropertiesForPath(). On a calendar holding tens of thousands of events
     * that N+1 turns a filter-less REPORT (the one issued by the reindex task,
     * see linagora/esn-sabre#403) into as many Mongo round-trips as there are
     * objects, which blows past every reasonable timeout.
     *
     * We override the Depth: 1 calendar-collection case to fetch the calendar
     * data of all matching objects in a single batch, exactly like
     * ICSExportPlugin does for export. Every other shape (direct object,
     * Depth: 0, ...) is rare and delegated to $fallback, which runs the stock
     * implementation.
     *
     * @param \Sabre\CalDAV\Xml\Request\CalendarQueryReport $report
     * @param callable $fallback Runs the stock Sabre implementation.
     * @return void
     */
    function calendarQueryReport($report, callable $fallback) {
        $path = $this->server->getRequestUri();
        $node = $this->server->tree->getNodeForPath($path);
        $depth = $this->server->getHTTPDepth(0);

        if (!($node instanceof ICalendarObjectContainer) || $depth != 1) {
            $fallback();

            return;
        }

        $this->sendMultiStatus($this->buildCalendarQueryReportResult($report, $path, $node));
    }

    /**
     * Builds the multistatus response entries for a Depth: 1 calendar-query
     * REPORT on a calendar collection.
     *
     * The filter-less case is served straight from a single query (see
     * {@see SingleQueryReport}); every other shape resolves the matching URIs
     * and fetches their properties in one batch through
     * getPropertiesForMultiplePaths(), turning the stock per-object N+1 into a
     * single additional query.
     *
     * @param \Sabre\CalDAV\Xml\Request\CalendarQueryReport $report
     * @param string $path
     * @param ICalendarObjectContainer $node
     * @return array
     */
    protected function buildCalendarQueryReportResult($report, $path, ICalendarObjectContainer $node) {
        $singleQueryResult = (new SingleQueryReport($this->server))->tryBuild($report, $path, $node);
        if ($singleQueryResult !== null) {
            return $singleQueryResult;
        }

        return $this->buildBatchedResult($report, $path, $node);
    }

    /**
     * Standard path: resolve the matching URIs then fetch their properties in a
     * single batch, rendering calendar-data (json/expand) as needed.
     *
     * @param \Sabre\CalDAV\Xml\Request\CalendarQueryReport $report
     * @param string $path
     * @param ICalendarObjectContainer $node
     * @return array
     */
    private function buildBatchedResult($report, $path, ICalendarObjectContainer $node) {
        $needsJson = $report->contentType === 'application/calendar+json';
        $calendarTimeZone = $report->expand ? $this->resolveCalendarTimeZone($path) : null;

        $paths = array_map(function ($nodePath) use ($path) {
            return $path . '/' . $nodePath;
        }, $node->calendarQuery($report->filters));

        $result = [];
        foreach ($this->server->getPropertiesForMultiplePaths($paths, $report->properties) as $properties) {
            $result[] = $this->renderBatchedProperties($properties, $report, $needsJson, $calendarTimeZone);
        }

        return $result;
    }

    /**
     * Renders the calendar-data of a single getPropertiesForMultiplePaths entry
     * when the client asked for json output or an expand; otherwise the entry is
     * returned untouched.
     *
     * @param array $properties
     * @param \Sabre\CalDAV\Xml\Request\CalendarQueryReport $report
     * @param bool $needsJson
     * @param DateTimeZone|null $calendarTimeZone
     * @return array
     */
    private function renderBatchedProperties($properties, $report, $needsJson, $calendarTimeZone) {
        $calendarDataProp = '{' . self::NS_CALDAV . '}calendar-data';

        if (!$needsJson && !$report->expand) {
            return $properties;
        }
        if (!isset($properties[200][$calendarDataProp])) {
            return $properties;
        }

        $vObject = Reader::read($properties[200][$calendarDataProp]);

        if ($report->expand) {
            $vObject = $vObject->expand($report->expand['start'], $report->expand['end'], $calendarTimeZone);
        }

        $properties[200][$calendarDataProp] = $needsJson
            ? json_encode($vObject->jsonSerialize())
            : $vObject->serialize();

        // Destroy circular references so PHP will garbage collect the object.
        $vObject->destroy();

        return $properties;
    }

    /**
     * Figures out the calendar's timezone, needed when expanding recurrences.
     *
     * @param string $path
     * @return DateTimeZone
     */
    private function resolveCalendarTimeZone($path) {
        $tzProp = '{' . self::NS_CALDAV . '}calendar-timezone';
        $tzResult = $this->server->getProperties($path, [$tzProp]);

        if (!isset($tzResult[$tzProp])) {
            return new DateTimeZone('UTC');
        }

        $vtimezoneObj = Reader::read($tzResult[$tzProp]);
        $calendarTimeZone = $vtimezoneObj->VTIMEZONE->getTimeZone();
        $vtimezoneObj->destroy();

        return $calendarTimeZone;
    }

    private function sendMultiStatus($result) {
        $prefer = $this->server->getHTTPPrefer();

        $this->server->httpResponse->setStatus(207);
        $this->server->httpResponse->setHeader('Content-Type', 'application/xml; charset=utf-8');
        $this->server->httpResponse->setHeader('Vary', 'Brief,Prefer');
        $this->server->httpResponse->setBody($this->server->generateMultiStatus($result, $prefer['return'] === 'minimal'));
    }
}
