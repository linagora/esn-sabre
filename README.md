# esn-sabre

![LOGO](assets/calendar.svg)

## Goals

This project is THE dav server behind Twake Calendar, where the magic take place.

It builds on top of [sabre/dav](https://github.com/sabre-io/dav/) and adds [various modules]() in order to deliver an enterprise ready calendar server!

Please note the following other components of the Twake Calendar product:
 - [Twake Calendar Side service](https://github.com/linagora/twake-calendar-side-service) augment the DAV serveur with mailing, search, users, domains, configuration...
 - [Twake Calendar Integretion Tests](https://github.com/linagora/twake-calendar-integration-tests) acts as a quality assurance for the Twake Calendar project umbrella, including this project.
 - OpenPaaS single page applications: [calendar](https://github.com/linagora/esn-frontend-calendar) and [contacts](https://github.com/linagora/esn-frontend-contacts).

## Roadmap

We are planning to:

 - Update this project to latest PHP and sabre/dav version
 - Triage most bugs reported on esn-sabre
 - Improve performances
 - Have a multi-tenant Sabre (per domain)
 - Eventually add team-calendar

## Configure

Refer to [this section](doc/CONFIGURE.md) for configuring the project.

## Run

Refer to [this section](doc/RUN.md) for running the project.

### Test

Please refer to [this document](doc/TESTING.md) for running project tests.

### Interfaces

`esn-sabre` exposes a convenience [JSON API](doc/JSON-API.md).