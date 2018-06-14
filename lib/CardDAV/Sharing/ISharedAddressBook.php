<?php

namespace ESN\CardDAV\Sharing;

use Sabre\DAV\Sharing\ISharedNode;

interface ISharedAddressBook extends ISharedNode {
    function getInviteStatus();
    function replyInvite($inviteStatus, $options);
    function getShareOwner();
    function setPublishStatus($value);
}
