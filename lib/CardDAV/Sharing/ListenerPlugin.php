<?php

namespace ESN\CardDAV\Sharing;

use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;

#[\AllowDynamicProperties]
class ListenerPlugin extends ServerPlugin {

    private $carddavBackend;
    protected $server;

    function __construct($carddavBackend) {
        $this->carddavBackend = $carddavBackend;
    }

    function initialize(Server $server) {
        $this->server = $server;

        $eventEmitter = $this->carddavBackend->getEventEmitter();

        $eventEmitter->on('sabre:addressBookDeleted', [$this, 'onAddressBookDeleted']);
    }

    function onAddressBookDeleted($data) {
        $this->carddavBackend->deleteAddressBooksSharedFrom($data['addressbookid']);
    }
}
