# OpenPaaS ESN Frontend for SabreDAV

Welcome to the OpenPaaS frontend for [SabreDAV](http://sabre.io/). This frontend adds calendaring and address book capabilities to your OpenPaaS instance and allows you to access them via standard CalDAV and CardDAV clients like Lightning.

## Setting up the Environment

Those are the steps needed on an [Ubuntu](http://ubuntu.com/) distribution, but the equivalent can be found for any Linux flavor.

### Install esn-sabre

```bash
cd /var/www/html
git clone https://ci.linagora.com/linagora/lgs/openpaas/esn-sabre.git
```

The OpenPaaS frontend is managed through [composer](https://getcomposer.org/), all requirements can easily be set up using:

```bash
cd esn-sabre
composer update
```

This command can be repeated to update package versions.

### create the configuration file

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

### Configure advanced logging

Sabre can use [Monolog](https://github.com/Seldaek/monolog) to push log to standard file.

To configure logger add the `"logger"` item in config file with each logger needed

You can add ENV fields, these fields will add ENV variables values to each log entry in the `extras` field.
ENV fields define a field name that contains ENV variable value.

Full configuration is:
```json
{
        "logger" : {
                "fileLogger": {
                        "path": "Path to the file (required)",
                        "level": "debug level (default ERROR)"
                },
                "envFields": {
                        "myFirstEnvFieldName": "MY_FIRST_ENV_VARIABLE_NAME",
                        "mySecandEnvFieldName": "MY_SECOND_ENV_VARIABLE_NAME",
                        ...
                }
        }
}
```

Date format is specified by php date format [here](http://php.net/manual/fr/function.date.php)

Log level can be :

 * ALERT
 * CRITICAL
 * ERROR
 * WARNING
 * NOTICE
 * INFO
 * DEBUG


### System & Apache environment

esn-sabre requires an Apache server to work.

-	Install PHP5 support into Apache

```bash
apt-get install libapache2-mod-php5
```

Ubuntu 16.04

```bash
apt install php5.6 php5.6-bcmath libapache2-mod-php5.6
```

-	Install mongodb & curl support in PHP

```bash
apt-get install php5-mongo php5-curl
```

Ubuntu 16.04

```bash
apt-get install php5.6-mongo php5.6-curl
```

If **composer update** throws an error, you might want to use PECL

```bash
apt-get install php5-dev php-pear && pecl install mongo
```
Ubuntu 16.04

```bash
apt-get install php5.6-dev php-pear && pecl install mongo
```

Depending on your linux version you may need to add mongo extension in php.ini

```bash
extension=mongo.so
extension=mbstring.so
```

-	Restart Apache

```bash
/etc/init.d/apache2 restart
```

-	Test your environment by pointing a browser to the "esn.php" file

    http://your.server.example.com/esn-sabre/esn.php

### Enable the Caldav support in OpenPaaS ESN

The caldav support in OpenPaaS ESN is enabled by telling the system where to find the caldav server. To do so, create a document in the configuration collection, having this structure:

```json
{
        "_id" : "davserver",
        "backend" : {
                "url" : "http://192.168.7.6/esn-sabre/esn.php" // replace 192.168.7.6 by your localhost
        },
        "frontend" : {
                "url" : "http://my-caldav-server.example.com/esn-sabre/esn.php"  // replace my-caldav-server.example.com by your localhost
        }
}
```

The backend url is used by the ESN SERVER to access the caldav server. The frontend url is used by the user's browser to access the caldav server.

## Unit tests and codestyle

A simple Makefile exists to make it easier to run the tests and check code style. The following commands are available:

```bash
make test                        # Run all tests
make test TARGET=tests/CalDAV/   # Run only certain tests
make test-report                 # Run tests and create a coverage report

# run tests with docker compose
./run_test.sh

make lint                        # Check code style for the whole project
make lint TARGET=lib/            # Check only certain files for lint
```

The coverage report will be placed in the `tests/report` directory, a clickable link will be presented when done.

## Run

Refer to [this section](doc/RUN.md) for running the project.



### Test

Please refer to [this document](doc/TESTING.md) for running project tests.
