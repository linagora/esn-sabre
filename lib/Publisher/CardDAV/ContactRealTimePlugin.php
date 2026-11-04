<?php
namespace ESN\Publisher\CardDAV;

use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;
use Sabre\Uri;
use ESN\Utils\Utils as Utils;

#[\AllowDynamicProperties]
class ContactRealTimePlugin extends \ESN\Publisher\RealTimePlugin {

    protected $moved;

    /**
     * Message data of the cards being deleted, by path: a card can no longer be read once deleted, while the sync
     * token is only known after that.
     */
    private $deletedCards = [];

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
            $this->publishCardChange('CREATED', $path, $node);
        }

        return true;
    }

    function beforeUnbind($path) {
        if (!$this->isCardPath($path)) {
            return true;
        }

        $node = $this->server->tree->getNodeForPath('/'.$path);

        $this->deletedCards[$path] = [
            'path'     => $this->ensureContactPathContainsOwnerId($path, $node->getOwner()),
            'owner'    => $node->getOwner(),
            'carddata' => $node->get(),
            'etag'     => $node->getETag()
        ];
    }

    function afterUnbind($path) {
        if (!$this->isCardPath($path)) {
            return true;
        }

        $deletedCard = $this->deletedCards[$path];
        unset($this->deletedCards[$path]);

        $addressBook = $this->reloadAddressBook($path);
        $revision = [
            'syncToken' => $this->syncToken($addressBook),
            'etag'      => $deletedCard['etag']
        ];

        $this->createMessage($this->PUBSUB_TOPICS['CONTACT_DELETED'], array_merge($deletedCard, $revision));

        $this->createMessage(
            $this->PUBSUB_TOPICS['CONTACT_DELETED'],
            array_merge(['path' => $path], $revision)
        );

        $this->notifySubscribedAddressBooks($addressBook, 'DELETED', $path, $revision);

        $this->publishMessages();

        return true;
    }

    function afterWriteContent($path, \Sabre\DAV\IFile $node) {
        if ($node instanceof \Sabre\CardDAV\Card) {
            $this->publishCardChange('UPDATED', $path, $node);
        }

        return true;
    }

    private function publishCardChange($action, $path, $node) {
        $addressBook = $this->reloadAddressBook($path);
        $dataMessage = [
            'owner'     => $node->getOwner(),
            'carddata'  => $node->get(),
            'syncToken' => $this->syncToken($addressBook),
            'etag'      => $node->getETag()
        ];

        $this->createMessage(
            $this->PUBSUB_TOPICS['CONTACT_'.$action],
            array_merge(['path' => $this->ensureContactPathContainsOwnerId($path, $node->getOwner())], $dataMessage)
        );

        $this->notifySubscribedAddressBooks($addressBook, $action, $path, $dataMessage);

        $this->publishMessages();
    }

    /**
     * The address book node holds the sync token read when it was built, and may have been cached before the write.
     */
    private function reloadAddressBook($cardPath) {
        list($parentUri) = Uri\split($cardPath);
        $this->server->tree->markDirty($parentUri);

        return $this->server->tree->getNodeForPath('/'.$parentUri);
    }

    private function syncToken($addressBook) {
        $syncToken = $addressBook->getSyncToken();

        return $syncToken === null ? null : (int) $syncToken;
    }

    private function notifySubscribedAddressBooks($parent, $action, $cardPath, $dataMessage = []) {
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
