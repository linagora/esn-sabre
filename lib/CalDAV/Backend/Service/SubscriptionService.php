<?php

namespace ESN\CalDAV\Backend\Service;

use ESN\CalDAV\Backend\DAO\CalendarSubscriptionDAO;
use Sabre\Event\EventEmitter;

/**
 * Subscription Service
 *
 * Handles calendar subscription operations including:
 * - Creating, updating, and deleting subscriptions
 * - Managing subscription properties
 * - Finding subscribers for a source
 */
class SubscriptionService {
    private $calendarSubscriptionDAO;
    private $eventEmitter;
    private $subscriptionPropertyMap;

    public function __construct(CalendarSubscriptionDAO $calendarSubscriptionDAO, EventEmitter $eventEmitter, array $subscriptionPropertyMap) {
        $this->calendarSubscriptionDAO = $calendarSubscriptionDAO;
        $this->eventEmitter = $eventEmitter;
        $this->subscriptionPropertyMap = $subscriptionPropertyMap;
    }

    /**
     * Get all subscriptions for a user
     *
     * @param string $principalUri
     * @return array Array of subscription data
     */
    public function getSubscriptionsForUser($principalUri) {
        $fields = array_merge(
            array_values($this->subscriptionPropertyMap),
            ['_id', 'uri', 'source', 'principaluri', 'lastmodified']
        );

        $res = $this->calendarSubscriptionDAO->findByPrincipalUri($principalUri, $fields, ['calendarorder' => 1]);

        $subscriptions = [];
        foreach ($res as $row) {
            $subscription = [
                'id'           => (string) $row['_id'],
                'uri'          => $row['uri'],
                'principaluri' => $row['principaluri'],
                'source'       => $row['source'],
                'lastmodified' => $row['lastmodified'],
                '{' . \Sabre\CalDAV\Plugin::NS_CALDAV . '}supported-calendar-component-set' =>
                    new \Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet(['VEVENT', 'VTODO']),
            ];

            foreach($this->subscriptionPropertyMap as $xmlName => $dbName) {
                if (!is_null($row[$dbName])) {
                    $subscription[$xmlName] = $row[$dbName];
                }
            }

            $subscriptions[] = $subscription;
        }

        return $subscriptions;
    }

    /**
     * Create a new subscription
     *
     * @param string $principalUri
     * @param string $uri
     * @param array $properties
     * @param callable $getCalendarPathCallback
     * @return string Subscription ID
     * @throws \Sabre\DAV\Exception\Forbidden If source property is missing
     */
    public function createSubscription($principalUri, $uri, array $properties, $getCalendarPathCallback) {
        if (!isset($properties['{http://calendarserver.org/ns/}source'])) {
            throw new \Sabre\DAV\Exception\Forbidden('The {http://calendarserver.org/ns/}source property is required when creating subscriptions');
        }

        $obj = [
            'principaluri' => $principalUri,
            'uri'          => $uri,
            'source'       => $properties['{http://calendarserver.org/ns/}source']->getHref(),
            'lastmodified' => time(),
        ];

        foreach($this->subscriptionPropertyMap as $xmlName => $dbName) {
            $obj[$dbName] = isset($properties[$xmlName]) ? $properties[$xmlName] : null;
        }

        $subscriptionId = $this->calendarSubscriptionDAO->createSubscription($obj);

        $calendarPath = $getCalendarPathCallback($principalUri, $uri);
        $this->eventEmitter->emit('esn:subscriptionCreated', [$calendarPath]);

        return $subscriptionId;
    }

    /**
     * Update subscription properties
     *
     * @param string $subscriptionId
     * @param \Sabre\DAV\PropPatch $propPatch
     * @param callable $getCalendarPathCallback
     */
    public function updateSubscription($subscriptionId, \Sabre\DAV\PropPatch $propPatch, $getCalendarPathCallback) {
        $supportedProperties = array_keys($this->subscriptionPropertyMap);
        $supportedProperties[] = '{http://calendarserver.org/ns/}source';

        $propPatch->handle($supportedProperties, function($mutations) use ($subscriptionId, $getCalendarPathCallback) {
            $newValues = ['lastmodified' => time()];

            foreach($mutations as $propertyName => $propertyValue) {
                if ($propertyName === '{http://calendarserver.org/ns/}source') {
                    $newValues['source'] = $propertyValue->getHref();
                } else {
                    $fieldName = $this->subscriptionPropertyMap[$propertyName];
                    $newValues[$fieldName] = $propertyValue;
                }
            }

            $this->calendarSubscriptionDAO->updateSubscriptionById($subscriptionId, $newValues);

            $projection = ['uri' => 1, 'principaluri' => 1];
            $row = $this->calendarSubscriptionDAO->findSubscriptionById($subscriptionId, $projection);

            $calendarPath = $getCalendarPathCallback($row['principaluri'], $row['uri']);
            $this->eventEmitter->emit('esn:subscriptionUpdated', [$calendarPath]);

            return true;
        });
    }

    /**
     * Delete a subscription
     *
     * @param string $subscriptionId
     * @param callable $getCalendarPathCallback
     */
    public function deleteSubscription($subscriptionId, $getCalendarPathCallback) {
        $projection = ['uri' => 1, 'principaluri' => 1, 'source' => 1];
        $row = $this->calendarSubscriptionDAO->findSubscriptionById($subscriptionId, $projection);

        $this->calendarSubscriptionDAO->deleteSubscriptionById($subscriptionId);

        $calendarPath = $getCalendarPathCallback($row['principaluri'], $row['uri']);
        $this->eventEmitter->emit('esn:subscriptionDeleted', [$calendarPath, '/' . $row['source']]);
    }

    /**
     * Get all subscribers for a source calendar
     *
     * @param string $source Source calendar path
     * @return array Array of subscriber info
     */
    public function getSubscribers($source) {
        $projection = ['_id' => 1, 'principaluri' => 1, 'uri' => 1];
        $res = $this->calendarSubscriptionDAO->findSubscribersBySource($source, $projection);

        $result = [];
        foreach ($res as $row) {
            $result[] = [
                '_id' => $row['_id'],
                'principaluri' => $row['principaluri'],
                'uri' => $row['uri']
            ];
        }

        return $result;
    }
}
