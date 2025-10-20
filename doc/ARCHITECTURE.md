## Architecture

![TAD](../assets/twake-calendar-side-service-architecture.drawio.png)

## Flux matrix

`esn-sabre` relies on Twake Calendar side service, that is used for instance for validating technical token used for resource management and domain member address book. 
It also uses it in order to resolve the email address of a given user.

`esn-sabre` is called by [Twake-Mail](https://github.com/linagora/tmail-backend) in order to maintain collected address book, integrate calendar in email (ITIP + calendar integration in emails).

## Persistance

[**MongoDB**](https://www.mongodb.com/) is used as a primary data store. Entities are stored in order to be retro-compatible
with the OpenPaaS dataformat making a transition from OpenPaaS to the side service trivial. Indexes matching the application
access pattern are created upon application start.

Please note that `esn-sabre` accesses 2 DBs:
 - The main `esn-sabre` DB which is used to hold calendar and contact data
 - It also directly queries the `side service` DB for principal/calendar root

[**RabbitMQ**](https://www.rabbitmq.com/) is used in order to push events resulting from user actions. The `side service` and `Twake Mail` listen to those events in order to
add features in a composable fashion

[**Redis**](https://redis.io/) is used as a cache to store OpenID connect access token hash thus lowring the load on the identity server.
Invalidation of this cache is possible by relying on back-channel logout.

**LDAP** data storage is possible and backs basic authentication