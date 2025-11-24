<?php

namespace ESN\Utils;

use \PhpAmqpLib\Connection\AMQPStreamConnection;
use \PhpAmqpLib\Connection\AMQPSSLConnection;
use \MongoDB\Client as MongoClient;

/**
 * Persistent connection manager for MongoDB and AMQP
 *
 * MongoDB: Fully persistent clients (thread-safe, handles pooling)
 * AMQP: Persistent connection, but channels created per-request for safety
 */
class ConnectionManager {
    private static $instance = null;

    private $config = null;
    private $esnMongoClient = null;
    private $sabreMongoClient = null;
    private $amqpConnection = null;
    private $esnDb = null;
    private $sabreDb = null;

    private function __construct() {
        // Singleton pattern
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Load and cache configuration from file and APCu
     */
    public function getConfig() {
        if ($this->config !== null) {
            return $this->config;
        }

        // Try to load from APCu cache
        $cacheKey = 'esn_sabre_config';
        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch($cacheKey, $success);
            if ($success) {
                $this->config = $cached;
                return $this->config;
            }
        }

        // Load from file
        $configPath = defined('CONFIG_PATH') ? CONFIG_PATH : 'config.json';
        $this->config = json_decode(file_get_contents($configPath), true);

        if (!$this->config) {
            throw new \Exception("Could not load config.json from " . realpath($configPath) . ", Error " . json_last_error());
        }

        // Cache for 300 seconds (5 minutes)
        if (function_exists('apcu_store')) {
            apcu_store($cacheKey, $this->config, 300);
        }

        return $this->config;
    }

    /**
     * Get ESN MongoDB client (persistent connection with pooling)
     */
    public function getEsnMongoClient() {
        if ($this->esnMongoClient !== null) {
            return $this->esnMongoClient;
        }

        $config = $this->getConfig();
        $dbConfig = $config['database'];
        $connectionString = $dbConfig['esn']['connectionString'];

        // Optimized connection options for pooling
        $options = array_merge(
            $dbConfig['esn']['connectionOptions'] ?? [],
            [
                'maxPoolSize' => 50,
                'minPoolSize' => 5,
                'maxIdleTimeMS' => 60000,
                'serverSelectionTimeoutMS' => 5000,
            ]
        );

        $this->esnMongoClient = new MongoClient($connectionString, $options);

        return $this->esnMongoClient;
    }

    /**
     * Get Sabre MongoDB client (persistent connection with pooling)
     */
    public function getSabreMongoClient() {
        if ($this->sabreMongoClient !== null) {
            return $this->sabreMongoClient;
        }

        $config = $this->getConfig();
        $dbConfig = $config['database'];
        $connectionString = $dbConfig['sabre']['connectionString'];

        // Optimized connection options for pooling
        $options = array_merge(
            $dbConfig['sabre']['connectionOptions'] ?? [],
            [
                'maxPoolSize' => 50,
                'minPoolSize' => 5,
                'maxIdleTimeMS' => 60000,
                'serverSelectionTimeoutMS' => 5000,
            ]
        );

        $this->sabreMongoClient = new MongoClient($connectionString, $options);

        return $this->sabreMongoClient;
    }

    /**
     * Get ESN database
     */
    public function getEsnDb() {
        if ($this->esnDb !== null) {
            return $this->esnDb;
        }

        $config = $this->getConfig();
        $dbConfig = $config['database'];
        $client = $this->getEsnMongoClient();
        $connectionString = $dbConfig['esn']['connectionString'];

        $dbName = $this->getDatabaseName("esn", $connectionString, $dbConfig);
        if (!$dbName) {
            throw new \Exception("Unable to get ESN database name from configuration");
        }

        $this->esnDb = $client->{$dbName};
        return $this->esnDb;
    }

    /**
     * Get Sabre database
     */
    public function getSabreDb() {
        if ($this->sabreDb !== null) {
            return $this->sabreDb;
        }

        $config = $this->getConfig();
        $dbConfig = $config['database'];
        $client = $this->getSabreMongoClient();
        $connectionString = $dbConfig['sabre']['connectionString'];

        $dbName = $this->getDatabaseName("sabre", $connectionString, $dbConfig);
        if (!$dbName) {
            throw new \Exception("Unable to get SABRE database name from configuration");
        }

        $this->sabreDb = $client->{$dbName};
        return $this->sabreDb;
    }

