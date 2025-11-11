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
    protected $authBackend;

    function initialize(DAV\Server $server)
    {
        $this->server = $server;
        $this->authBackend = $server->getPlugin('auth');
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
     * Requires basic authentication - accepts requests from any users that exist in sabre.
     */
    function imipCallback($request)
    {
        // Store body string first before any other operation that might consume the stream
        $bodyString = $request->getBodyAsString();

        // Check authentication using the auth backend
        if (!$this->authBackend) {
            error_log('IMipCallback: Auth backend not found');
            return $this->send(500, ['error' => 'Server configuration error: Auth backend not found']);
        }

        list($authenticated, $principal) = $this->authBackend->check($request, $this->server->httpResponse);

        if (!$authenticated) {
            $this->server->httpResponse->setHeader('WWW-Authenticate', 'Basic realm="SabreDAV"');
            return $this->send(401, ['error' => 'Authentication required']);
        }

        // Decode the stored body string
        $payload = json_decode($bodyString);

        if ($payload === null && json_last_error() !== JSON_ERROR_NONE) {
            return $this->send(400, ['error' => 'Invalid JSON: ' . json_last_error_msg()]);
        }

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

            // Find the Schedule\Plugin and call its deliverSync method
            // This will call parent::deliver() to process the message synchronously
            $schedulePlugin = null;
            foreach ($this->server->getPlugins() as $plugin) {
                if ($plugin instanceof Plugin) {
                    $schedulePlugin = $plugin;
                    break;
                }
            }

            if (!$schedulePlugin) {
                error_log('IMipCallback: Schedule Plugin not found');
                return $this->send(500, ['error' => 'Server configuration error: Schedule Plugin not found']);
            }

            // Call deliverSync to bypass async logic and deliver synchronously
            $schedulePlugin->deliverSync($iTipMessage);

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
