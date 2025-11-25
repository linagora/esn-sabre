<?php
namespace ESN\Publisher;

use \PhpAmqpLib\Connection\AMQPStreamConnection;
use \PhpAmqpLib\Connection\AMQPSSLConnection;
use \PhpAmqpLib\Message\AMQPMessage;

/**
 * Lazy AMQP Publisher
 *
 * Opens connection ONLY when publish() is called (lazy loading)
 * This avoids opening connections for read-only requests
 */
class AMQPPublisher {

    protected $config;
    protected $connection = null;
    protected $channel = null;

    function __construct($amqpConfig) {
        $this->config = $amqpConfig;

        if (empty($this->config) || empty($this->config['host'])) {
            error_log('AMQPPublisher: No AMQP configuration provided');
        }
    }

    /**
     * Get or create AMQP channel (lazy loading)
     */
    private function getChannel() {
        if ($this->channel !== null) {
            return $this->channel;
        }

        // No config, return null
        if (empty($this->config) || empty($this->config['host'])) {
            return null;
        }

        // Create connection
        $login = !empty($this->config['login']) ? $this->config['login'] : 'guest';
        $password = !empty($this->config['password']) ? $this->config['password'] : 'guest';
        $vhost = !empty($this->config['vhost']) ? $this->config['vhost'] : '/';
        $sslEnabled = !empty($this->config['sslEnabled']) ? $this->config['sslEnabled'] : false;
        $sslTrustAll = !empty($this->config['sslTrustAllCerts']) ? $this->config['sslTrustAllCerts'] : false;

        try {
            if ($sslEnabled) {
                $sslOptions = [];
                if ($sslTrustAll) {
                    $sslOptions = [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true
                    ];
                }
                $this->connection = new AMQPSSLConnection(
                    $this->config['host'],
                    $this->config['port'],
                    $login,
                    $password,
                    $vhost,
                    $sslOptions
                );
            } else {
                $this->connection = new AMQPStreamConnection(
                    $this->config['host'],
                    $this->config['port'],
                    $login,
                    $password,
                    $vhost
                );
            }

            $this->channel = $this->connection->channel();

        } catch (\Exception $e) {
            error_log('AMQPPublisher: Failed to connect to AMQP: ' . $e->getMessage());
            return null;
        }

        return $this->channel;
    }

    /**
     * Publish message (opens connection lazily if needed)
     */
    function publish($topic, $message) {
        $channel = $this->getChannel();

        if (!$channel) {
            error_log('AMQPPublisher: Cannot publish, no channel available');
            return;
        }

        try {
            $msg = new AMQPMessage($message);
            $channel->basic_publish($msg, $topic);
        } catch (\Exception $e) {
            error_log('AMQPPublisher: Failed to publish message: ' . $e->getMessage());
        }
    }

    /**
     * Close connection at end of request (optional, PHP will close anyway)
     */
    function __destruct() {
        if ($this->channel !== null) {
            try {
                $this->channel->close();
            } catch (\Exception $e) {
                // Ignore close errors
            }
        }

        if ($this->connection !== null) {
            try {
                $this->connection->close();
            } catch (\Exception $e) {
                // Ignore close errors
            }
        }
    }
}
