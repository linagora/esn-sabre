<?php
namespace ESN\CalDAV;

use \Sabre\VObject;
use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;
use \Sabre\Uri;
use DateTimeZone;
use \Sabre\HTTP\RequestInterface;
use \Sabre\HTTP\ResponseInterface;

#[\AllowDynamicProperties]
class ImportPlugin extends \ESN\JSON\BasePlugin  {

    /**
     * This is the official CalDAV namespace
     */
    const NS_CALDAV = 'urn:ietf:params:xml:ns:caldav';

    /**
     * Query parameter keeping, on import, the copies of the event living in the other
     * calendars of the user: see removeDuplicateObjects
     */
    const KEEP_DUPLICATES_PARAMETER = 'keepDuplicates';

    function initialize(Server $server) {
        parent::initialize($server);

        $server->on('schedule', [$this, 'schedule'], 99);
        $server->on('afterMethod:PUT', [$this, 'removeDuplicateObjects'], 98);
    }

    /**
     * Returns a plugin name.
     *
     * Using this name other plugins will be able to access other plugins
     * using DAV\Server::getPlugin
     *
     * @return string
     */
    function getPluginName() {
        return 'caldav-import';
    }

    /**
     * Returns a bunch of meta-data about the plugin.
     *
     * Providing this information is optional, and is mainly displayed by the
     * Browser plugin.
     *
     * The description key in the returned array may contain html and will not
     * be sanitized.
     *
     * @return array
     */
    function getPluginInfo() {
        return [
            'name'        => $this->getPluginName(),
            'description' => 'Adds import support for CalDAV',
            'link'        => 'http://sabre.io/dav/caldav/',
        ];
    }

    function schedule(\Sabre\VObject\ITip\Message $iTipMessage) {
        $queryParams = $this->server->httpRequest->getQueryParameters();
        if (!array_key_exists('import', $queryParams)) return;

        return false;
    }

    /**
     * Removes, once an import succeeded, the other copies of the imported event.
     *
     * Why: the calendar frontend imports an .ics file by PUTting each of its events
     * (`?import`) into the calendar the user picked. Importing again a file holding an
     * event the user already has in another of their calendars must not leave them
     * with two occurrences of it (OpenPaaS-Suite/esn-frontend-calendar#276): the import
     * moves the event into the picked calendar.
     *
     * So, on a `?import` PUT that succeeded (the preconditions passed and the object was
     * written - a failed request leaves the home untouched), the objects bearing the UID
     * of the imported object in the other calendars owned by the user are deleted. The
     * imported object itself is never deleted: re-importing an event into its own
     * calendar is a plain update.
     *
     * The same UID in two calendars is nonetheless a legitimate state (Outlook's "Copy
     * to calendar" keeps the UID, migrations carry such copies over): clients importing
     * such copies opt out with the `keepDuplicates` query parameter
     * (`PUT ...?import&keepDuplicates`).
     */
    function removeDuplicateObjects(RequestInterface $request, ResponseInterface $response) {
        $queryParams = $request->getQueryParameters();
        if (!array_key_exists('import', $queryParams)) return;
        if (array_key_exists(self::KEEP_DUPLICATES_PARAMETER, $queryParams)) return;
        if (!in_array($response->getStatus(), [201, 204])) return;

        $pathParts = explode('/', trim($request->getPath(), '/'));
        if (count($pathParts) !== 4 || $pathParts[0] !== 'calendars') return;
        list($namespace, $homeId, $calendarUri, $objectUri) = $pathParts;

        $homePath = $namespace . '/' . $homeId;
        $home = $this->server->tree->getNodeForPath($homePath);
        if (!($home instanceof CalendarHome)) return;

        foreach($home->getDuplicateCalendarObjects($calendarUri, $objectUri) as $eventPath) {
            $fullPath = $homePath . '/' . $eventPath;
            $this->server->tree->delete($fullPath);
            $this->server->emit('afterUnbind', [$fullPath]);
        }
    }
}
