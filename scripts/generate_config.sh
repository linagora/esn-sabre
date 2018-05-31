#!/bin/bash

esn_mongo_dbname="esn"
esn_mongo_connectionstring="mongodb://${ESN_MONGO_HOST:-esn_mongo}:${ESN_MONGO_PORT:-27017}/"
sabre_mongo_connectionstring="mongodb://${SABRE_MONGO_HOST:-sabre_mongo}:${SABRE_MONGO_PORT:-27017}/"
esn_host="esn_host"
esn_port="8080"
amqp_host='amqp_host'
amqp_port='5672'
amqp_login='guest'
amqp_password='guest'
mongo_timeout="10000"

if [ ! -z $ESN_MONGO_CONNECTION_STRING ] ; then
  esn_mongo_connectionstring="$ESN_MONGO_CONNECTION_STRING"
fi

if [ ! -z $SABRE_MONGO_CONNECTION_STRING ] ; then
  sabre_mongo_connectionstring="$SABRE_MONGO_CONNECTION_STRING"
fi

[ -z "$ESN_MONGO_DBNAME" ] || esn_mongo_dbname="$ESN_MONGO_DBNAME"
[ -z "$MONGO_TIMEOUT" ] || mongo_timeout="$MONGO_TIMEOUT"
[ -z "$ESN_HOST" ] || esn_host="$ESN_HOST"
[ -z "$ESN_PORT" ] || esn_port="$ESN_PORT"
[ -z "$AMQP_HOST" ] || amqp_host="$AMQP_HOST"
[ -z "$AMQP_PORT" ] || amqp_port="$AMQP_PORT"
[ -z "$AMQP_LOGIN" ] || amqp_login="$AMQP_LOGIN"
[ -z "$AMQP_PASSWORD" ] || amqp_password="$AMQP_PASSWORD"

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
      \"db\": \"${esn_mongo_dbname}\",
      \"connectionString\" : \"${esn_mongo_connectionstring}\",
      \"connectionOptions\": {
        \"w\": 1,
        \"fsync\": true,
        \"connectTimeoutMS\": ${mongo_timeout}
      }
    },
    \"sabre\": {
      \"db\": \"sabre\",
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
  }
}"

echo $config
