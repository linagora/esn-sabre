<?php

namespace ESN\Log;

/**
 * Initialize logger from conf
 *
 */
class EsnLoggerFactory
{
    const DEFAULT_LEVEL = \Monolog\Logger::ERROR;
    private $extraFiels;

    public static function initLogger($loggerConfig)
    {
        $handlers = [];

        if (isset($loggerConfig['fileLogger'])) {
            $fileHandler = self::initFileHandler($loggerConfig['fileLogger']);

            if (!empty($fileHandler)) {
                $handlers[] = $fileHandler;
            }
        }

        if (isset($loggerConfig['esLogger'])) {
            $esLoggerConfig = $loggerConfig['esLogger'];
            $handlers[] = self::initESHandler($esLoggerConfig);
        }

        if (!empty($handlers)) {
            $logger = new \Monolog\Logger('EsnSabre');

            foreach ($handlers as  $handler) {
                $logger->pushHandler($handler);
            }

            return $logger;
        }

        return null;
    }

    private static function initFileHandler($fileLoggerConfig)
    {
        if (isset($fileLoggerConfig['path'])) {
            $logLevel = self::DEFAULT_LEVEL;
            if (isset($fileLoggerConfig['level']) && defined('Monolog\Logger::'.$fileLoggerConfig['level'])) {
                $logLevel = constant('Monolog\Logger::'.$fileLoggerConfig['level']);
            }

            return new \Monolog\Handler\StreamHandler($fileLoggerConfig['path'], $logLevel);
        }

        return null;
    }

    private static function initESHandler($esLoggerConfig)
    {
        $esHost = isset($esLoggerConfig['host']) ? $esLoggerConfig['host'] : 'localhost';
        $esPort = isset($esLoggerConfig['port']) ? $esLoggerConfig['port'] : 9200;

        $logLevel = self::DEFAULT_LEVEL;
        if (isset($esLoggerConfig['level']) && defined('Monolog\Logger::'.$esLoggerConfig['level'])) {
            $logLevel = constant('Monolog\Logger::'.$fileLoggerConfig['level']);
        }

        $clientOptions = [
            'host' => $esHost,
            'port' => $esPort
        ];

        if (isset($esLoggerConfig['username']) && isset($esLoggerConfig['password'])) {
            $clientOptions[] = ['username' => $esLoggerConfig['username']];
            $clientOptions[] = ['password' => $esLoggerConfig['password']];
        }

        $elasticaClient = new \Elastica\Client($clientOptions);

        $indexName = 'monolog';
        $indexType = '_doc';

        if (isset($esLoggerConfig['index'])) {
            $indexName = $esLoggerConfig['index'];
        }

        if (isset($esLoggerConfig['appendDateToIndexName'])) {
            $indexName .= date($esLoggerConfig['appendDateToIndexName']);
        }

        if (isset($esLoggerConfig['type'])) {
            $indexType = $esLoggerConfig['type'];
        }

        $options = [
            'index' => $indexName,
            'type' => $indexType
        ];

        $handler = new \Monolog\Handler\ElasticSearchHandler($elasticaClient, $options, $logLevel);
        $handler->setFormatter(new EsnLoggerFormatter($handler->getOptions()['index'], $handler->getOptions()['type']));

        return $handler;
    }
}
