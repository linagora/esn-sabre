<?php

namespace ESN\DAV\Sharing;

use Sabre\DAV\Exception\Forbidden;
use \ESN\Utils\AuthTenant;

#[\AllowDynamicProperties]
class Plugin extends \Sabre\DAV\Sharing\Plugin {

    const ACCESS_ADMINISTRATION = 5;
    const ACCESS_FREEBUSY = 6;

    protected ?AuthTenant $authTenant = null;
    protected $esnDb;

    function initialize(\Sabre\DAV\Server $server) {
        parent::initialize($server);
        $server->on('auth:success',
                    function(AuthTenant $authTenant) {
                        $this->authTenant = $authTenant;
                    });
    }

    function __construct(\MongoDB\Database $esnDb = null) {
        $this->esnDb = $esnDb;
    }

    function shareResource($path, array $sharees) {
        $domainId = $this->authTenant?->domainId;
        if(!$domainId)
            throw new \Sabre\DAV\Exception\Forbidden('Cross-domain calendar access is not allowed: null $authTenant');
        if ($this->esnDb !== null) {
            foreach ($sharees as $sharee) {
                if ($sharee->access === self::ACCESS_NOACCESS) {
                    continue;
                }
                if (!$sharee->href || !str_starts_with($sharee->href, 'mailto:')) {
                    continue;
                }
                $email = substr($sharee->href, 7);
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                $emailDomain = explode('@', $email)[1];
                $domain = $this->esnDb->domains->findOne(
                    ['_id' => new \MongoDB\BSON\ObjectId($domainId), 'name' => $emailDomain],
                    ['projection' => ['_id' => 1]]
                );
                if (!$domain) {
                    throw new \Sabre\DAV\Exception\Forbidden('Cross-domain delegation is not allowed');
                }
            }
        }
        parent::shareResource($path, $sharees);
    }

    function accessToRightRse($access) {
        switch($access) {
            case $this::ACCESS_ADMINISTRATION:
                return "dav:administration";
                break;
            case $this::ACCESS_READWRITE:
                return "dav:read-write";
                    break;
            case $this::ACCESS_READ:
                return "dav:read";
                break;
            case $this::ACCESS_FREEBUSY:
                return "dav:freebusy";
                break;
            case $this::ACCESS_NOACCESS:
                return "";
                break;
            case $this::ACCESS_SHAREDOWNER:
                return "dav:shareer";
                break;
            default:
                return "";
                break;
        }
    }

    function rightRseToAccess($right) {
        switch($right) {
            case "dav:administration":
                return $this::ACCESS_ADMINISTRATION;
                break;
            case "dav:read-write":
                return $this::ACCESS_READWRITE;
                break;
            case "dav:read":
                return $this::ACCESS_READ;
                break;
            case "dav:freebusy":
                return $this::ACCESS_FREEBUSY;
                break;
            case "dav:shareer":
                return $this::ACCESS_SHAREDOWNER;
                break;
            default:
                break;
        }
    }
}
