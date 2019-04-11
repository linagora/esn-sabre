<?php

namespace ESN\Log;

use \Sabre\DAV;

class ExceptionLoggerPlugin extends DAV\ServerPlugin
{
    protected $logger;
    protected $server;

    public function __construct($logger) {
        $this->logger = $logger;
    }

    function initialize(DAV\Server $server) {
        $this->server = $server;

        if (isset($this->logger)) {
            $server->setLogger($this->logger);
        }

        $server->removeAllListeners('exception');
        $server->on('exception', [$this, 'exception']);
    }

    /**
     * Listens for exception events, and automatically logs them.
     *
     * @param Exception $e
     */
    function exception($e)
    {
        $logLevel = \Psr\Log\LogLevel::CRITICAL;
        if ($e instanceof \Sabre\DAV\Exception) {
            // If it's a standard sabre/dav exception, it means we have a http
            // status code available.
            $code = $e->getHTTPCode();

            if ($code >= 400 && $code < 500) {
                // user error
                $logLevel = \Psr\Log\LogLevel::INFO;
            } else {
                // Server-side error. We mark it's as an error, but it's not
                // critical.
                $logLevel = \Psr\Log\LogLevel::ERROR;
            }
        }

        $this->server->getLogger()->log(
            $logLevel,
            'Uncaught exception',
            [
                'exception' => $e,
            ]
        );
    }

    function getPluginName() {
        return "exceptionLogger";
    }

    function getPluginInfo() {
        return [
            'name'        => $this->getPluginName(),
            'description' => 'Log exception to PSR log.'
        ];
    }
}
