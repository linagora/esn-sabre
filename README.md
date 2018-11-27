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

Sabre can use [Monolog](https://github.com/Seldaek/monolog) to push log to :

 * Standard file
 * Elastic Search

To configure logger add the `"logger"` item in config file with each logger needed

Full configuration is:
```json
{
        "logger" : {
                "fileLogger": {
                        "path": "Path to the file (required)",
                        "level": "debug level (default ERROR)"
                },
                "esLogger": {
                        "host": "ES hostname (default localhost)",
                        "port": "ES port (default 9200)",
                        "level": "debug level (default ERROR)",
                        "index": "index name (default monolog)",
                        "appendDateToIndexName": "date format (default none)",
                        "username":"ES user name (default none)",
                        "password":"ES user password (default none)"
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

make lint                        # Check code style for the whole project
make lint TARGET=lib/            # Check only certain files for lint
```

The coverage report will be placed in the `tests/report` directory, a clickable link will be presented when done.

## Docker

### Build

```
docker build -t linagora/esn-sabre .
```

### Run

In order to run, the ESN sabre instance must access to the ESN, and mongo instances. They can be configured from the run command using these optional environment variables:

- SABRE_MONGO_HOST: Mongodb instance used to store sabre data, defaults to 'sabre_mongo'
- SABRE_MONGO_PORT: Port used by the Mongodb instance defined above, defaults to '27017'
- ESN_MONGO_HOST: Mongodb instance of the ESN, used to create principals, defaults to 'esn_mongo'
- ESN_MONGO_PORT: Port of the ESN Mongodb instance, defaults to '27017'
- ESN_MONGO_DBNAME: Database name of the ESN Mongodb instance, defaults to 'esn'
- ESN_HOST: Hostname of the ESN API, defaults to 'esn_host'
- ESN_PORT: Port of the ESN API, defaults to '8080'
- AMQP_HOST: AMQP instance used by Sabre and the ESN, defaults to 'amqp_host'
- AMQP_PORT: Port of the instance defined just above, defaults to '5672'
- AMQP_LOGIN: login used to connect to the message broker defined above, defaults to 'guest'
- AMQP_PASSWORD: password used to connect to the message broker defined above, defaults to 'guest'
- MONGO_TIMEOUT: Timeout to connect to Mongodb, defaults to 10000 ms

For example:

```
docker run -d -p 8001:80 -e "SABRE_MONGO_HOST=192.168.0.1" -e "ESN_MONGO_HOST=192.168.0.1" linagora/esn-sabre
```

This will launch the Sabre container, create its configuration, launch Sabre and expose on port 8001 on your host.

### Test

You can run the unit test with Docker to avoid to install all the PHP tools and dependencies locally.
Unit tests need a MongoDB container to run:

```
docker run --name mongo -d mongo:3.2.0
```

Once MongoDB container is started, you can run the unit test like this:

```
docker run -a stdout -i -t -v $PWD:/var/www --link mongo:mongo linagora/esn-sabre make
```

It will use the `linagora/esn-sabre` image, you may need to rebuid it in some cases, but the `make` command will update composer dependencies automatically.