    /**
     * Get persistent AMQP connection (reused across requests in same worker)
     *
     * IMPORTANT: Do NOT share channels! Call createAmqpChannel() for each request.
     */
    private function getAmqpConnection() {
        if ($this->amqpConnection !== null) {
            // Check if connection is still alive
            try {
                if ($this->amqpConnection->isConnected()) {
                    return $this->amqpConnection;
                }
            } catch (\Exception $e) {
                // Connection is dead, recreate it
                $this->amqpConnection = null;
            }
        }

        $config = $this->getConfig();

        if (empty($config['amqp']['host'])) {
            return null;
        }

        $amqpLogin = !empty($config['amqp']['login']) ? $config['amqp']['login'] : 'guest';
        $amqpPassword = !empty($config['amqp']['password']) ? $config['amqp']['password'] : 'guest';
        $amqpVhost = !empty($config['amqp']['vhost']) ? $config['amqp']['vhost'] : '/';
        $amqpSslEnabled = !empty($config['amqp']['sslEnabled']) ? $config['amqp']['sslEnabled'] : false;
        $amqpSslTrustAll = !empty($config['amqp']['sslTrustAllCerts']) ? $config['amqp']['sslTrustAllCerts'] : false;

        $connectionOptions = [
            'keepalive' => true,
            'heartbeat' => 30,
            'connection_timeout' => 5.0,
            'read_write_timeout' => 60.0,
        ];

        if ($amqpSslEnabled) {
            $sslOptions = [];
            if ($amqpSslTrustAll) {
                $sslOptions = [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ];
            }

            $this->amqpConnection = new AMQPSSLConnection(
                $config['amqp']['host'],
                $config['amqp']['port'],
                $amqpLogin,
                $amqpPassword,
                $amqpVhost,
                $sslOptions,
                $connectionOptions
            );
        } else {
            $this->amqpConnection = new AMQPStreamConnection(
                $config['amqp']['host'],
                $config['amqp']['port'],
                $amqpLogin,
                $amqpPassword,
                $amqpVhost,
                false, // insist
                'AMQPLAIN', // login_method
                null, // login_response
                'en_US', // locale
                $connectionOptions['connection_timeout'],
                $connectionOptions['read_write_timeout'],
                null, // context
                $connectionOptions['keepalive'],
                $connectionOptions['heartbeat']
            );
        }

        return $this->amqpConnection;
    }

    /**
     * Create a new AMQP channel for the current request
     *
     * SAFE for concurrent use: Each request gets its own channel
     * The underlying connection is reused (persistent) for performance
     *
     * @return \PhpAmqpLib\Channel\AMQPChannel|null
     */
    public function createAmqpChannel() {
        $connection = $this->getAmqpConnection();

        if ($connection === null) {
            return null;
        }

        return $connection->channel();
    }

    /**
     * Check if AMQP is configured
     */
    public function hasAmqp() {
        $config = $this->getConfig();
        return !empty($config['amqp']['host']);
    }

    /**
     * Extract database name from connection string
     */
    private function getDatabaseName($dbKind, $connectionString, $dbConfig) {
        $parsedUrl = parse_url($connectionString);
        $pathSegments = explode("/", $parsedUrl['path']);
        $lastSegment = array_pop($pathSegments);
        if ($lastSegment) {
            return $lastSegment;
        } else {
            // support old style database name
            return $dbConfig[$dbKind]['db'];
        }
    }

    /**
     * Close all connections (called at end of worker lifecycle if needed)
     */
    public function closeConnections() {
        if ($this->amqpConnection !== null) {
            try {
                $this->amqpConnection->close();
            } catch (\Exception $e) {
                // Ignore closing errors
            }
            $this->amqpConnection = null;
        }

        // MongoDB handles connection closing automatically
        $this->esnMongoClient = null;
        $this->sabreMongoClient = null;
        $this->esnDb = null;
        $this->sabreDb = null;
    }

    /**
     * Prevent singleton cloning
     */
    private function __clone() {}

    /**
     * Prevent singleton unserialization
     */
    public function __wakeup() {
        throw new \Exception("Cannot unserialize singleton");
    }
}
