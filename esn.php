<?php

require_once 'vendor/autoload.php';

// settings
date_default_timezone_set('UTC');

// $baseUri = '/';
$baseUri = '/esn.php/';

//Mapping PHP errors to exceptions
function exception_error_handler($errno, $errstr, $errfile, $errline ) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}
set_error_handler("exception_error_handler");

// Database
try {
    $mongo = new MongoClient('mongodb://localhost:23456');
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
$authBackend = new ESN\DAV\Auth\Backend\Mongo($mongo->hiveet);
$calendarBackend = new ESN\CalDAV\Backend\Mongo($mongo->sabredav);
$addressbookBackend = new ESN\CardDAV\Backend\Mongo($mongo->sabredav);
$principalBackend = new ESN\DAVACL\PrincipalBackend\Mongo($mongo->hiveet);

// Directory structure
$tree = [
    new Sabre\DAV\SimpleCollection('principals', [
      new Sabre\CalDAV\Principal\Collection($principalBackend, 'principals/users'),
      new Sabre\CalDAV\Principal\Collection($principalBackend, 'principals/communities'),
    ]),
    new ESN\CalDAV\CalendarRoot($principalBackend, $calendarBackend, $mongo->hiveet),
    new ESN\CardDAV\AddressBookRoot($principalBackend, $addressbookBackend, $mongo->hiveet),
];

$server = new Sabre\DAV\Server($tree);
$server->debugExceptions = true;

if (isset($baseUri))
    $server->setBaseUri($baseUri);

// Server Plugins
$authPlugin = new Sabre\DAV\Auth\Plugin($authBackend,'SabreDAV');
$server->addPlugin($authPlugin);

$aclPlugin = new Sabre\DAVACL\Plugin();
$aclPlugin->defaultUsernamePath = 'principals/users';
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
$server->addPlugin($corsPlugin);

$esnHookPlugin = new ESN\CalDAV\ESNHookPlugin();
$server->addPlugin($esnHookPlugin);

// And off we go!
$server->exec();
