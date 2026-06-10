<?php

namespace ESN\JSON\CalDAV;

use ESN\Utils\Utils;
use \Sabre\DAV\Exception\Forbidden;
use \Sabre\VObject,
    \Sabre\DAV;

/**
 * Calendar Handler
 *
 * Handles calendar-related operations including:
 * - Calendar CRUD operations
 * - Calendar property management
 * - Calendar listing and filtering
 * - Calendar sharing and public rights
 */
class CalendarHandler {
    use ValidatesResourceIds;

    private const CALENDAR_JSON_PROPERTIES = [
        '{DAV:}displayname' => 'dav:name',
        '{urn:ietf:params:xml:ns:caldav}calendar-description' => 'caldav:description',
        '{http://calendarserver.org/ns/}getctag' => 'calendarserver:ctag',
        '{http://apple.com/ns/ical/}calendar-color' => 'apple:color',
        '{http://apple.com/ns/ical/}calendar-order' => 'apple:order'
    ];

    private const SUBSCRIPTION_JSON_PROPERTIES = [
        '{http://apple.com/ns/ical/}calendar-color' => 'apple:color',
        '{http://apple.com/ns/ical/}calendar-order' => 'apple:order'
    ];

    protected $server;
    protected $currentUser;

    public function __construct($server, $currentUser) {
        $this->server = $server;
        $this->currentUser = $currentUser;
    }

    public function createCalendar($homePath, $jsonData) {
        $issetdef = $this->propertyOrDefault($jsonData);

        if (!$this->isValidResourceId($jsonData->id ?? null)) {
            return [400, null];
        }

        $rt = ['{DAV:}collection', '{urn:ietf:params:xml:ns:caldav}calendar'];
        $props = [
            '{DAV:}displayname' => $issetdef('dav:name'),
            '{urn:ietf:params:xml:ns:caldav}calendar-description' => $issetdef('caldav:description'),
            '{http://apple.com/ns/ical/}calendar-color' => $issetdef('apple:color'),
            '{http://apple.com/ns/ical/}calendar-order' => $issetdef('apple:order')
        ];

        $this->server->createCollection($homePath . '/' . $jsonData->id, new \Sabre\DAV\MkCol($rt, $props));

        return [201, null];
    }

    public function changeCalendarProperties($nodePath, $jsonData) {
        $propnameMap = [
            'dav:name' => '{DAV:}displayname',
            'caldav:description' => '{urn:ietf:params:xml:ns:caldav}calendar-description',
            'apple:color' => '{http://apple.com/ns/ical/}calendar-color',
            'apple:order' => '{http://apple.com/ns/ical/}calendar-order'
        ];

        // List of read-only properties that cannot be modified
        $readonlyProps = ['dav:getetag', 'dav:getctag', 'calendarserver:ctag'];

        $davProps = [];
        foreach ($jsonData as $jsonProp => $value) {
            // Check if trying to modify a read-only property
            if (in_array($jsonProp, $readonlyProps)) {
                return [403, null];
            }

            if (isset($propnameMap[$jsonProp])) {
                $davProps[$propnameMap[$jsonProp]] = $value;
            }
        }

        $result = $this->server->updateProperties($nodePath, $davProps);

        return [$this->statusCodeFromUpdateResult($result), null];
    }

    private function statusCodeFromUpdateResult($result): int {
        foreach ($result as $code) {
            if ((int)$code > 299) {
                return (int)$code;
            }
        }

        return 204;
    }

    public function listCalendarHomes($nodePath, $node, $withRights, $calendarTypeOptions) {
        $homes = $node->getChildren();
        $baseUri = $this->server->getBaseUri();

        $items = [];
        foreach ($homes as $home) {
            $noderef = $nodePath . '/' . $home->getName();
            list($code, $result) = $this->listCalendars($noderef, $home, $withRights, $calendarTypeOptions);
            if (!empty($result)) {
                $items[] = $result;
            }
        }

        $requestPath = $baseUri . $nodePath . '.json';
        $result = [
            '_links' => [
              'self' => [ 'href' => $requestPath ]
            ],
            '_embedded' => [ 'dav:home' => $items ]
        ];

        return [200, $result];
    }

