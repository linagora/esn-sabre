<?php

namespace ESN\CalDAV\Schedule;

use \Sabre\VObject;
use \Sabre\VObject\ITip\Message;
use \Sabre\DAV;

/**
 * IMip Callback Plugin
 *
 * This plugin provides an HTTP endpoint for the side service to call back
 * with processed IMIP messages after asynchronous processing.
 */
class IMipCallbackPlugin extends \Sabre\DAV\ServerPlugin {

    protected $server;

    function initialize(DAV\Server $server)
    {
        $this->server = $server;
        $server->on('method:IMIPCALLBACK', [$this, 'imipCallback'], 80);
    }

    function getPluginName()
    {
        return 'IMipCallbackPlugin';
    }

    /**
     * Use this method to tell the server this plugin defines additional
     * HTTP methods.
     *
     * This method is passed a uri. It should only return HTTP methods that are
     * available for the specified uri.
     *
     * @param string $path
     * @return array
     */
    function getHTTPMethods($path)
    {
        return ['IMIPCALLBACK'];
    }

    /**
     * This is the method called when the side service sends back a processed IMIP message.
     * The payload contains the serialized ITip\Message that was published to AMQP.
     */
    function imipCallback($request)
    {
        $payload = json_decode($request->getBodyAsString());

        // Validate required fields
        if (!isset($payload->sender) || !isset($payload->recipient) ||
            !isset($payload->message) || !isset($payload->method)) {
            return $this->send(400, ['error' => 'Missing required fields: sender, recipient, message, method']);
        }

        try {
            // Reconstruct the ITip\Message from the serialized payload
            $iTipMessage = new Message();
            $iTipMessage->sender = $payload->sender;
            $iTipMessage->recipient = $payload->recipient;
            $iTipMessage->method = $payload->method;
            $iTipMessage->uid = $payload->uid ?? null;
            $iTipMessage->component = $payload->component ?? 'VEVENT';
            $iTipMessage->significantChange = $payload->significantChange ?? false;
            $iTipMessage->hasChange = $payload->hasChange ?? false;

            // Parse the serialized iCalendar message
            $iTipMessage->message = VObject\Reader::read($payload->message);

            // Find the IMipPlugin and call its schedule method
            // This will handle the actual IMIP message delivery
            $imipPlugin = null;
            foreach ($this->server->getPlugins() as $plugin) {
                if ($plugin instanceof IMipPlugin) {
                    $imipPlugin = $plugin;
                    break;
                }
            }

            if (!$imipPlugin) {
                error_log('IMipCallback: IMipPlugin not found');
                return $this->send(500, ['error' => 'Server configuration error: IMipPlugin not found']);
            }

            // Call the IMipPlugin's schedule method to process the message
            $imipPlugin->schedule($iTipMessage);

            return $this->send(204, null);

        } catch (\Exception $e) {
            error_log('IMipCallback error: ' . $e->getMessage());
            return $this->send(500, ['error' => 'Failed to process IMIP message: ' . $e->getMessage()]);
        }
    }

    private function send($code, $body, $setContentType = true)
    {
        if (!isset($code)) {
            return true;
        }

        if ($body) {
            if ($setContentType) {
                $this->server->httpResponse->setHeader('Content-Type', 'application/json; charset=utf-8');
            }
            $this->server->httpResponse->setBody(json_encode($body));
        }
        $this->server->httpResponse->setStatus($code);

        return false;
    }
}
