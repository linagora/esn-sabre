<?php

namespace ESN\CalDAV\Migration;

use ESN\CalDAV\Backend\DAO\CalendarObjectDAO;
use ESN\Utils\Migration\Migration;
use ESN\Utils\Migration\MigrationException;
use MongoDB\Database;
use MongoDB\Driver\Exception\ConnectionTimeoutException;
use Psr\Log\LoggerInterface;

/**
 * Builds the time-range index starting with lastoccurence (see CalendarObjectDAO::TIME_RANGE_BY_END_INDEX_KEY).
 *
 * Done here rather than in the startup index creation: on a large calendarobjects collection the build can take
 * longer than the socket timeout of the driver (5 minutes by default), which would fail and retry the startup until
 * the container gives up. Migrations run in the background, DAV serving meanwhile with the former index.
 */
class Version0006CreateTimeRangeByEndIndex implements Migration
{
    /**
     * A socket timeout leaves the build running on the server: the next createIndexes with the same key waits for it
     * instead of starting another one. 288 timeouts of 5 minutes is a day.
     */
    public const MAX_ATTEMPTS = 288;

    public function getName(): string
    {
        return '0006_create_time_range_by_end_index';
    }

    public function up(Database $database, LoggerInterface $logger): void
    {
        $started = microtime(true);
        $context = ['migration' => $this->getName(), 'database' => $database->getDatabaseName(), 'collection' => 'calendarobjects'];
        $logger->info('Calendar object time-range index build starting', $context);

        for ($attempt = 1; ; $attempt++) {
            try {
                $database->selectCollection('calendarobjects')->createIndex(CalendarObjectDAO::TIME_RANGE_BY_END_INDEX_KEY);
                break;
            } catch (ConnectionTimeoutException $error) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw new MigrationException('Timed out building the time-range index of calendarobjects', 0, $error);
                }
                $logger->info('Calendar object time-range index build still running, waiting for it', $context + [
                    'attempt' => $attempt,
                    'elapsed_seconds' => round(microtime(true) - $started, 3),
                ]);
            } catch (\Throwable $error) {
                throw new MigrationException('Unable to build the time-range index of calendarobjects', 0, $error);
            }
        }

        $logger->info('Calendar object time-range index build completed', $context + [
            'elapsed_seconds' => round(microtime(true) - $started, 3),
        ]);
    }
}
