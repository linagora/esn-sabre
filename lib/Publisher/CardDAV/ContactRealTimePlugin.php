<?php
namespace ESN\Publisher\CardDAV;

use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;
use Sabre\Uri;
use ESN\Utils\Utils as Utils;

#[\AllowDynamicProperties]
class ContactRealTimePlugin extends \ESN\Publisher\RealTimePlugin {

    protected $moved;

    private $PUBSUB_TOPICS = [
        'CONTACT_CREATED' => 'sabre:contact:created',
        'CONTACT_UPDATED' => 'sabre:contact:updated',
        'CONTACT_DELETED' => 'sabre:contact:deleted',
    ];

    function __construct($client) {
        parent::__construct($client);
    }

    function initialize(Server $server) {
        parent::initialize($server);

        $this->moved = false;

        $server->on('afterBind',          [$this, 'afterBind']);
        $server->on('beforeUnbind',        [$this, 'beforeUnbind']);
        $server->on('afterUnbind',        [$this, 'afterUnbind']);
        $server->on('afterWriteContent',  [$this, 'afterWriteContent']);
    }

    function buildData($data) {
        return $data;
    }

    function afterBind($path) {
        if (!$this->isCardPath($path)) {
            return true;
        }

        $node = $this->server->tree->getNodeForPath('/'.$path);

        if ($node instanceof \Sabre\CardDAV\Card) {
            $this->createMessage($this->PUBSUB_TOPICS['CONTACT_CREATED'], [
                'path'     => $this->ensureContactPathContainsOwnerId($path, $node->getOwner()),
                'owner'    => $node->getOwner(),
                'carddata' => $node->get()
            ]);

            $this->notifySubscribedAddressBooks('CREATED', $path, [
                'owner'    => $node->getOwner(),
                'carddata' => $node->get()
            ]);

            $this->publishMessages();
        }

        return true;
    }

    function beforeUnbind($path) {
        if (!$this->isCardPath($path)) {
            return true;
        }

        $node = $this->server->tree->getNodeForPath('/'.$path);

        $this->createMessage($this->PUBSUB_TOPICS['CONTACT_DELETED'], [
            'path'     => $this->ensureContactPathContainsOwnerId($path, $node->getOwner()),
            'owner'    => $node->getOwner(),
            'carddata' => $node->get()
        ]);
    }

    function afterUnbind($path) {
        if (!$this->isCardPath($path)) {
            return true;
        }

        $this->createMessage(
            $this->PUBSUB_TOPICS['CONTACT_DELETED'],
            [
                'path' => $path
            ]
        );

        $this->notifySubscribedAddressBooks('DELETED', $path);

        $this->publishMessages();

        return true;
    }

    function afterWriteContent($path, \Sabre\DAV\IFile $node) {
        if ($node instanceof \Sabre\CardDAV\Card) {
            $this->createMessage(
                $this->PUBSUB_TOPICS['CONTACT_UPDATED'],
                [
                    'path'     => $this->ensureContactPathContainsOwnerId($path, $node->getOwner()),
                    'owner'    => $node->getOwner(),
                    'carddata' => $node->get()
                ]
            );

            $this->notifySubscribedAddressBooks('UPDATED', $path, [
                'owner'    => $node->getOwner(),
                'carddata' => $node->get()
            ]);

            $this->publishMessages();
        }

        return true;
    }

    private function notifySubscribedAddressBooks($action, $cardPath, $dataMessage = []) {
        list($parentUri) = Uri\split($cardPath);
        $parent = $this->server->tree->getNodeForPath('/'.$parentUri);
        $subscribedAddressBooks = $parent->getSubscribedAddressBooks();

        foreach ($subscribedAddressBooks as $addressBook) {
            $principalUriExploded = explode('/', $addressBook['principaluri']);
            $cardPathExploded = explode('/', $cardPath);
            $dataMessage['path'] = 'addressbooks/'.$principalUriExploded[2].'/'.$addressBook['uri'].'/'.$cardPathExploded[3];
            $dataMessage['sourcePath'] = $cardPath;

            $this->createMessage($this->PUBSUB_TOPICS['CONTACT_'.$action], $dataMessage);
        }
    }

    private function isCardPath($path) {
        return preg_match('/^addressbooks\/.*?\.vcf$/', $path);
    }

    private function ensureContactPathContainsOwnerId($path, $ownerPrincipal) {
        $pathExploded = explode('/', $path);
        $ownerPrincipalExploded = explode('/', $ownerPrincipal);

        $pathExploded[1] = $ownerPrincipalExploded[2];

        return join('/', $pathExploded);
    }
}
