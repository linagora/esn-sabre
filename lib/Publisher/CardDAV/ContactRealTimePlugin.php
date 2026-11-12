<?php
namespace ESN\Publisher\CardDAV;

use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;
use Sabre\Uri;
use ESN\CardDAV\Sharing\SharedAddressBook;
use ESN\CardDAV\Subscriptions\Subscription;
use ESN\Utils\Utils as Utils;

#[\AllowDynamicProperties]
class ContactRealTimePlugin extends \ESN\Publisher\RealTimePlugin {

    protected $moved;

    /**
     * Message data of the cards being deleted, by path: a card can no longer be read once deleted, while the sync
     * token is only known after that.
     */
    private $deletedCards = [];

    /**
     * Card data before an update, by path: consumers keeping derived data (e.g. auto-complete indexes) need it to
     * drop what the update removed from the card.
     */
    private $previousCards = [];

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
        $server->on('beforeWriteContent', [$this, 'beforeWriteContent']);
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
            $this->publishCardChange('CREATED', $path, $node->get(), $node->getETag());
        }

        return true;
    }

    function beforeUnbind($path) {
        if (!$this->isCardPath($path)) {
            return true;
        }

        $node = $this->server->tree->getNodeForPath('/'.$path);

        $this->deletedCards[$path] = [
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

        $this->publishCardChange('DELETED', $path, $deletedCard['carddata'], $deletedCard['etag']);

        return true;
    }

    function beforeWriteContent($path, \Sabre\DAV\IFile $node) {
        if ($node instanceof \Sabre\CardDAV\Card) {
            $this->previousCards[$path] = $node->get();
        }

        return true;
    }

    function afterWriteContent($path, \Sabre\DAV\IFile $node) {
        if ($node instanceof \Sabre\CardDAV\Card) {
            $previousCard = $this->previousCards[$path] ?? null;
            unset($this->previousCards[$path]);

            $this->publishCardChange('UPDATED', $path, $node->get(), $node->getETag(),
                $previousCard === null ? [] : ['oldcarddata' => $previousCard]);
        }

        return true;
    }

    /**
     * A card written through a subscribed or delegated address book belongs to the source address book: whichever
     * way it was written, one message is published for the source, and one for each address book mirroring it.
     */
    private function publishCardChange($action, $path, $carddata, $etag, $extraData = []) {
        list($addressBookPath, $cardName) = Uri\split($path);
        $addressBook = $this->reloadAddressBook($addressBookPath);

        if ($addressBook instanceof SharedAddressBook || $addressBook instanceof Subscription) {
            $addressBookPath = $addressBook->getShareResourceUri();
            $addressBook = $this->reloadAddressBook($addressBookPath);
        }

        $topic = $this->PUBSUB_TOPICS['CONTACT_'.$action];
        $sourcePath = $addressBookPath.'/'.$cardName;
        $cardMessage = [
            'carddata'  => $carddata,
            'syncToken' => $this->syncToken($addressBook),
            'etag'      => $etag
        ] + $extraData;

        $this->createMessage($topic, ['path' => $sourcePath, 'owner' => $addressBook->getOwner()] + $cardMessage);

        foreach ($addressBook->getSubscribedAddressBooks() as $mirror) {
            $principalUriExploded = explode('/', $mirror['principaluri']);

            $this->createMessage($topic, [
                'path'       => 'addressbooks/'.$principalUriExploded[2].'/'.$mirror['uri'].'/'.$cardName,
                'sourcePath' => $sourcePath,
                'owner'      => $mirror['principaluri']
            ] + $cardMessage);
        }

        $this->publishMessages();
    }

    /**
     * The address book node holds the sync token read when it was built, and may have been cached before the write.
     */
    private function reloadAddressBook($addressBookPath) {
        $this->server->tree->markDirty($addressBookPath);

        return $this->server->tree->getNodeForPath('/'.$addressBookPath);
    }

    private function syncToken($addressBook) {
        $syncToken = $addressBook->getSyncToken();

        return $syncToken === null ? null : (int) $syncToken;
    }

    private function isCardPath($path) {
        return preg_match('/^addressbooks\/.*?\.vcf$/', $path);
    }
}
