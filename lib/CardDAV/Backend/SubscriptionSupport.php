<?php

namespace ESN\CardDAV\Backend;

use Sabre\DAV;
use Sabre\CardDAV\Backend\BackendInterface;

/**
 * Every CardDAV backend must at least implement this interface.
 */
interface SubscriptionSupport extends BackendInterface {

    /**
     * Returns a list of subscriptions for a principal.
     *
     * Every subscription is an array with the following keys:
     *  * id, a unique id that will be used by other functions to modify the
     *    subscription. This can be the same as the uri or a database key.
     *  * uri. This is just the 'base uri' or 'filename' of the subscription.
     *  * principaluri. The owner of the subscription. Almost always the same as
     *    principalUri passed to this method.
     *
     * Furthermore, all the subscription info must be returned too:
     *
     * 1. {DAV:}displayname
     * 2. {http://open-paas.org/ns/}source (Must be a Sabre\DAV\Property\Href).
     *
     * @param string $principalUri
     * @return array
     */
    function getSubscriptionsForUser($principalUri);

    /**
     * Creates a new subscription for a principal.
     *
     * If the creation was a success, an id must be returned that can be used to reference
     * this subscription in other methods, such as updateSubscription.
     *
     * @param string $principalUri
     * @param string $uri
     * @param array $properties
     * @return mixed
     */
    function createSubscription($principalUri, $uri, array $properties);

    /**
     * Updates a subscription
     *
     * The list of mutations is stored in a Sabre\DAV\PropPatch object.
     * To do the actual updates, you must tell this object which properties
     * you're going to process with the handle() method.
     *
     * Calling the handle method is like telling the PropPatch object "I
     * promise I can handle updating this property".
     *
     * Read the PropPatch documentation for more info and examples.
     *
     * @param mixed $subscriptionId
     * @param \Sabre\DAV\PropPatch $propPatch
     * @return void
     */
    function updateSubscription($subscriptionId, DAV\PropPatch $propPatch);

    /**
     * Deletes a subscription.
     *
     * @param mixed $subscriptionId
     * @return void
     */
    function deleteSubscription($subscriptionId);

}
