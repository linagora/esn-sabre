#!/bin/bash

# Cleaning APT
apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# Own mongo settings
sabre_mongo_host="172.12.0.1"
sabre_mongo_port="27017"
[ -z "$SABRE_MONGO_PORT_27017_TCP_ADDR" ] || sabre_mongo_host="$SABRE_MONGO_PORT_27017_TCP_ADDR"
[ -z "$SABRE_MONGO_PORT_27017_TCP_PORT" ] || sabre_mongo_host="$SABRE_MONGO_PORT_27017_TCP_PORT"

#
esn_mongo_host="172.12.0.1"
esn_mongo_port="27017"
esn_mongo_db="rse"
[ -z "$ESN_MONGO_PORT_27017_TCP_ADDR" ] || esn_mongo_host="$ESN_MONGO_PORT_27017_TCP_ADDR"
[ -z "$ESN_MONGO_PORT_27017_TCP_PORT" ] || esn_mongo_port="$ESN_MONGO_PORT_27017_TCP_PORT"
[ -z "$MONGO_DB" ]                      || esn_mongo_db="$MONGO_DB"

if [ "$HAS_OWN_MONGO" = true ] ; then
    json_db_sabre=",\"sabre\": {
      \"db\": \"sabre\",
      \"connectionString\" : \"mongodb://$sabre_mongo_host:$sabre_mongo_port/\",
      \"connectionOptions\": {
        \"w\": 1,
        \"fsync\": true,
        \"connectTimeoutMS\": 10000
      }
    }"
fi

config="{
  \"webserver\": {
    \"baseUri\": \"/\",
    \"allowOrigin\": \"*\",
    \"realm\": \"ESN\"
  },
  \"database\": {
    \"esn\": {
      \"db\": \"$esn_mongo_db\",
      \"connectionString\" : \"mongodb://$esn_mongo_host:$esn_mongo_port/\",
      \"connectionOptions\": {
        \"w\": 1,
        \"fsync\": true,
        \"connectTimeoutMS\": 10000
      }
    }
    ${json_db_sabre}
  },
  \"esn\": {
    \"apiRoot\": \"http://esn_host:8080/api\",
    \"calendarRoot\": \"http://esn_host:8080/calendar/api\"
  }
}"

echo $config > config.json

/usr/bin/supervisord
