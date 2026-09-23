<?php

namespace ESN\DAV;

/**
 * Counts the MongoDB find commands sent while it is subscribed, per collection.
 */
class MongoFindCounter implements \MongoDB\Driver\Monitoring\CommandSubscriber {
    private $finds = [];

    /**
     * Runs $callback and returns the number of find commands it sent on $collection.
     */
    static function count($collection, callable $callback) {
        $counter = new self();
        \MongoDB\Driver\Monitoring\addSubscriber($counter);
        try {
            $callback();
        } finally {
            \MongoDB\Driver\Monitoring\removeSubscriber($counter);
        }

        return $counter->finds[$collection] ?? 0;
    }

    function commandStarted(\MongoDB\Driver\Monitoring\CommandStartedEvent $event): void {
        if ($event->getCommandName() === 'find') {
            $collection = $event->getCommand()->find;
            $this->finds[$collection] = ($this->finds[$collection] ?? 0) + 1;
        }
    }

    function commandSucceeded(\MongoDB\Driver\Monitoring\CommandSucceededEvent $event): void {}

    function commandFailed(\MongoDB\Driver\Monitoring\CommandFailedEvent $event): void {}
}
