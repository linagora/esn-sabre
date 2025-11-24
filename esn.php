<?php

require_once 'vendor/autoload.php';

use \ESN\Utils\ConnectionManager;

define('CONFIG_PATH', 'config.json');

// Use connection manager for persistent connections and cached config
$connectionManager = ConnectionManager::getInstance();
$config = $connectionManager->getConfig();
$dbConfig = $config['database'];
define('SABRE_ENV_PRODUCTION', 'production');
define('SABRE_ENV_DEV', 'dev');

define('SABRE_ENV', (!empty($config['environment']) && !empty($config['environment']['SABRE_ENV'])) ? $config['environment']['SABRE_ENV'] : SABRE_ENV_PRODUCTION);

// settings
date_default_timezone_set('UTC');

define('PRINCIPALS_COLLECTION', 'principals');
define('PRINCIPALS_USERS', 'principals/users');
define('PRINCIPALS_TECHNICAL_USER', 'principals/technicalUser');
define('PRINCIPALS_RESOURCES', 'principals/resources');
define('PRINCIPALS_DOMAINS', 'principals/domains');
define('JSON_ROOT', 'json');

//Mapping PHP errors to exceptions
function exception_error_handler($errno, $errstr, $errfile, $errline ) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}
set_error_handler("exception_error_handler");

$loggerConfig = isset($config['logger']) ? $config['logger'] : null;

$logger = ESN\Log\EsnLoggerFactory::initLogger($loggerConfig);

$loggerPlugin = new ESN\Log\ExceptionLoggerPlugin($logger);

try {
    // Use persistent MongoDB connections from connection manager
    $esnDb = $connectionManager->getEsnDb();
    $sabreDb = $connectionManager->getSabreDb();
} catch (Exception $e) {
    // Create a fake server that will abort with the exception right away. This
    // allows us to use SabreDAV's exception handler and output.
    $server = new Sabre\DAV\Server([]);

    // Add stack trace to HTML response in dev mode
    if (SABRE_ENV === SABRE_ENV_DEV) {
        $server->debugExceptions = true;
    }

    $server->addPlugin($loggerPlugin);

    $server->on('beforeMethod:*', function() use ($e) {
        throw new Sabre\DAV\Exception\ServiceUnavailable($e->getTraceAsString());
    }, 1);
    $server->exec();
    return;
}

// Backends
$addressbookBackend = new ESN\CardDAV\Backend\Esn($sabreDb);
$principalBackend = new ESN\DAVACL\PrincipalBackend\Mongo($esnDb);

$schedulingObjectTTLInDays = $dbConfig['schedulingObjectTTLInDays'] ?? 56;
$calendarBackend = new ESN\CalDAV\Backend\Esn($sabreDb, $principalBackend, $schedulingObjectTTLInDays);

// Directory structure
$tree = [
    new Sabre\DAV\SimpleCollection(PRINCIPALS_COLLECTION, [
      new ESN\CalDAV\Principal\Collection($principalBackend, PRINCIPALS_USERS),
      new ESN\CalDAV\Principal\ResourceCollection($principalBackend, PRINCIPALS_RESOURCES),
      new Sabre\CalDAV\Principal\Collection($principalBackend, PRINCIPALS_TECHNICAL_USER),
      new Sabre\CalDAV\Principal\Collection($principalBackend, PRINCIPALS_DOMAINS)
    ]),
    new ESN\CalDAV\CalendarRoot($principalBackend, $calendarBackend, $esnDb),
    new ESN\CardDAV\AddressBookRoot($principalBackend, $addressbookBackend),
];

$server = new Sabre\DAV\Server($tree);

// logger plugin
$server->addPlugin($loggerPlugin);

// Auth backend
$authBackend = new ESN\DAV\Auth\Backend\Esn($config['esn']['apiRoot'], $config['webserver']['realm'], $principalBackend, $server);

// listener
$authEmitter = $authBackend->getEventEmitter();
$authEmitter->on("auth:success", [$addressbookBackend, "getAddressBooksForUser"]);
$authEmitter->on("auth:success", [$calendarBackend, "getCalendarsForUser"]);

// Add stack trace to HTML response in dev mode
if (SABRE_ENV === SABRE_ENV_DEV) {
    $server->debugExceptions = true;
}


$server->setBaseUri($config['webserver']['baseUri']);

// Server Plugins
$authPlugin = new Sabre\DAV\Auth\Plugin($authBackend);
$server->addPlugin($authPlugin);

$aclPlugin = new Sabre\DAVACL\Plugin();
$aclPlugin->principalCollectionSet = [
    PRINCIPALS_USERS,
    PRINCIPALS_RESOURCES,
    PRINCIPALS_DOMAINS
];
$aclPlugin->adminPrincipals[] = PRINCIPALS_TECHNICAL_USER;
$server->addPlugin($aclPlugin);

// JSON api support for DAVACL plugin
$esnAclPlugin = new ESN\DAVACL\Plugin();
$server->addPlugin($esnAclPlugin);

