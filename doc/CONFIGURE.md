## ENV variables

`docker` packaging relies on ENV variables to generate configuration file throught [this script](../scripts/generate_config.sh).

The following ENV variables needs to be set:

 - SABRE_MONGO_HOST
 - SABRE_MONGO_PORT
 - SABRE_MONGO_DBNAME
 - ESN_MONGO_HOST
 - ESN_MONGO_PORT
 - ESN_MONGO_DBNAME
 - MONGO_TIMEOUT
 - ESN_HOST
 - ESN_PORT
 - AMQP_HOST
 - AMQP_PORT
 - AMQP_LOGIN
 - AMQP_PASSWORD
 - SABRE_ENV

 Additionally LDAP setup is done solely by ENV variables:

 - LDAP_ADMIN_DN
 - LDAP_ADMIN_PASSWORD
 - LDAP_BASE
 - LDAP_BASE_WITH_MAIL
 - LDAP_SERVER
 - LDAP_USERNAME_MODE
 - LDAP_FILTER (optional)

Credentials for impersonation are also set by ENV variables:

 - SABRE_ADMIN_LOGIN
 - SABRE_ADMIN_PASSWORD

Sabre being written in PHP, it supports per-request MongoDB indexes provisioning (defaults to `true`), which can be disabled by setting the SHOULD_CREATE_INDEX environment variable to `false`. This is recommended in production once indexes are provisioned.

## create the configuration file

The configuration file can be created from the example file.

```bash
cp config.json.default config.json
```

or by running the generation script:

```bash
sh ./scripts/generate_config.sh > config.json
```

You then have to modify the configuration to match your setup.

-	**webserver.baseUri**

The local part of the url that bring the esn.php file.

From apache, if you reach esn.php through http://YOUR_ESN_SABRE_IP/esn-sabre/esn.php then your baseUri is **/esn-sabre/esn.php**.

By using Docker your baseUri is only **/**.

-	**webserver.allowOrigin**

This setting is used to configure the headers returned by the CalDAV server. It's usefull to configure CORS. Either set the hostname of your ESN server, or leave "*".

-	**database.esn**

This is the configuration to access the ESN datastore

-	**database.sabre**

This is the configuration where the CalDAV server will store its data.

-	**esn.apiRoot**

This is the URL the Caldav server will use to access the OpenPaaS ESN API.

-	**mail**

This is the configuration the Caldav server will use to send emails.