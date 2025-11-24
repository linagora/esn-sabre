<?php
/**
 * PHP OPcache Preloading Script for ESN-Sabre
 *
 * This file preloads frequently used classes into shared memory
 * at PHP-FPM startup, reducing per-request overhead.
 *
 * PHP 8.0+ required for preloading support
 */

// Ensure we have the autoloader
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    return;
}

require_once __DIR__ . '/vendor/autoload.php';

// Preload core Sabre/DAV classes
$sabreClasses = [
    // Core DAV
    \Sabre\DAV\Server::class,
    \Sabre\DAV\Tree::class,
    \Sabre\DAV\Node::class,
    \Sabre\DAV\Collection::class,
    \Sabre\DAV\File::class,
    \Sabre\DAV\SimpleCollection::class,
    \Sabre\DAV\Exception::class,
    \Sabre\DAV\Exception\ServiceUnavailable::class,
    \Sabre\DAV\Exception\NotFound::class,
    \Sabre\DAV\Exception\Forbidden::class,

    // Auth
    \Sabre\DAV\Auth\Plugin::class,
    \Sabre\DAV\Auth\Backend\AbstractBasic::class,

    // CalDAV
    \Sabre\CalDAV\Plugin::class,
    \Sabre\CalDAV\Backend\BackendInterface::class,
    \Sabre\CalDAV\Calendar::class,
    \Sabre\CalDAV\CalendarObject::class,
    \Sabre\CalDAV\Principal\Collection::class,
    \Sabre\CalDAV\ICSExportPlugin::class,
    \Sabre\CalDAV\Schedule\Plugin::class,
    \Sabre\CalDAV\SharingPlugin::class,
    \Sabre\CalDAV\Subscriptions\Plugin::class,

    // CardDAV
    \Sabre\CardDAV\Plugin::class,
    \Sabre\CardDAV\Backend\BackendInterface::class,
    \Sabre\CardDAV\AddressBook::class,
    \Sabre\CardDAV\Card::class,
    \Sabre\CardDAV\VCFExportPlugin::class,

    // DAVACL
    \Sabre\DAVACL\Plugin::class,
    \Sabre\DAVACL\PrincipalBackend\BackendInterface::class,

    // HTTP
    \Sabre\HTTP\Request::class,
    \Sabre\HTTP\Response::class,
    \Sabre\HTTP\Sapi::class,

    // VObject
    \Sabre\VObject\Component::class,
    \Sabre\VObject\Component\VCalendar::class,
    \Sabre\VObject\Component\VCard::class,
    \Sabre\VObject\Reader::class,
    \Sabre\VObject\Writer::class,

    // Sync
    \Sabre\DAV\Sync\Plugin::class,
];

// Preload ESN custom classes
$esnClasses = [
    // Utils
    \ESN\Utils\ConnectionManager::class,

    // CalDAV
    \ESN\CalDAV\Plugin::class,
    \ESN\CalDAV\Backend\Esn::class,
    \ESN\CalDAV\CalendarRoot::class,
    \ESN\CalDAV\Principal\Collection::class,
    \ESN\CalDAV\Principal\ResourceCollection::class,
    \ESN\CalDAV\TextPlugin::class,
    \ESN\CalDAV\ParticipationPlugin::class,
    \ESN\CalDAV\MobileRequestPlugin::class,
    \ESN\CalDAV\ImportPlugin::class,

    // CardDAV
    \ESN\CardDAV\Plugin::class,
    \ESN\CardDAV\Backend\Esn::class,
    \ESN\CardDAV\AddressBookRoot::class,
    \ESN\CardDAV\MobileRequestPlugin::class,
    \ESN\CardDAV\Subscriptions\Plugin::class,
    \ESN\CardDAV\Sharing\Plugin::class,
    \ESN\CardDAV\Sharing\ListenerPlugin::class,

    // DAVACL
    \ESN\DAVACL\Plugin::class,
    \ESN\DAVACL\PrincipalBackend\Mongo::class,

    // DAV
    \ESN\DAV\Auth\Backend\Esn::class,
    \ESN\DAV\Sharing\Plugin::class,
    \ESN\DAV\XHttpMethodOverridePlugin::class,

    // JSON
    \ESN\JSON\Plugin::class,
    \ESN\JSON\FreeBusyPlugin::class,

    // Log
    \ESN\Log\ExceptionLoggerPlugin::class,
    \ESN\Log\EsnLoggerFactory::class,
];

// Publisher classes (if AMQP is enabled)
$publisherClasses = [
    \ESN\Publisher\AMQPPublisher::class,
    \ESN\Publisher\CalDAV\EventRealTimePlugin::class,
    \ESN\Publisher\CalDAV\CalendarRealTimePlugin::class,
    \ESN\Publisher\CalDAV\SubscriptionRealTimePlugin::class,
    \ESN\Publisher\CardDAV\ContactRealTimePlugin::class,
    \ESN\Publisher\CardDAV\AddressBookRealTimePlugin::class,
    \ESN\Publisher\CardDAV\SubscriptionRealTimePlugin::class,
];

// Schedule classes
$scheduleClasses = [
    \ESN\CalDAV\Schedule\Plugin::class,
    \ESN\CalDAV\Schedule\IMipPlugin::class,
    \ESN\CalDAV\Schedule\ITipPlugin::class,
];

// Combine all classes
$allClasses = array_merge(
    $sabreClasses,
    $esnClasses,
    $publisherClasses,
    $scheduleClasses
);

// Preload each class
$preloadedCount = 0;
$skippedCount = 0;

foreach ($allClasses as $class) {
    try {
        if (class_exists($class) || interface_exists($class) || trait_exists($class)) {
            opcache_compile_file((new ReflectionClass($class))->getFileName());
            $preloadedCount++;
        } else {
            $skippedCount++;
        }
    } catch (Throwable $e) {
        // Skip classes that can't be preloaded
        $skippedCount++;
    }
}

// Log preload statistics in dev mode
if (getenv('SABRE_ENV') === 'dev') {
    error_log(sprintf(
        'PHP Preload: %d classes preloaded, %d skipped',
        $preloadedCount,
        $skippedCount
    ));
}
