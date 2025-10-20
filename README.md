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

## Run

Refer to [this section](doc/RUN.md) for running the project.

### Test

Please refer to [this document](doc/TESTING.md) for running project tests.