// JSON api support
$jsonPlugin = new ESN\JSON\Plugin(JSON_ROOT);
$server->addPlugin($jsonPlugin);

// TEXT api support (iphone)
$textPlugin = new ESN\CalDAV\TextPlugin("text");
$server->addPlugin($textPlugin);

// FREEBUSY api support
$freeBusyPlugin = new ESN\JSON\FreeBusyPlugin();
$server->addPlugin($freeBusyPlugin);

// CalDAV support
$caldavPlugin = new ESN\CalDAV\Plugin();
$server->addPlugin($caldavPlugin);

// CardDAV support
$carddavPlugin = new Sabre\CardDAV\Plugin();
$server->addPlugin($carddavPlugin);

$carddavJsonPlugin = new ESN\CardDAV\Plugin();
$server->addPlugin($carddavJsonPlugin);

// vCard export plugin
$vcfPlugin = new Sabre\CardDAV\VCFExportPlugin();
$server->addPlugin($vcfPlugin);

// CardDAV subscription support
$carddavSubscriptionPlugin = new ESN\CardDAV\Subscriptions\Plugin();
$server->addPlugin($carddavSubscriptionPlugin);

// CardDAV sharing support
$carddavSharingPlugin = new ESN\CardDAV\Sharing\Plugin();
$server->addPlugin($carddavSharingPlugin);

$carddavSharingListenerPlugin = new ESN\CardDAV\Sharing\ListenerPlugin($addressbookBackend);
$server->addPlugin($carddavSharingListenerPlugin);

// Calendar subscription support
$server->addPlugin(
    new Sabre\CalDAV\Subscriptions\Plugin()
);

// Calendar sharing support
$server->addPlugin(new ESN\DAV\Sharing\Plugin());
$server->addPlugin(new Sabre\CalDAV\SharingPlugin());

// Calendar scheduling support
$server->addPlugin(
    new ESN\CalDAV\Schedule\Plugin($principalBackend)
);

// WebDAV-Sync plugin
$server->addPlugin(new Sabre\DAV\Sync\Plugin());

// Support for html frontend (only available in dev mode)
if (SABRE_ENV === SABRE_ENV_DEV) {
    $browser = new Sabre\DAV\Browser\Plugin();
    $server->addPlugin($browser);
}

// Calendar Ics Export support
$icsExportPlugin = new Sabre\CalDAV\ICSExportPlugin();
$server->addPlugin($icsExportPlugin);

// Rabbit publisher plugin - use persistent connection with per-request channel
if ($connectionManager->hasAmqp()) {
    $channel = $connectionManager->createAmqpChannel();
    $AMQPPublisher = new ESN\Publisher\AMQPPublisher($channel);
    $eventRealTimePlugin = new ESN\Publisher\CalDAV\EventRealTimePlugin($AMQPPublisher, $calendarBackend);
    $server->addPlugin($eventRealTimePlugin);

    $calendarRealTimePlugin = new ESN\Publisher\CalDAV\CalendarRealTimePlugin($AMQPPublisher, $calendarBackend);
    $server->addPlugin($calendarRealTimePlugin);

    $subscriptionRealTimePlugin = new ESN\Publisher\CalDAV\SubscriptionRealTimePlugin($AMQPPublisher, $calendarBackend->getEventEmitter());
    $server->addPlugin($subscriptionRealTimePlugin);

    $contactRealTimePlugin = new ESN\Publisher\CardDAV\ContactRealTimePlugin($AMQPPublisher);
    $server->addPlugin($contactRealTimePlugin);

    $addressBookRealTimePlugin = new ESN\Publisher\CardDAV\AddressBookRealTimePlugin($AMQPPublisher, $addressbookBackend);
    $server->addPlugin($addressBookRealTimePlugin);

    $subscriptionRealTimePlugin = new ESN\Publisher\CardDAV\SubscriptionRealTimePlugin($AMQPPublisher, $addressbookBackend);
    $server->addPlugin($subscriptionRealTimePlugin);

    // iMip Plugin to handle sending emails
    $server->addPlugin(new ESN\CalDAV\Schedule\IMipPlugin($AMQPPublisher));
}

$server->addPlugin(new ESN\CalDAV\Schedule\ITipPlugin());

$server->addPlugin(new ESN\CalDAV\ParticipationPlugin());

$server->addPlugin(new ESN\CalDAV\MobileRequestPlugin());

$server->addPlugin(new ESN\CardDAV\MobileRequestPlugin());

$server->addPlugin(new ESN\CalDAV\ImportPlugin());

$server->addPlugin(new ESN\DAV\XHttpMethodOverridePlugin());

// Logger request plugin
if (SABRE_ENV === SABRE_ENV_DEV) {
    $requestLoggerPlugin = new  ESN\Log\RequestLoggerPlugin();
    $server->addPlugin($requestLoggerPlugin);
}

// And off we go!
$server->exec();
