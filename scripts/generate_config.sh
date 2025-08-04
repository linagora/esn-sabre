#!/bin/bash

sabre_mongo_host='sabre_mongo'
sabre_mongo_port='27017'
sabre_mongo_dbname='sabre'
esn_mongo_host='esn_mongo'
esn_mongo_port='27017'
esn_mongo_dbname='esn'
esn_host='esn_host'
esn_port='8080'
amqp_host='amqp_host'
amqp_port='5672'
amqp_login='guest'
amqp_password='guest'
mongo_timeout='10000'
sabre_env='production'

[ -z "$SABRE_MONGO_HOST" ] || sabre_mongo_host="$SABRE_MONGO_HOST"
[ -z "$SABRE_MONGO_PORT" ] || sabre_mongo_port="$SABRE_MONGO_PORT"
[ -z "$SABRE_MONGO_DBNAME" ] || sabre_mongo_dbname="$SABRE_MONGO_DBNAME"
[ -z "$ESN_MONGO_HOST" ] || esn_mongo_host="$ESN_MONGO_HOST"
[ -z "$ESN_MONGO_PORT" ] || esn_mongo_port="$ESN_MONGO_PORT"
[ -z "$ESN_MONGO_DBNAME" ] || esn_mongo_dbname="$ESN_MONGO_DBNAME"
[ -z "$MONGO_TIMEOUT" ] || mongo_timeout="$MONGO_TIMEOUT"
[ -z "$ESN_HOST" ] || esn_host="$ESN_HOST"
[ -z "$ESN_PORT" ] || esn_port="$ESN_PORT"
[ -z "$AMQP_HOST" ] || amqp_host="$AMQP_HOST"
[ -z "$AMQP_PORT" ] || amqp_port="$AMQP_PORT"
[ -z "$AMQP_LOGIN" ] || amqp_login="$AMQP_LOGIN"
[ -z "$AMQP_PASSWORD" ] || amqp_password="$AMQP_PASSWORD"
[ -z "$SABRE_ENV" ] || sabre_env="$SABRE_ENV"

if [ ! -z "${ESN_MONGO_USER}" ]
then
  esn_mongo_connectionstring="mongodb://${ESN_MONGO_USER}:${ESN_MONGO_PASSWORD}@${ESN_MONGO_URI:-${esn_mongo_host}:${esn_mongo_port}/${esn_mongo_dbname}}"
else
  esn_mongo_connectionstring="mongodb://${ESN_MONGO_URI:-${esn_mongo_host}:${esn_mongo_port}/${esn_mongo_dbname}}"
fi

if [ ! -z "${SABRE_MONGO_USER}" ]
then
  sabre_mongo_connectionstring="mongodb://${SABRE_MONGO_USER}:${SABRE_MONGO_PASSWORD}@${SABRE_MONGO_URI:-${sabre_mongo_host}:${sabre_mongo_port}/${sabre_mongo_dbname}}"
else
  sabre_mongo_connectionstring="mongodb://${SABRE_MONGO_URI:-${sabre_mongo_host}:${sabre_mongo_port}/${sabre_mongo_dbname}}"
fi

config="{
  \"webserver\": {
    \"baseUri\": \"/\",
    \"allowOrigin\": \"*\",
    \"realm\": \"ESN\"
  },
  \"amqp\": {
    \"host\": \"${amqp_host}\",
    \"port\": \"${amqp_port}\",
    \"login\": \"${amqp_login}\",
    \"password\": \"${amqp_password}\"
  },
  \"database\": {
    \"esn\": {
      \"connectionString\" : \"${esn_mongo_connectionstring}\",
      \"connectionOptions\": {
        \"w\": 1,
        \"fsync\": true,
        \"connectTimeoutMS\": ${mongo_timeout}
      }
    },
    \"sabre\": {
      \"connectionString\" : \"${sabre_mongo_connectionstring}\",
      \"connectionOptions\": {
        \"w\": 1,
        \"fsync\": true,
        \"connectTimeoutMS\": ${mongo_timeout}
      }
    }
  },
  \"esn\": {
    \"apiRoot\": \"http://${esn_host}:${esn_port}\",
    \"calendarRoot\": \"http://${esn_host}:${esn_port}/calendar/api\"
  },
  \"environment\": {
    \"SABRE_ENV\": \"${sabre_env}\"
  }
}"

echo "$config"
