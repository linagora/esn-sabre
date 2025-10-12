<?php

namespace ESN\DAV\Sharing;

use Sabre\DAV\Exception\Forbidden;

#[\AllowDynamicProperties]
class Plugin extends \Sabre\DAV\Sharing\Plugin {

    const ACCESS_ADMINISTRATION = 5;
    const ACCESS_FREEBUSY = 6;

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
