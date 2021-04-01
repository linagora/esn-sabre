<?php

require_once 'vendor/autoload.php';

use \PhpAmqpLib\Connection\AMQPStreamConnection;

define('CONFIG_PATH', 'config.json');

$config = json_decode(file_get_contents(CONFIG_PATH), true);
if (!$config) {
    throw new Exception("Could not load config.json from " . realpath(CONFIG_PATH) . ", Error " . json_last_error());
}
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
    $esnMongoConnectionString = $dbConfig['esn']['connectionString'];
    $esnMongoClient = new \MongoDB\Client($esnMongoConnectionString, $dbConfig['esn']['connectionOptions']);
    $sabreMongoConnectionString = $dbConfig['sabre']['connectionString'];
    $sabreMongoClient = new \MongoDB\Client($sabreMongoConnectionString, $dbConfig['sabre']['connectionOptions']);

    $esnMongoDbName = getDatabaseName("esn", $esnMongoConnectionString, $dbConfig);
    if (!$esnMongoDbName) {
        throw new Exception("Unable to get ESN database name from configuration");
    }
    $sabreMongoDbName = getDatabaseName("sabre", $sabreMongoConnectionString, $dbConfig);
    if (!$sabreMongoDbName) {
        throw new Exception("Unable to get SABRE database name from configuration");
    }

    $esnDb = $esnMongoClient->{$esnMongoDbName};
    $sabreDb = $sabreMongoClient->{$sabreMongoDbName};

    if(!empty($config['amqp']['host'])) {
        $amqpLogin = !empty($config['amqp']['login']) ? $config['amqp']['login'] : 'guest';
        $amqpPassword = !empty($config['amqp']['password']) ? $config['amqp']['password'] : 'guest';

        $amqpConnection = new AMQPStreamConnection(
            $config['amqp']['host'],
            $config['amqp']['port'],
            $amqpLogin,
            $amqpPassword
        );
    }
} catch (Exception $e) {
    // Create a fake server that will abort with the exception right away. This
    // allows us to use SabreDAV's exception handler and output.
    $server = new Sabre\DAV\Server([]);

    // Add stack trace to HTML response in dev mode
    if (SABRE_ENV === SABRE_ENV_DEV) {
        $server->debugExceptions = true;
    }

    $server->addPlugin($loggerPlugin);

    $server->on('beforeMethod', function() use ($e) {
        throw new Sabre\DAV\Exception\ServiceUnavailable($e->getMessage());
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
      new Sabre\CalDAV\Principal\Collection($principalBackend, PRINCIPALS_RESOURCES),
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
    new ESN\CalDAV\Schedule\Plugin()
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

// Support CORS
$corsPlugin = new ESN\DAV\CorsPlugin();
if (isset($config['webserver']['corsAllowMethods'])) {
    $corsPlugin->allowMethods = $config['webserver']['corsAllowMethods'];
}
if (isset($config['webserver']['corsAllowHeaders'])) {
    $corsPlugin->allowHeaders = $config['webserver']['corsAllowHeaders'];
}
if (isset($config['webserver']['corsAllowOrigin'])) {
    $corsPlugin->allowOrigin = $config['webserver']['corsAllowOrigin'];
}
if (isset($config['webserver']['corsAllowCredentials'])) {
    $corsPlugin->allowCredentials = $config['webserver']['corsAllowCredentials'];
}
if (isset($config['webserver']['corsExposeHeaders'])) {
    $corsPlugin->exposeHeaders = $config['webserver']['corsExposeHeaders'];
}

// Regardless of the webserver settings, we need to support the ESNToken header
$corsPlugin->allowHeaders[] = 'ESNToken';

$server->addPlugin($corsPlugin);

// Rabbit publisher plugin
if (!empty($config['amqp']['host'])) {
    $channel = $amqpConnection->channel();
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

$server->addPlugin(new ESN\CalDAV\ParticipationPlugin());

$server->addPlugin(new ESN\CalDAV\MobileRequestPlugin());

$server->addPlugin(new ESN\CardDAV\MobileRequestPlugin());

$server->addPlugin(new ESN\CalDAV\ImportPlugin());

$server->addPlugin(new ESN\DAV\XHttpMethodOverridePlugin());

//Logger request plugin
$requestLoggerPLugin = new  ESN\CalDAV\RequestLoggerPlugin();
$server->addPlugin($requestLoggerPLugin);

// And off we go!
$server->exec();


function getDatabaseName($dbKind, $connectionString, $dbConfig) {
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
