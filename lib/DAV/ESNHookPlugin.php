<?php
namespace ESN\DAV;

use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;
use \Sabre\HTTP;

class ESNHookPlugin extends ServerPlugin {
    protected $server;
    private $httpClient;
    private $request;

    const ESN_BASE_URI = 'http://localhost:8080';

    function initialize(Server $server) {
        $this->server = $server;
        $server->on('beforeCreateFile',   [$this, 'beforeCreateFile']);
        $server->on('afterCreateFile',    [$this, 'afterCreateFile']);
        $server->on('beforeWriteContent', [$this, 'beforeWriteContent']);
        $server->on('afterWriteContent',  [$this, 'afterWriteContent']);
        $this->httpClient = new HTTP\Client();
    }

    function beforeCreateFile($path, &$data, \Sabre\DAV\ICollection $parent, &$modified) {
        error_log('beforeCreateFile');

        $community_id = $this->getCommunityIdFrom($parent->getOwner());

        $bodyAsArray = [ 'event_id' => '/'.$path, 'type' => 'created', 'event' => $data ];
        $body = json_encode($bodyAsArray);

        $this->createRequest($community_id, $body);

        return true;
    }

    function afterCreateFile($path, \Sabre\DAV\ICollection $parent) {
        error_log('afterCreateFile');
        error_log(print_r((string)$this->request, true));
        $this->sendAsync($this->request);

        return true;
    }

    function beforeWriteContent($path, \Sabre\DAV\IFile $node, &$data, &$modified) {
        error_log('beforeWriteContent');

        $community_id = $this->getCommunityIdFrom($node->getOwner());
        $old_event = $node->get();

        $bodyAsArray = [ 'event_id' => '/'.$path, 'type' => 'updated', 'event' => $data, 'old_event' => $old_event ];
        $body = json_encode($bodyAsArray);

        $this->createRequest($community_id, $body);

        return true;
    }

    function afterWriteContent($path, \Sabre\DAV\IFile $node) {
        error_log('afterWriteContent');
        error_log(print_r((string)$this->request, true));
        $this->sendAsync($this->request);

        return true;
    }

    private function sendAsync($request) {
      $this->httpClient->sendAsync(
          $request,
          function (ResponseInterface $response) {
            error_log('success');
            error_log(print_r($response->getBodyAsString(), true));
          },
          function($error) {
            error_log('error');
            error_log(print_r($error, true));
          }
      );
    }

    private function createRequest($community_id, $body) {
      $url = self::ESN_BASE_URI.'/api/calendars/'.$community_id.'/events';
      $this->request = new HTTP\Request('POST', $url);
      $this->request->setHeader('Content-type', 'application/json');
      $this->request->setHeader('Content-length', strlen($body));
      $this->request->setBody($body);
    }

    private function getCommunityIdFrom($principaluri) {
      $array = explode('/', $principaluri);
      $community_id = array_pop($array);
      return $community_id;
    }
}
