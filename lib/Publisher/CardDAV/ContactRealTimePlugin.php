<?php
namespace ESN\Publisher\CardDAV;

use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;
use Sabre\Uri;

class ContactRealTimePlugin extends \ESN\Publisher\RealTimePlugin {

    private $PUBSUB_TOPICS = [
        'CONTACT_CREATED' => 'sabre:contact:created',
        'CONTACT_UPDATED' => 'sabre:contact:updated',
        'CONTACT_MOVED'   => 'sabre:contact:moved',
        'CONTACT_DELETED' => 'sabre:contact:deleted',
    ];

    private $moved;

    function __construct($client) {
        parent::__construct($client);
    }

    function initialize(Server $server) {
        parent::initialize($server);

        $this->moved = false;

        $server->on('afterCreateFile',    [$this, 'afterCreateFile']);
        $server->on('afterWriteContent',  [$this, 'afterWriteContent']);
        $server->on('afterMove',          [$this, 'afterMove']);
        $server->on('afterUnbind',        [$this, 'afterUnbind']);
    }

    function buildData($data) {
        return $data;
    }

    function afterCreateFile($path) {
        if (!$this->isCardPath($path)) {
            return true;
        }

        $node = $this->server->tree->getNodeForPath('/'.$path);

        if ($node instanceof \Sabre\CardDAV\Card) {
            $this->createMessage(
                $this->PUBSUB_TOPICS['CONTACT_CREATED'],
                [
                    'path' => $path,
                    'owner' => $node->getOwner(),
                    'carddata' => $node->get()
                ]
            );
            $this->publishMessages();
        }

        return true;
    }

    function afterWriteContent($path, \Sabre\DAV\IFile $node) {
        if ($node instanceof \Sabre\CardDAV\Card) {
            $this->createMessage(
                $this->PUBSUB_TOPICS['CONTACT_UPDATED'],
                [
                    'path' => $path,
                    'owner' => $node->getOwner(),
                    'carddata' => $node->get()
                ]
            );
            $this->publishMessages();
        }
        return true;
    }

    function afterMove($path, $toPath) {
        if (!$this->isCardPath($path)) {
            return true;
        }

        $node = $this->server->tree->getNodeForPath('/'.$toPath);

        $this->createMessage(
            $this->PUBSUB_TOPICS['CONTACT_MOVED'],
            [
                'path' => $path,
                'toPath' => $toPath,
                'owner' => $node->getOwner(),
                'carddata' => $node->get()
            ]
        );
        $this->publishMessages();

        $this->moved = true;

        return true;
    }

    function afterUnbind($path) {
        if (!$this->isCardPath($path)) {
            return true;
        }

        if ($this->moved) { // ignore unbind event when contact is moved
            return true;
        }

        $this->createMessage(
            $this->PUBSUB_TOPICS['CONTACT_DELETED'],
            [
                'path' => $path
            ]
        );
        $this->publishMessages();

        return true;
    }

    private function isCardPath($path) {
        return preg_match('/^addressbooks\/.*?\.vcf$/', $path);
    }
}
