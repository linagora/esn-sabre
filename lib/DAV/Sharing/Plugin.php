<?php

namespace ESN\DAV\Sharing;

use Sabre\DAV\Exception\Forbidden;

class Plugin extends \Sabre\DAV\Sharing\Plugin {

    const ACCESS_ADMINISTRATION = 5;
    const ACCESS_FREEBUSY = 6;


    /**
     * Updates the list of sharees on a shared resource.
     *
     * The sharees  array is a list of people that are to be added modified
     * or removed in the list of shares.
     *
     * @param string $path
     * @param Sharee[] $sharees
     * @return void
     */
    function shareResource($path, array $sharees) {

        $node = $this->server->tree->getNodeForPath($path);

        if (!$node instanceof \ESN\CalDAV\SharedCalendar) {

            throw new Forbidden('Sharing is not allowed on this node');

        }

        // Getting ACL info
        $acl = $this->server->getPlugin('acl');

        // If there's no ACL support, we allow everything
        if ($acl) {
            $acl->checkPrivileges($path, '{DAV:}write-acl');
        }

        foreach ($sharees as $sharee) {
            // We're going to attempt to get a local principal uri for a share
            // href by emitting the getPrincipalByUri event.
            $principal = null;
            $this->server->emit('getPrincipalByUri', [$sharee->href, &$principal]);
            $sharee->principal = $principal;
        }

        $node->updateInvites($sharees);
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