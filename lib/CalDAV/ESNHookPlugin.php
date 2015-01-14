<?php
namespace ESN\CalDAV;

use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;
use \Sabre\HTTP;
use \Sabre\HTTP\RequestInterface;
use \Sabre\HTTP\ResponseInterface;

class ESNHookPlugin extends ServerPlugin {
    protected $server;
    protected $httpClient;
    protected $apiroot;
    protected $request;


    private $PRINCIPAL_TO_COLLABORATION = [
      'communities' => 'community',
      'projects' => 'project'
    ];

    function __construct($apiroot, $authBackend) {
        $this->apiroot = $apiroot;
        $this->authBackend = $authBackend;
    }

    function initialize(Server $server) {
        $this->server = $server;

        $server->on('beforeCreateFile',   [$this, 'beforeCreateFile']);
        $server->on('afterCreateFile',    [$this, 'after']);

        $server->on('beforeWriteContent', [$this, 'beforeWriteContent']);
        $server->on('afterWriteContent',  [$this, 'after']);

        $server->on('beforeUnbind',       [$this, 'beforeUnbind']);
        $server->on('afterUnbind',        [$this, 'after']);

        // Make sure the message id header is exposed to XHR
        $corsPlugin = $this->server->getPlugin("cors");
        if (!is_null($corsPlugin)) {
            $corsPlugin->exposeHeaders[] = 'ESN-Message-Id';
        }

        $this->httpClient = new HTTP\Client();
    }

    function after($path) {
        $this->sendRequest();
        return true;
    }

    function beforeUnbind($path) {
        $node = $this->server->tree->getNodeForPath($path);
        if (!($node instanceof \Sabre\CalDAV\CalendarObject)) {
            return true;
        }


        $body = json_encode([
            'event_id' => '/' . $path,
            'type' => 'deleted',
            'event' => $node->get()
        ]);

        $this->createRequest($node->getOwner(), $path, $body);
        return true;
    }

    function beforeCreateFile($path, &$data, \Sabre\DAV\ICollection $parent, &$modified) {
        if (!($parent instanceof \Sabre\CalDAV\Calendar)) {
            return true;
        }

        $body = json_encode([
            'event_id' => '/' . $path,
            'type' => 'created',
            'event' => $data
        ]);

        $this->createRequest($parent->getOwner(), $path, $body);
        return true;
    }

    function beforeWriteContent($path, \Sabre\DAV\IFile $node, &$data, &$modified) {
        if (!($node instanceof \Sabre\CalDAV\CalendarObject)) {
            return true;
        }

        $body = json_encode([
            'event_id' => '/' .$path,
            'type' => 'updated',
            'event' => $data,
            'old_event' => $node->get()
        ]);

        $this->createRequest($node->getOwner(), $path, $body);
        return true;
    }

    protected function createRequest($owner, $path, $body) {
        $parts = $this->getCollaborationFromPaths($owner, $path);
        if (!$parts) {
            return null;
        }
        list($collaborationType, $collaborationId) = $parts;

        $url = $this->apiroot.'/calendars/'.$collaborationType.'/'.$collaborationId.'/events';
        $this->request = new HTTP\Request('POST', $url);
        $this->request->setHeader('Content-type', 'application/json');
        $this->request->setHeader('Content-length', strlen($body));

        $cookie = $this->authBackend->getAuthCookies();
        $this->request->setHeader('Cookie', $cookie);

        $this->request->setBody($body);
        return $this->request;
    }

    protected function sendRequest() {
        if ($this->request) {
            $response = $this->httpClient->send($this->request);
            $json = json_decode($response->getBodyAsString());
            if (!is_null($json) && isset($json->{'_id'})) {
                $this->server->httpResponse->setHeader("ESN-Message-Id", $json->{'_id'});
            }
        }
    }

    private function getCollaborationFromPaths($owner, $uri) {
        $ownerParts = explode('/', $owner);
        if (count($ownerParts) != 3 || $ownerParts[0] != "principals") {
            return null;
        }

        $parts = explode('/', $uri);
        $partCount = count($parts);
        if (($partCount != 3 && $partCount != 4) || $parts[0] != 'calendars' ||
            !isset($this->PRINCIPAL_TO_COLLABORATION[$ownerParts[1]])) {
            return null;
        }

        return array($this->PRINCIPAL_TO_COLLABORATION[$ownerParts[1]], $parts[1]);
    }
}
