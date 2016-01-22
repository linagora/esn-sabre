#!/bin/bash

if [ "$HAS_OWN_MONGO" = true ] ; then
    json_db_sabre=",\"sabre\": {
      \"db\": \"sabre\",
      \"connectionString\" : \"mongodb://sabre_mongo:27017/\",
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
      \"db\": \"esn\",
      \"connectionString\" : \"mongodb://esn_mongo:27017/\",
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
