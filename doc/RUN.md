## Run

We provide `esn-sabre` as a docker container.

### Build

```
docker build -t linagora/esn-sabre .
```

### Run

In order to run, the ESN sabre instance must access to the ESN, and mongo instances. They can be configured from the run command using these optional environment variables:

- SABRE_MONGO_HOST: Mongodb instance used to store sabre data, defaults to 'sabre_mongo'
- SABRE_MONGO_PORT: Port used by the Mongodb instance defined above, defaults to '27017'
- SABRE_MONGO_DBNAME: Sabre database name in the MongoDB instance, defaults to 'sabre'
- SABRE_ENV: Specify if Sabre is in `dev` mode or `production` mode (default).
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