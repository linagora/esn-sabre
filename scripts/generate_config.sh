#!/bin/bash

sabre_mongo_host='sabre_mongo'
sabre_mongo_port='27017'
sabre_mongo_dbname='sabre'
esn_mongo_host='esn_mongo'
esn_mongo_port='27017'
esn_mongo_dbname='esn'
esn_host='esn_host'
esn_port='8080'
esn_scheme='http'
amqp_host='amqp_host'
amqp_port='5672'
amqp_login='guest'
amqp_password='guest'
amqp_vhost='/'
amqp_ssl_enabled='false'
amqp_ssl_trust_all='false'
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
[ -z "$ESN_SCHEME" ] || esn_scheme="$ESN_SCHEME"
[ -z "$AMQP_HOST" ] || amqp_host="$AMQP_HOST"
[ -z "$AMQP_PORT" ] || amqp_port="$AMQP_PORT"
[ -z "$AMQP_LOGIN" ] || amqp_login="$AMQP_LOGIN"
[ -z "$AMQP_PASSWORD" ] || amqp_password="$AMQP_PASSWORD"
[ -z "$AMQP_VHOST" ] || amqp_vhost="$AMQP_VHOST"
[ -z "$AMQP_SSL_ENABLED" ] || amqp_ssl_enabled="$AMQP_SSL_ENABLED"
[ -z "$AMQP_SSL_TRUST_ALL_CERTS" ] || amqp_ssl_trust_all="$AMQP_SSL_TRUST_ALL_CERTS"
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

# Escape a value so it can be embedded in a JSON string. Newlines are folded
# into spaces: none of these settings is expected to span lines, and a raw
# newline would make the whole config.json unparseable.
json_escape() {
  printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' -e 's/	/\\t/g' | tr '\n\r' '  '
}

# The ESN API carries technical tokens and answers with the authenticated
# identity: ESN_SCHEME=https, or a whole ESN_API_URL, lets it be reached over
# TLS. ESN_API_URL wins over ESN_SCHEME/ESN_HOST/ESN_PORT; its trailing slashes
# are dropped, since paths such as /api/... are appended to it.
# The one in use is checked here, case-insensitively, and its scheme lowercased:
# a value such as ESN_SCHEME=https:// would otherwise yield https://://host.
lowercase() {
  printf '%s' "$1" | tr 'ABCDEFGHIJKLMNOPQRSTUVWXYZ' 'abcdefghijklmnopqrstuvwxyz'
}
if [ ! -z "${ESN_API_URL}" ]
then
  esn_api_url_scheme="$(lowercase "${ESN_API_URL%%://*}")"
  case "$(lowercase "${ESN_API_URL}")" in
    http://[!/]*|https://[!/]*) ;;
    *)
      echo "generate_config.sh: ESN_API_URL: invalid URL '${ESN_API_URL}' (expected http://host... or https://host...)" >&2
      exit 1
      ;;
  esac
  esn_api_root="$(printf '%s' "${esn_api_url_scheme}://${ESN_API_URL#*://}" | sed -e 's:/*$::')"
else
  esn_scheme="$(lowercase "${esn_scheme}")"
  case "${esn_scheme}" in
    http|https) ;;
    *)
      echo "generate_config.sh: ESN_SCHEME: invalid scheme '${ESN_SCHEME}' (expected http or https)" >&2
      exit 1
      ;;
  esac
  esn_api_root="${esn_scheme}://${esn_host}:${esn_port}"
fi
esn_api_root="$(json_escape "${esn_api_root}")"

# Settings read at runtime by PHP through ESN\Utils\Env. Only the variables
# that are actually set get written out: anything absent keeps the default
# documented in doc/CONFIGURE.md.
runtime_env_vars="LDAP_SERVER
LDAP_BASE
LDAP_FILTER
LDAP_ADMIN_DN
LDAP_ADMIN_PASSWORD
LDAP_USERNAME_MODE
LDAP_TIMEOUT
SABRE_ADMIN_LOGIN
SABRE_ADMIN_PASSWORD
SABRE_IMPERSONATION_ENABLED
KERBEROS_ENABLED
KERBEROS_REALM_DOMAINS
KERBEROS_LDAP_PRINCIPAL_FILTER
SABRE_MIGRATE_ON_STARTUP
AUTO_PROVISION
PRINCIPAL_PRIVACY
TRUSTED_VIDEO_URL_BASE
TRUSTED_ATTACH_URL_BASE
CALDAV_BINARY_ATTACHMENT_MODE
CARDDAV_INLINE_ATTACHMENT_MODE
SABRE_ENFORCE_RFC_6638
SABRE_EMAIL_VALARM_RECIPIENT_SCHEDULING
TW_CAL_REPLY_PROPAGATION_THRESHOLD
SABRE_MAX_RECURRENCES
SABRE_CONTACT_SEARCH_MIN_LENGTH
LOG_TRACE"

environment_entries="    \"SABRE_ENV\": \"$(json_escape "${sabre_env}")\""

for env_var in ${runtime_env_vars}
do
  eval "env_value=\${${env_var}}"

  if [ ! -z "${env_value}" ]
  then
    environment_entries="${environment_entries},
    \"${env_var}\": \"$(json_escape "${env_value}")\""
  fi
done

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
    \"password\": \"${amqp_password}\",
    \"vhost\": \"${amqp_vhost}\",
    \"sslEnabled\": ${amqp_ssl_enabled},
    \"sslTrustAllCerts\": ${amqp_ssl_trust_all}
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
    \"apiRoot\": \"${esn_api_root}\",
    \"calendarRoot\": \"${esn_api_root}/calendar/api\"
  },
  \"environment\": {
${environment_entries}
  }
}"

# printf, not echo: dash's echo expands backslash sequences and would undo the
# JSON escaping done above.
printf '%s\n' "$config"
