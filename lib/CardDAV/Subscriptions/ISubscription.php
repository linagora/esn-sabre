<?php

namespace ESN\CardDAV\Subscriptions;

use Sabre\DAV\ICollection;
use Sabre\DAV\IProperties;

/**
 * ISubscription
 *
 * Nodes implementing this interface represent address book subscriptions.
 *
 * The subscription node doesn't do much, other than returning and updating
 * subscription-related properties.
 *
 * The following properties should be supported:
 *
 * 1. {DAV:}displayname
 * 2. {http://open-paas.org/ns/}source (Must be a Sabre\DAV\Property\Href).
 */
interface ISubscription extends ICollection, IProperties {


}
