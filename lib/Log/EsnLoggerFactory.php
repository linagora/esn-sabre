<?php

namespace ESN\Log;

/**
 * Initialize logger from conf
 *
 */
class EsnLoggerFactory
{
    const DEFAULT_LEVEL = \Monolog\Logger::ERROR;

    public static function initLogger($loggerConfig = null)
    {
        $handlers = [];

        if (isset($loggerConfig)) {
            if (isset($loggerConfig['fileLogger'])) {
                $fileHandler = self::initFileHandler($loggerConfig['fileLogger']);

                if (!empty($fileHandler)) {
                    $handlers[] = $fileHandler;
                }
            }
        }

        $logger = new \Monolog\Logger('EsnSabre');

        foreach ($handlers as  $handler) {
            $logger->pushHandler($handler);
        }

        return $logger;
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
}
