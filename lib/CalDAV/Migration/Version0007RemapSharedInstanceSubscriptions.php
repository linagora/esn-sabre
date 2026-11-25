<?php

namespace ESN\CalDAV\Migration;

use ESN\CalDAV\Subscriptions\SubscriptionSource;
use ESN\Utils\Migration\Migration;
use ESN\Utils\Migration\MigrationException;
use MongoDB\Collection;
use MongoDB\Database;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Sharing\Plugin as SharingPlugin;

/**
 * A subscription is never served from a shared instance of a calendar (calendars/<sharee>/<instanceUri>): its
 * principaluri is the sharee, not the owner of its events (GHSA-g357-w92v-552m). Subscriptions stored with such a
 * source before it was refused would silently serve nothing: point them to the owner's instance of the calendar.
 *
 * The read-time check of ESN\CalDAV\Subscriptions\Subscription keeps deciding whether the subscriber may read the
 * owner's instance (public right, share, delegation), exactly as for a subscription created on it: this rewrite
 * never grants a read the subscriber has not.
 */
class Version0007RemapSharedInstanceSubscriptions implements Migration
{
    public const BATCH_SIZE = 500;

    private const HOME_PRINCIPAL_PREFIXES = ['principals/users/', 'principals/resources/', 'principals/team-calendars/'];

    public function getName(): string
    {
        return '0007_remap_shared_instance_subscriptions';
    }

    public function up(Database $database, LoggerInterface $logger): void
    {
        $started = microtime(true);
        $context = ['migration' => $this->getName(), 'database' => $database->getDatabaseName()];
        $logger->info('Shared instance subscription remapping starting', $context);
        $subscriptions = $database->selectCollection('calendarsubscriptions');
        $instances = $database->selectCollection('calendarinstances');

        try {
            $updated = 0;
            $operations = [];
            foreach ($this->subscriptions($subscriptions) as $subscription) {
                $operations = array_merge($operations, $this->remapOperations($instances, $subscription));
                if (count($operations) === self::BATCH_SIZE) {
                    $updated += $subscriptions->bulkWrite($operations)->getModifiedCount();
                    $operations = [];
                }
            }
            $updated += $operations ? $subscriptions->bulkWrite($operations)->getModifiedCount() : 0;
        } catch (\Throwable $error) {
            throw new MigrationException('Unable to remap the subscriptions to a shared instance of a calendar', 0, $error);
        }

        $logger->info('Shared instance subscription remapping completed', $context + [
            'subscriptions_remapped' => $updated,
            'elapsed_seconds' => round(microtime(true) - $started, 3),
        ]);
    }

    private function subscriptions(Collection $subscriptions): iterable
    {
        return $subscriptions->find([], [
            'projection' => ['_id' => 1, 'source' => 1],
            'sort' => ['_id' => 1],
            'batchSize' => self::BATCH_SIZE,
            'typeMap' => ['root' => 'array', 'document' => 'array'],
        ]);
    }

    /**
     * @return array the update of the subscription source, none when it is not a shared instance of a calendar
     */
    private function remapOperations(Collection $instances, array $subscription): array
    {
        $ownerSource = $this->ownerSourceOfSharedInstance($instances, $subscription['source'] ?? null);

        // DAV remains writable: only rewrite the source we read
        return $ownerSource === null ? [] : [['updateOne' => [
            ['_id' => $subscription['_id'], 'source' => $subscription['source']],
            ['$set' => ['source' => $ownerSource]],
        ]]];
    }

    /**
     * @return string|null the source of the owner's instance, when the source is a shared instance of a calendar
     */
    private function ownerSourceOfSharedInstance(Collection $instances, $source): ?string
    {
        $parsedSource = SubscriptionSource::parse($source);
        if ($parsedSource === null) {
            return null;
        }

        [$home, $calendarUri] = $parsedSource;
        $sharedInstance = $instances->findOne([
            'principaluri' => ['$in' => array_map(fn (string $prefix) => $prefix . $home, self::HOME_PRINCIPAL_PREFIXES)],
            'uri' => $calendarUri,
            'access' => ['$ne' => SharingPlugin::ACCESS_SHAREDOWNER],
        ], ['projection' => ['calendarid' => 1]]);
        $ownerInstance = $sharedInstance === null ? null : $instances->findOne(
            ['calendarid' => $sharedInstance['calendarid'], 'access' => SharingPlugin::ACCESS_SHAREDOWNER],
            ['projection' => ['principaluri' => 1, 'uri' => 1]]
        );

        return $ownerInstance === null ? null : SubscriptionSource::path(basename($ownerInstance['principaluri']), $ownerInstance['uri']);
    }
}
