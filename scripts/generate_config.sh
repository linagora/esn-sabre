#!/bin/bash

sabre_mongo_host="sabre_mongo"
sabre_mongo_port="27017"
esn_mongo_host="esn_mongo"
esn_mongo_port="27017"
esn_mongo_dbname="esn"
esn_host="esn_host"
esn_port="8080"
amqp_host='amqp_host'
amqp_port='5672'
amqp_login='guest'
amqp_password='guest'
mongo_timeout="10000"

[ -z "$SABRE_MONGO_HOST" ] || sabre_mongo_host="$SABRE_MONGO_HOST"
[ -z "$SABRE_MONGO_PORT" ] || sabre_mongo_port="$SABRE_MONGO_PORT"
[ -z "$ESN_MONGO_HOST" ] || esn_mongo_host="$ESN_MONGO_HOST"
[ -z "$ESN_MONGO_PORT" ] || esn_mongo_port="$ESN_MONGO_PORT"
[ -z "$ESN_MONGO_DBNAME" ] || esn_mongo_dbname="$ESN_MONGO_DBNAME"
[ -z "$MONGO_TIMEOUT" ] || mongo_timeout="$MONGO_TIMEOUT"
[ -z "$ESN_HOST" ] || esn_host="$ESN_HOST"
[ -z "$ESN_PORT" ] || esn_port="$ESN_PORT"
[ -z "$AMQP_HOST" ] || amqp_host="$AMQP_HOST"
[ -z "$AMQP_PORT" ] || amqp_port="$AMQP_PORT"
[ -z "$AMQP_LOGIN" ] || amqp_login"=$AMQP_LOGIN"
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
      \"connectionString\" : \"mongodb://${esn_mongo_host}:${esn_mongo_port}/\",
      \"connectionOptions\": {
        \"w\": 1,
        \"fsync\": true,
        \"connectTimeoutMS\": ${mongo_timeout}
      }
    },
    \"sabre\": {
      \"db\": \"sabre\",
      \"connectionString\" : \"mongodb://${sabre_mongo_host}:${sabre_mongo_port}/\",
      \"connectionOptions\": {
        \"w\": 1,
        \"fsync\": true,
        \"connectTimeoutMS\": ${mongo_timeout}
      }
    }
  },
  \"esn\": {
    \"apiRoot\": \"http://${esn_host}:${esn_port}/api\",
    \"calendarRoot\": \"http://${esn_host}:${esn_port}/calendar/api\"
  }
}"

echo $config