    public function listCalendars($nodePath, $node, $withRights, $calendarTypeOptions, $sharedPublic = false, $withFreeBusy = false) {
        $baseUri = $this->server->getBaseUri();

        if ($sharedPublic) {
            $items = $this->listPublicCalendars($nodePath, $node, $withRights);
        } else {
            $items = $this->listAllCalendarsWithReadRight($nodePath, $node, $withRights, $calendarTypeOptions, $withFreeBusy);
        }

        $requestPath = $baseUri . $nodePath . '.json';
        $result = [];
        if (!empty($items)) {
            $result = [
                '_links' => [
                    'self' => [ 'href' => $requestPath ]
                ],
                '_embedded' => [ 'dav:calendar' => $items ]
            ];
        }

        return [200, $result];
    }

    public function listAllCalendarsWithReadRight($nodePath, $node, $withRights, $calendarTypeOptions, $withFreeBusy) {
        $listingContext = [
            'right' => $withFreeBusy ? '{urn:ietf:params:xml:ns:caldav}read-free-busy' : '{DAV:}read',
            'withRights' => $withRights,
            'typeOptions' => $calendarTypeOptions
        ];

        $items = [];
        foreach ($node->getChildren() as $calendar) {
            $item = $this->calendarChildToJson($calendar, $nodePath . '/' . $calendar->getName(), $listingContext);

            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Renders a calendar home child as JSON when the listing options select
     * it, or returns null when it must be excluded.
     */
    private function calendarChildToJson($calendar, string $calendarPath, array $listingContext): ?array {
        if ($this->shouldIncludeCalendar($calendar, $calendarPath, $listingContext['right'], $listingContext['typeOptions'])) {
            return $this->calendarToJson($calendarPath, $calendar, $listingContext['withRights']);
        }

        if ($this->shouldIncludeSubscription($calendar, $calendarPath, $listingContext['right'], $listingContext['typeOptions'])) {
            return $this->subscriptionToJson($calendarPath, $calendar, $listingContext['withRights']);
        }

        return null;
    }

    private function hasRightOn(string $calendarPath, string $right): bool {
        return $this->server->getPlugin('acl')->checkPrivileges($calendarPath, $right, \Sabre\DAVACL\Plugin::R_PARENT, false);
    }

    private function shouldIncludeCalendar($calendar, string $calendarPath, string $right, $calendarTypeOptions): bool {
        if (!($calendar instanceof \ESN\CalDAV\SharedCalendar) || !$this->hasRightOn($calendarPath, $right)) {
            return false;
        }

        //Personnal Calendars
        if (!$calendar->isSharedInstance()) {
            return !empty($calendarTypeOptions['includePersonal']);
        }

        //Shared Calendars
        return !empty($calendarTypeOptions['includeShared'])
            && $this->matchesDelegationStatus($calendar, $calendarTypeOptions);
    }

    private function matchesDelegationStatus($calendar, $calendarTypeOptions): bool {
        return !isset($calendarTypeOptions['sharedDelegationStatus'])
            || $calendar->getInviteStatus() === $calendarTypeOptions['sharedDelegationStatus'];
    }

    private function shouldIncludeSubscription($calendar, string $calendarPath, string $right, $calendarTypeOptions): bool {
        return $calendar instanceof \Sabre\CalDAV\Subscriptions\Subscription
            && !empty($calendarTypeOptions['includeSharedPublicSubscription'])
            && $this->hasRightOn($calendarPath, $right);
    }

    public function listAllPersonalCalendars($calendarHomeNode) {
        $calendars = $calendarHomeNode->getChildren();

        $personalCalendars = [];
        foreach ($calendars as $calendar) {
            if ($calendar instanceof \ESN\CalDAV\SharedCalendar) {
                if (!$calendar->isSharedInstance()) {
                    $personalCalendars[] = $calendar->getName();
                }
            }
        }

        return $personalCalendars;
    }

    public function listPublicCalendars($nodePath, $node, $withRights = null) {
        $calendars = $node->getChildren();

        $items = [];
        foreach ($calendars as $calendar) {
            if ($this->isPublicPersonalCalendar($calendar)) {
                $items[] = $this->calendarToJson($nodePath . '/' . $calendar->getName(), $calendar, $withRights);
            }
        }

        return $items;
    }

    private function isPublicPersonalCalendar($calendar): bool {
        return $calendar instanceof \ESN\CalDAV\SharedCalendar
            && !$calendar->isSharedInstance()
            && $calendar->isPublic();
    }

    public function getCalendarInformation($nodePath, $node, $withRights) {
        return [200, $this->calendarToJson($nodePath, $node, $withRights)];
    }

    public function calendarToJson($nodePath, $calendar, $withRights = null) {
        $baseUri = $this->server->getBaseUri();
        $calprops = $calendar->getProperties([]);

        $json = [
            '_links' => [
                'self' => [ 'href' => $baseUri . $nodePath . '.json' ],
            ]
        ];

        $delegatedSourcePath = $this->resolveDelegatedSourcePath($calendar);
        if ($delegatedSourcePath !== null) {
            $json['calendarserver:delegatedsource'] = $baseUri . $delegatedSourcePath;
        }

        $this->mapDavProperties($json, $calprops, self::CALENDAR_JSON_PROPERTIES);

        if ($withRights) {
            $this->appendRights($json, $calendar);
        }

        return $json;
    }

    /**
     * For a shared (delegated) calendar instance, finds the path of the source
     * calendar in the share owner's home. Returns null for non-delegated
     * calendars or when the source cannot be located.
     */
    private function resolveDelegatedSourcePath($calendar): ?string {
        if (!($calendar instanceof \ESN\CalDAV\SharedCalendar) || !$calendar->isSharedInstance()) {
            return null;
        }

        foreach ($calendar->getInvites() as $user) {
            if ($user->access != \Sabre\DAV\Sharing\Plugin::ACCESS_SHAREDOWNER) {
                continue;
            }

            $sourceCalendarOwner = $this->ownerIdFromPrincipal($user->principal);
            if ($sourceCalendarOwner === null) {
                continue;
            }

            $sourceCalendarUri = $this->findOwnerCalendarUri($sourceCalendarOwner, $calendar->getCalendarId());
            if ($sourceCalendarUri !== null) {
                return 'calendars/' . $sourceCalendarOwner . '/' . $sourceCalendarUri . '.json';
            }
        }

        return null;
    }

    /**
     * Extracts the owner id from a principal URI, returning null when the
     * principal is null or malformed.
     */
    private function ownerIdFromPrincipal($principal): ?string {
        if ($principal === null) {
            return null;
        }

        $uriExploded = explode('/', $principal);
        if (count($uriExploded) < 3) {
            return null;
        }

        $ownerId = $uriExploded[2];

        return ($ownerId === null || $ownerId === '') ? null : $ownerId;
    }

    private function findOwnerCalendarUri(string $ownerId, $calendarid): ?string {
        $ownerHome = $this->server->tree->getNodeForPath('/calendars/' . $ownerId);

        foreach ($ownerHome->getChildren() as $ownerCalendar) {
            if ($ownerCalendar instanceof \ESN\CalDAV\SharedCalendar && $ownerCalendar->getCalendarId() == $calendarid) {
                return $ownerCalendar->getName();
            }
        }

        return null;
    }

    private function mapDavProperties(array &$json, $props, array $propertyMap): void {
        foreach ($propertyMap as $davProperty => $jsonKey) {
            if (isset($props[$davProperty])) {
                $json[$jsonKey] = $props[$davProperty];
            }
        }
    }

    private function appendRights(array &$json, $node): void {
        if (method_exists($node, 'getInvites') && $node->getInvites()) {
            $json['invite'] = $node->getInvites();
        }

        if (method_exists($node, 'getACL') && $node->getACL()) {
            $json['acl'] = $node->getACL();
        }
    }

    public function subscriptionToJson($nodePath, $subscription, $withRights = null) {
        $baseUri = $this->server->getBaseUri();
        $propertiesList = [
            '{DAV:}displayname',
            '{http://calendarserver.org/ns/}source',
            '{http://apple.com/ns/ical/}calendar-color',
            '{http://apple.com/ns/ical/}calendar-order'
        ];
        $subprops = $subscription->getProperties($propertiesList);

        $json = [
            '_links' => [
                'self' => [ 'href' => $baseUri . $nodePath . '.json' ],
            ]
        ];

        if (isset($subprops['{DAV:}displayname'])) {
            $json['dav:name'] = $subprops['{DAV:}displayname'];
        }

        if (isset($subprops['{http://calendarserver.org/ns/}source'])) {
            $source = $this->subscriptionSourceToJson($subprops['{http://calendarserver.org/ns/}source']->getHref());

            // Skip the whole subscription when its source is invalid or gone
            if ($source === null) {
                return null;
            }

            $json['calendarserver:source'] = $source;
        }

        $this->mapDavProperties($json, $subprops, self::SUBSCRIPTION_JSON_PROPERTIES);

        if ($withRights) {
            $this->appendRights($json, $subscription);
        }

        return $json;
    }

    private function subscriptionSourceToJson($sourceHref): ?array {
        $sourcePath = $this->normalizeSourcePath($sourceHref);

        if ($sourcePath === null || !$this->server->tree->nodeExists($sourcePath)) {
            return null;
        }

        return $this->calendarToJson($sourcePath, $this->server->tree->getNodeForPath($sourcePath), true);
    }

    /**
     * Extracts and normalizes the path component of a subscription source
     * href. Returns null when the href is empty, malformed, or normalizes to
     * an empty path.
     */
    private function normalizeSourcePath($sourceHref): ?string {
        if ($sourceHref === null || $sourceHref === '') {
            return null;
        }

        // parse_url can return null (component absent) or false (malformed URL)
        $path = parse_url($sourceHref, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }

        // Remove leading slashes, collapse multiple slashes, remove trailing slashes
        $sourcePath = rtrim(preg_replace('#/+#', '/', ltrim($path, '/')), '/');

        return $sourcePath === '' ? null : $sourcePath;
    }

    public function getSubscriptionInformation($nodePath, $node, $withRights) {
        $subscription = $this->subscriptionToJson($nodePath, $node, $withRights);

        if(!isset($subscription)) {
            return [404, null];
        }

        return [200, $subscription];
    }

    public function updateSharees($path, $jsonData) {
        $sharingPlugin = $this->server->getPlugin('sharing');

        $sharees = array_merge(
            $this->buildShareesToSet($jsonData->share->set ?? []),
            $this->buildShareesToRemove($jsonData->share->remove ?? [])
        );

        $sharingPlugin->shareResource($path, $sharees);

        // see vendor/sabre/dav/lib/CalDAV/SharingPlugin.php:268
        $this->server->httpResponse->setHeader('X-Sabre-Status', 'everything-went-well');

        return [200, null];
    }

    private function buildShareesToSet($shareesToSet): array {
        $sharees = [];

        foreach ($shareesToSet as $sharee) {
            $access = $this->shareeAccessLevel($sharee);

            // Skip this sharee if no valid access level is found
            if ($access === null) {
                continue;
            }

            $properties = [];
            if (isset($sharee->{'common-name'})) {
                $properties['{DAV:}displayname'] = $sharee->{'common-name'};
            }

            $sharees[] = new \Sabre\DAV\Xml\Element\Sharee([
                'href'       => $sharee->{'dav:href'},
                'properties' => $properties,
                'access'     => $access,
                'comment'    => isset($sharee->summary) ? $sharee->summary : null
            ]);
        }

        return $sharees;
    }

    private function shareeAccessLevel($sharee): ?int {
        if (isset($sharee->{'dav:administration'})) {
            return \ESN\DAV\Sharing\Plugin::ACCESS_ADMINISTRATION;
        }
        if (isset($sharee->{'dav:read-write'})) {
            return \Sabre\DAV\Sharing\Plugin::ACCESS_READWRITE;
        }
        if (isset($sharee->{'dav:read'})) {
            return \Sabre\DAV\Sharing\Plugin::ACCESS_READ;
        }
        if (isset($sharee->{'dav:freebusy'})) {
            return \ESN\DAV\Sharing\Plugin::ACCESS_FREEBUSY;
        }

        return null;
    }

    private function buildShareesToRemove($shareesToRemove): array {
        $sharees = [];

        foreach ($shareesToRemove as $sharee) {
            $sharees[] = new \Sabre\DAV\Xml\Element\Sharee([
                'href'   => $sharee->{'dav:href'},
                'access' => \Sabre\DAV\Sharing\Plugin::ACCESS_NOACCESS
            ]);
        }

        return $sharees;
    }

    public function updateInviteStatus($node, $jsonData) {
        if(isset($jsonData->{'invite-reply'}->invitestatus)) {
            switch ($jsonData->{'invite-reply'}->{'invitestatus'}) {
                case 'accepted':
                    $inviteStatus = \Sabre\DAV\Sharing\Plugin::INVITE_ACCEPTED;
                    break;
                case 'noresponse':
                    $inviteStatus = \ESN\DAV\Sharing\Plugin::INVITE_NORESPONSE;
                    break;
            }

            if (isset($inviteStatus)) {
                $node->updateInviteStatus($inviteStatus);

                // see vendor/sabre/dav/lib/CalDAV/SharingPlugin.php:268
                $this->server->httpResponse->setHeader('X-Sabre-Status', 'everything-went-well');

                return [200, null];
            }
        }

        return [400, null];
    }

    public function changePublicRights($request) {
        //this is a very simplified version of Sabre\DAVACL\Plugin#httpacl function
        //here we do not consider a normal acl payload but only a json formatted like {public_right: aprivilege}
        //if the request is not 400 we need to store this info inside the calendarinstance node (i.e. $node->savePublicRight)
        //the info is then available through node->getACL() alongside hardcoded acls

        $path = $request->getPath();
        $node = $this->server->tree->getNodeForPath($path);

        if (!($node instanceof \ESN\CalDAV\SharedCalendar)) {
            return null;
        }

        $this->assertCanModifyPublicRights($path);

        $publicRight = $this->decodePublicRightPayload($request);

        $this->assertSupportedPublicRight($node, $publicRight);

        $node->savePublicRight($publicRight);

        return [200, $node->getACL()];
    }

    /**
     * Checks that the user has the {DAV:}share privilege before modifying
     * public rights. Only ADMINISTRATION access level grants this privilege.
     */
    private function assertCanModifyPublicRights($path): void {
        $aclPlugin = $this->server->getPlugin('acl');
        if (!$aclPlugin->checkPrivileges($path, '{DAV:}share', \Sabre\DAVACL\Plugin::R_PARENT, false)) {
            throw new Forbidden('You do not have permission to modify public rights on this calendar');
        }
    }

    private function decodePublicRightPayload($request) {
        $jsonData = json_decode($request->getBodyAsString());

        if ($jsonData === null || !is_object($jsonData)) {
            throw new DAV\Exception\BadRequest('Invalid JSON in request body');
        }

        if (!isset($jsonData->public_right)) {
            throw new DAV\Exception\BadRequest('Missing public_right property in JSON request');
        }

        return $jsonData->public_right;
    }

    private function assertSupportedPublicRight($node, $publicRight): void {
        $supportedPrivileges = $this->server->getPlugin('acl')->getFlatPrivilegeSet($node);
        $supportedPrivileges[""] = "Private";

        if (!isset($supportedPrivileges[$publicRight])) {
            throw new \Sabre\DAVACL\Exception\NotSupportedPrivilege('The privilege you specified (' . $publicRight . ') is not recognized by this server');
        }
    }

    public function isOldDefaultCalendarUriNotFound($url) {
        return strpos($url, \ESN\CalDAV\Backend\Esn::EVENTS_URI) && !$this->server->tree->nodeExists($url);
    }

    public function getDefaultCalendarUri($user, $path) {
        list(,,$userId) = explode('/', $user);

        $eventUriPos = strpos($path, \ESN\CalDAV\Backend\Esn::EVENTS_URI);
        if ($eventUriPos === false) {
            throw new DAV\Exception\NotFound('Unable to determine calendar home path');
        }

        $homePath = substr($path, 0, $eventUriPos);
        $node = $this->server->tree->getNodeForPath($homePath);

        $existingDefaultCalendarUri = $this->findExistingDefaultCalendarUri($node, $userId);
        if ($existingDefaultCalendarUri !== null) {
            return $existingDefaultCalendarUri;
        }

        // No default calendar found - create it
        // This handles the case where a user has delegated calendars but no personal default calendar yet (issue #206)
        return $this->createDefaultCalendar($node, $user, $userId);
    }

    private function findExistingDefaultCalendarUri($node, $userId): ?string {
        foreach ($node->getChildren() as $calendar) {
            $name = $calendar->getName();

            if ($name === \ESN\CalDAV\Backend\Esn::EVENTS_URI || $name === $userId) {
                return $name;
            }
        }

        return null;
    }

    private function createDefaultCalendar($node, $user, $userId): string {
        $backend = $node->getCalDAVBackend();

        if (!($backend instanceof \ESN\CalDAV\Backend\Esn)) {
            throw new DAV\Exception\NotFound('Unable to find or create user default calendar');
        }

        $properties = [];
        if (Utils::isResourceFromPrincipal($user)) {
            $principal = $backend->getPrincipalBackend()->getPrincipalByPath($user);
            if ($principal) {
                $properties['{DAV:}displayname'] = $principal['{DAV:}displayname'];
            }
        }
        $backend->createCalendar($user, $userId, $properties);

        return $userId;
    }

    private function propertyOrDefault($jsonData) {
        return function($key, $default = null) use ($jsonData) {
            return isset($jsonData->{$key}) ? $jsonData->{$key} : $default;
        };
    }
}
