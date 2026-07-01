<?php

namespace ESN\CalDAV;
use \ESN\Utils\AuthTenant;


#[\AllowDynamicProperties]
class CalendarRoot extends \Sabre\DAV\Collection {

    const USER_PREFIX = 'principals/users';
    const RESOURCES_PREFIX = 'principals/resources';
    const TEAM_CALENDARS_PREFIX = 'principals/team-calendars';

    protected $principalBackend;
    protected $caldavBackend;
    protected $db;
    protected ?AuthTenant $authTenant = null;

    function setAuthTenant(AuthTenant $authTenant) {
        $this->authTenant = $authTenant;
    }

    function __construct(\Sabre\DAVACL\PrincipalBackend\BackendInterface $principalBackend,\Sabre\CalDAV\Backend\BackendInterface $caldavBackend, \MongoDB\Database $db) {
        $this->principalBackend = $principalBackend;
        $this->caldavBackend = $caldavBackend;
        $this->db = $db;
    }

    public function getName() {
        return \Sabre\CalDAV\Plugin::CALENDAR_ROOT;
    }

    public function getChildren() {
        //throw new \Sabre\DAV\Exception\MethodNotAllowed('Listing children in this collection has been disabled');
        $homes = [];
        $userQuery = [];
        $domainId = $this->authTenant?->domainId;
        if (!$domainId)
            throw new \Sabre\DAV\Exception\Forbidden('Cross-domain calendar access is not allowed: null $authtenant');
        $userQuery = ['domains.domain_id' => new \MongoDB\BSON\ObjectId($domainId)];
        $res = $this->db->users->find($userQuery, [ 'projection' => ['_id' => 1 ]]);
        foreach ($res as $user) {
            $principal = [ 'uri' => self::USER_PREFIX . '/' . $user['_id'] ];
            $homes[] = new CalendarHome($this->caldavBackend, $principal);
        }
        $res = $this->db->resources->find([], [ 'projection' => ['_id' => 1 ]]);
        foreach ($res as $resource) {
            $principal = [ 'uri' => self::RESOURCES_PREFIX . '/' . $resource['_id'] ];
            $homes[] = new CalendarHome($this->caldavBackend, $principal);
        }
        $res = $this->db->team_calendars->find(
            ['domainId' => new \MongoDB\BSON\ObjectId($domainId)],
            [ 'projection' => ['_id' => 1 ]]
        );
        foreach ($res as $teamCalendar) {
            $principal = [ 'uri' => self::TEAM_CALENDARS_PREFIX . '/' . $teamCalendar['_id'] ];
            $homes[] = new CalendarHome($this->caldavBackend, $principal);
        }

        return $homes;
    }

    public function getChild($name) {
        try {
            $mongoName = new \MongoDB\BSON\ObjectId($name);
        } catch (\MongoDB\Driver\Exception\Exception $e) {
            return null;
        }

        $res = $this->db->users->findOne(['_id' => $mongoName], ['projection' => ['domains.domain_id' => 1]]);
        if ($res) {
            $domainId = $this->authTenant?->domainId;
            if (!$domainId)
                throw new \Sabre\DAV\Exception\Forbidden('Cross-domain calendar access is not allowed: null $authTenant');
            if (!empty($res['domains'])) {
                $userDomainIds = array_map(fn($d) => (string)$d['domain_id'], (array)$res['domains']);
                if (!in_array($domainId, $userDomainIds, true)) {
                    throw new \Sabre\DAV\Exception\Forbidden('Cross-domain calendar access is not allowed');
                }
            }
            $principal = [ 'uri' => self::USER_PREFIX . '/' . $name ];
            return new CalendarHome($this->caldavBackend, $principal);
        }

        $res = $this->db->resources->findOne(['_id' => $mongoName], ['projection' => ['domain' => 1]]);
        if ($res) {
            $domainId = $this->authTenant?->domainId;
            if (!$domainId)
                throw new \Sabre\DAV\Exception\Forbidden('Cross-domain calendar access is not allowed: null $authTenant');
            if (isset($res['domain'])) {
                if ((string)$res['domain'] !== $domainId) {
                    throw new \Sabre\DAV\Exception\Forbidden('Cross-domain resource access is not allowed');
                }
            }
            $principal = [ 'uri' => self::RESOURCES_PREFIX . '/' . $name ];
            return new CalendarHome($this->caldavBackend, $principal);
        }

        $res = $this->db->team_calendars->findOne(['_id' => $mongoName], ['projection' => ['domainId' => 1]]);
        if ($res) {
            $domainId = $this->authTenant?->domainId;
            if (!$domainId)
                throw new \Sabre\DAV\Exception\Forbidden('Cross-domain calendar access is not allowed: null $authTenant');
            if (!isset($res['domainId']) || (string)$res['domainId'] !== $domainId) {
                throw new \Sabre\DAV\Exception\Forbidden('Cross-domain team calendar access is not allowed');
            }
            $principal = [ 'uri' => self::TEAM_CALENDARS_PREFIX . '/' . $name ];
            return new CalendarHome($this->caldavBackend, $principal);
        }

        throw new \Sabre\DAV\Exception\NotFound('Principal with name ' . $name . ' not found');
    }
}
