<?php

require_once 'vendor/autoload.php';

define('CONFIG_PATH', 'config.json');

$config = json_decode(file_get_contents(CONFIG_PATH), true);
if (!$config) {
    throw new Exception("Could not load config.json from " . realpath(CONFIG_PATH) . ", Error " . json_last_error());
}

// settings
date_default_timezone_set('UTC');

define('PRINCIPALS_COLLECTION', 'principals');
define('PRINCIPALS_USERS', 'principals/users');
define('PRINCIPALS_COMMUNITIES', 'principals/communities');

//Mapping PHP errors to exceptions
function exception_error_handler($errno, $errstr, $errfile, $errline ) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}
set_error_handler("exception_error_handler");

try {
    $mongo = new MongoClient($config['database']['connectionString'], $config['database']['connectionOptions']);
} catch (MongoConnectionException $e) {
    // Create a fake server that will abort with the exception right away. This
    // allows us to use SabreDAV's exception handler and output.
    $server = new Sabre\DAV\Server([]);
    $server->on('beforeMethod', function() use ($e) {
        throw new Sabre\DAV\Exception\ServiceUnavailable($e->getMessage());
    }, 1);
    $server->exec();
    return;
}


// Backends
$authBackend = new ESN\DAV\Auth\Backend\Esn($config["esn"]["apiRoot"]);
$calendarBackend = new ESN\CalDAV\Backend\Mongo($mongo->sabredav);
$addressbookBackend = new ESN\CardDAV\Backend\Mongo($mongo->sabredav);
$principalBackend = new ESN\DAVACL\PrincipalBackend\Mongo($mongo->hiveet);

// Directory structure
$tree = [
    new Sabre\DAV\SimpleCollection(PRINCIPALS_COLLECTION, [
      new Sabre\CalDAV\Principal\Collection($principalBackend, PRINCIPALS_USERS),
      new Sabre\CalDAV\Principal\Collection($principalBackend, PRINCIPALS_COMMUNITIES),
    ]),
    new ESN\CalDAV\CalendarRoot($principalBackend, $calendarBackend, $mongo->hiveet),
    new ESN\CardDAV\AddressBookRoot($principalBackend, $addressbookBackend, $mongo->hiveet),
];

$server = new Sabre\DAV\Server($tree);
$server->debugExceptions = true;

$server->setBaseUri($config['webserver']['baseUri']);

// Server Plugins
$authPlugin = new Sabre\DAV\Auth\Plugin($authBackend,'SabreDAV');
$server->addPlugin($authPlugin);

$aclPlugin = new Sabre\DAVACL\Plugin();
$aclPlugin->defaultUsernamePath = PRINCIPALS_USERS;
$server->addPlugin($aclPlugin);

// CalDAV support
$caldavPlugin = new Sabre\CalDAV\Plugin();
$server->addPlugin($caldavPlugin);

// CardDAV support
$carddavPlugin = new Sabre\CardDAV\Plugin();
$server->addPlugin($carddavPlugin);

// Calendar subscription support
$server->addPlugin(
    new Sabre\CalDAV\Subscriptions\Plugin()
);

// Calendar scheduling support
$server->addPlugin(
    new Sabre\CalDAV\Schedule\Plugin()
);

// WebDAV-Sync plugin
$server->addPlugin(new Sabre\DAV\Sync\Plugin());

// Support for html frontend
$browser = new Sabre\DAV\Browser\Plugin();
$server->addPlugin($browser);

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
$server->addPlugin($corsPlugin);

$esnHookPlugin = new ESN\CalDAV\ESNHookPlugin($config['esn']['apiRoot'], PRINCIPALS_COMMUNITIES);
$server->addPlugin($esnHookPlugin);

// And off we go!
$server->exec();
