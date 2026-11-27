#!/bin/bash

# Deployments may still start this script with `sh` (dash): re-run it with bash.
if [ -z "$BASH_VERSION" ]; then
  exec bash "$0" "$@"
fi

# Nginx rate limiting, rendered from the environment at container boot.
# See doc/CONFIGURE.md § "Nginx rate limiting".
export NGINX_RATE_LIMIT=${NGINX_RATE_LIMIT:-50r/s}
export NGINX_RATE_ZONE_SIZE=${NGINX_RATE_ZONE_SIZE:-10m}
export NGINX_RATE_BURST=${NGINX_RATE_BURST:-100}
export NGINX_RATE_LIMIT_STATUS=${NGINX_RATE_LIMIT_STATUS:-429}
NGINX_TRUSTED_PROXIES=${NGINX_TRUSTED_PROXIES-0.0.0.0/0,::/0}
NGINX_TRUSTED_CLIENTS_CIDR=${NGINX_TRUSTED_CLIENTS_CIDR:-}

NGINX_RATE_LIMIT_CONF=/etc/nginx/conf.d/rate_limit.conf
NGINX_RATE_LIMIT_LOCATION_CONF=/etc/nginx/snippets/rate_limit_location.conf
NGINX_REAL_IP_CONF=/etc/nginx/conf.d/real_ip.conf

fail() {
  echo "start.sh: $*" >&2
  exit 1
}

# Prints a CIDR list (comma or space separated) as one normalized CIDR per line.
# A bare address gets /32 (IPv4) or /128 (IPv6). Fails on any invalid value.
parse_cidr_list() {
  local variable=$1 value=$2 cidr address prefix max_prefix
  for cidr in ${value//,/ }; do
    address=${cidr%%/*}
    if [ "$address" = "$cidr" ]; then
      prefix=""
    else
      prefix=${cidr#*/}
    fi
    if php -r 'exit(filter_var($argv[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false ? 1 : 0);' -- "$address"; then
      max_prefix=32
    elif php -r 'exit(filter_var($argv[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false ? 1 : 0);' -- "$address"; then
      max_prefix=128
    else
      fail "$variable: invalid IP address in '$cidr'"
    fi
    if [ -z "$prefix" ]; then
      prefix=$max_prefix
    elif ! [[ "$prefix" =~ ^[0-9]{1,3}$ ]] || [ "$prefix" -gt "$max_prefix" ]; then
      fail "$variable: invalid prefix length in '$cidr' (expected 0 to $max_prefix)"
    fi
    echo "$address/$prefix"
  done
}

render_rate_limit() {
  local rate_limit_enabled=true trusted_proxies trusted_clients cidr

  case "${NGINX_RATE_LIMIT,,}" in
    off|false|0|disabled) rate_limit_enabled=false ;;
  esac

  trusted_proxies=$(parse_cidr_list NGINX_TRUSTED_PROXIES "$NGINX_TRUSTED_PROXIES") || exit 1

  mkdir -p "$(dirname "$NGINX_RATE_LIMIT_LOCATION_CONF")"

  if [ -n "$trusted_proxies" ]; then
    {
      echo "real_ip_header X-Forwarded-For;"
      echo "real_ip_recursive on;"
      for cidr in $trusted_proxies; do
        echo "set_real_ip_from $cidr;"
      done
    } > "$NGINX_REAL_IP_CONF"
  else
    : > "$NGINX_REAL_IP_CONF"
  fi

  if [ "$rate_limit_enabled" = false ]; then
    : > "$NGINX_RATE_LIMIT_CONF"
    : > "$NGINX_RATE_LIMIT_LOCATION_CONF"
    echo "start.sh: nginx rate limiting disabled, trusted_proxies=[${trusted_proxies//$'\n'/ }]"
    return
  fi

  [[ "$NGINX_RATE_LIMIT" =~ ^[1-9][0-9]*r/[sm]$ ]] \
    || fail "NGINX_RATE_LIMIT: invalid rate '$NGINX_RATE_LIMIT' (expected e.g. 50r/s or 600r/m, or 'off')"
  [[ "$NGINX_RATE_BURST" =~ ^[0-9]+$ ]] \
    || fail "NGINX_RATE_BURST: invalid burst '$NGINX_RATE_BURST' (expected a non-negative integer)"
  [[ "$NGINX_RATE_ZONE_SIZE" =~ ^[1-9][0-9]*[kKmM]?$ ]] \
    || fail "NGINX_RATE_ZONE_SIZE: invalid size '$NGINX_RATE_ZONE_SIZE' (expected e.g. 10m)"
  [[ "$NGINX_RATE_LIMIT_STATUS" =~ ^[45][0-9][0-9]$ ]] \
    || fail "NGINX_RATE_LIMIT_STATUS: invalid status '$NGINX_RATE_LIMIT_STATUS' (expected 400 to 599)"

  trusted_clients=$(parse_cidr_list NGINX_TRUSTED_CLIENTS_CIDR "$NGINX_TRUSTED_CLIENTS_CIDR") || exit 1
  for cidr in $trusted_clients; do
    if [ "${cidr#*/}" = 0 ]; then
      fail "NGINX_TRUSTED_CLIENTS_CIDR: '$cidr' would exempt every client; use NGINX_RATE_LIMIT=off to disable rate limiting"
    fi
  done

  {
    echo "# Rendered by scripts/start.sh. Requests with an empty key are not rate limited."
    echo "geo \$davserver_limited {"
    echo "    default 1;"
    for cidr in $trusted_clients; do
      echo "    $cidr 0;"
    done
    echo "}"
    echo "map \$davserver_limited \$davserver_limit_key {"
    echo "    0 \"\";"
    echo "    1 \$binary_remote_addr;"
    echo "}"
    echo "map \$status \$davserver_retry_after {"
    echo "    $NGINX_RATE_LIMIT_STATUS 1;"
    echo "    default \"\";"
    echo "}"
    echo "limit_req_zone \$davserver_limit_key zone=davserver:$NGINX_RATE_ZONE_SIZE rate=$NGINX_RATE_LIMIT;"
  } > "$NGINX_RATE_LIMIT_CONF"

  {
    echo "limit_req zone=davserver burst=$NGINX_RATE_BURST nodelay;"
    echo "limit_req_status $NGINX_RATE_LIMIT_STATUS;"
    echo "limit_req_log_level warn;"
    echo "add_header 'Retry-After' \$davserver_retry_after always;"
  } > "$NGINX_RATE_LIMIT_LOCATION_CONF"

  echo "start.sh: nginx rate limiting enabled: limit=$NGINX_RATE_LIMIT burst=$NGINX_RATE_BURST" \
    "status=$NGINX_RATE_LIMIT_STATUS zone_size=$NGINX_RATE_ZONE_SIZE" \
    "trusted_proxies=[${trusted_proxies//$'\n'/ }] trusted_clients=[${trusted_clients//$'\n'/ }]"
}

render_rate_limit

# Kerberos (SPNEGO) authentication.
source "$(dirname "${BASH_SOURCE[0]}")/kerberos.sh"
render_kerberos

# CORS credentials: depends on whether Kerberos is enabled.
source "$(dirname "${BASH_SOURCE[0]}")/cors.sh"
render_cors

# CalDAV clients embed file bytes as Base64 in the request body. Deployments
# that accept attachments can raise this limit without changing Nginx's default.
NGINX_CLIENT_MAX_BODY_SIZE=${NGINX_CLIENT_MAX_BODY_SIZE:-1m}
[[ "$NGINX_CLIENT_MAX_BODY_SIZE" =~ ^[1-9][0-9]*[kKmM]?$ ]] \
  || fail "NGINX_CLIENT_MAX_BODY_SIZE: invalid size (expected a positive size such as 1m or 16m)"
printf 'client_max_body_size %s;\n' "$NGINX_CLIENT_MAX_BODY_SIZE" > /etc/nginx/conf.d/client_max_body_size.conf \
  || fail "could not write the nginx request body limit"

# PHP-FPM slow log and status page, rendered from the environment at container boot.
# See doc/CONFIGURE.md § "PHP-FPM slow log and status page".
PHP_FPM_SLOWLOG_TIMEOUT=${PHP_FPM_SLOWLOG_TIMEOUT:-5s}
PHP_FPM_SLOWLOG_TRACE_DEPTH=${PHP_FPM_SLOWLOG_TRACE_DEPTH:-20}
# The status page is a dev tool: on by default with SABRE_ENV=dev only. In production it is off unless
# PHP_FPM_STATUS_LISTEN asks for it (a monitoring probe, on a private port).
if [ "${SABRE_ENV:-production}" = dev ]; then
  PHP_FPM_STATUS_LISTEN=${PHP_FPM_STATUS_LISTEN:-127.0.0.1:8081}
else
  PHP_FPM_STATUS_LISTEN=${PHP_FPM_STATUS_LISTEN:-off}
fi
PHP_FPM_POOL_CONF=/etc/php/${PHPVERSION:-8.4}/fpm/pool.d/www.conf
# The status page is served by a dedicated FPM pool, so it answers while every worker of the main pool is busy.
PHP_FPM_STATUS_POOL_LISTEN=127.0.0.1:9001
NGINX_FPM_STATUS_CONF=/etc/nginx/conf.d/fpm_status.conf

# Sets `key = value` in the FPM pool configuration, whether the key is commented out, already set or absent.
set_fpm_pool_setting() {
  local key=$1 value=$2 key_pattern
  key_pattern="^;\?[[:space:]]*${key//./\\.}[[:space:]]*="
  if grep -q "$key_pattern" "$PHP_FPM_POOL_CONF"; then
    sed -i "s|${key_pattern}.*|${key} = ${value}|" "$PHP_FPM_POOL_CONF"
  else
    echo "${key} = ${value}" >> "$PHP_FPM_POOL_CONF"
  fi
}

render_php_fpm_slowlog() {
  case "${PHP_FPM_SLOWLOG_TIMEOUT,,}" in
    off|false|disabled) PHP_FPM_SLOWLOG_TIMEOUT=0 ;;
  esac
  [[ "$PHP_FPM_SLOWLOG_TIMEOUT" =~ ^[0-9]+[smhd]?$ ]] \
    || fail "PHP_FPM_SLOWLOG_TIMEOUT: invalid timeout '$PHP_FPM_SLOWLOG_TIMEOUT' (expected e.g. 5s, 500 or 'off')"
  [[ "$PHP_FPM_SLOWLOG_TRACE_DEPTH" =~ ^[1-9][0-9]*$ ]] \
    || fail "PHP_FPM_SLOWLOG_TRACE_DEPTH: invalid depth '$PHP_FPM_SLOWLOG_TRACE_DEPTH' (expected a positive integer)"

  set_fpm_pool_setting request_slowlog_timeout "$PHP_FPM_SLOWLOG_TIMEOUT"
  set_fpm_pool_setting request_slowlog_trace_depth "$PHP_FPM_SLOWLOG_TRACE_DEPTH"
  set_fpm_pool_setting slowlog /proc/self/fd/2

  if [[ "$PHP_FPM_SLOWLOG_TIMEOUT" =~ ^0+[smhd]?$ ]]; then
    echo "start.sh: php-fpm slow log disabled"
  else
    echo "start.sh: php-fpm slow log enabled: timeout=$PHP_FPM_SLOWLOG_TIMEOUT trace_depth=$PHP_FPM_SLOWLOG_TRACE_DEPTH"
  fi
}

render_php_fpm_status() {
  case "${PHP_FPM_STATUS_LISTEN,,}" in
    off|false|disabled)
      # Nothing in FPM either: no worker of the main pool answers /status or /ping.
      : > "$NGINX_FPM_STATUS_CONF"
      echo "start.sh: php-fpm status page disabled (SABRE_ENV=${SABRE_ENV:-production}, set PHP_FPM_STATUS_LISTEN to enable it)"
      return
      ;;
  esac
  [[ "$PHP_FPM_STATUS_LISTEN" =~ ^([0-9.]+:|\[[0-9a-fA-F:]+\]:)?[1-9][0-9]{0,4}$ ]] \
    || fail "PHP_FPM_STATUS_LISTEN: invalid address '$PHP_FPM_STATUS_LISTEN' (expected e.g. 127.0.0.1:8081, 8081 or 'off')"

  # pm.status_listen serves the status of the main pool from a dedicated pool, which reads the status
  # URIs of the main pool: they are set there too, so its workers answer them on the main socket.
  # The public server (default.conf) answers 404 to these two URIs before they reach that socket.
  set_fpm_pool_setting pm.status_path /status
  set_fpm_pool_setting ping.path /ping
  set_fpm_pool_setting pm.status_listen "$PHP_FPM_STATUS_POOL_LISTEN"

  {
    echo "# Rendered by scripts/start.sh: PHP-FPM status and ping pages, served by a dedicated FPM pool."
    echo "server {"
    echo "    listen $PHP_FPM_STATUS_LISTEN;"
    echo "    access_log off;"
    echo "    location ~ ^/(status|ping)\$ {"
    echo "        include fastcgi_params;"
    echo "        fastcgi_param SCRIPT_NAME \$uri;"
    echo "        fastcgi_param SCRIPT_FILENAME \$uri;"
    echo "        fastcgi_pass $PHP_FPM_STATUS_POOL_LISTEN;"
    echo "    }"
    echo "    location / {"
    echo "        return 404;"
    echo "    }"
    echo "}"
  } > "$NGINX_FPM_STATUS_CONF"
  echo "start.sh: php-fpm status page exposed by nginx on $PHP_FPM_STATUS_LISTEN (/status, /ping)"
}

if [ -f "$PHP_FPM_POOL_CONF" ]; then
  render_php_fpm_slowlog
  render_php_fpm_status
else
  echo "start.sh: $PHP_FPM_POOL_CONF not found, php-fpm slow log and status page not configured" >&2
  : > "$NGINX_FPM_STATUS_CONF"
fi

if [ -f /etc/nginx/sites-available/default.template ]; then
  cp /etc/nginx/sites-available/default.template /etc/nginx/sites-available/default
fi

/root/set_nginx_htpasswd.sh

# The configuration is generated aside and checked first: an invalid setting stops the
# container instead of booting on an empty config.json. A config.json mounted read-only
# (a Kubernetes ConfigMap) is the deployment's own configuration: it is kept, not an error.
# It carries the database and AMQP passwords: mktemp creates it 0600, owned by root, and it is
# removed on every path, failures included.
GENERATED_CONFIG=$(mktemp /tmp/config.json.XXXXXX) \
  || fail "could not create a temporary file for /var/www/config.json"
fail_config() {
  rm -f "$GENERATED_CONFIG"
  fail "$@"
}
# SABRE_CONFIG_JSON, when set, is the whole configuration, used as is instead of the generated one.
# The integration tests pass it, as their Docker daemon may not share a file to mount.
if [ -n "${SABRE_CONFIG_JSON:-}" ]; then
  printf '%s' "$SABRE_CONFIG_JSON" > "$GENERATED_CONFIG" \
    || fail_config "could not write SABRE_CONFIG_JSON to /var/www/config.json"
else
  sh /var/www/scripts/generate_config.sh > "$GENERATED_CONFIG" \
    || fail_config "could not generate /var/www/config.json, see the error above"
fi
if [ -e /var/www/config.json ] && [ ! -w /var/www/config.json ]; then
  echo "start.sh: /var/www/config.json is read-only, keeping the mounted configuration" >&2
else
  cat "$GENERATED_CONFIG" > /var/www/config.json \
    || fail_config "could not write /var/www/config.json"
  # Readable by root (this script and the startup PHP scripts) and by php-fpm (www-data) only:
  # it carries the passwords too. A file that cannot be handed to www-data keeps its mode,
  # since php-fpm would otherwise lose it.
  if chown root:www-data /var/www/config.json 2>/dev/null; then
    chmod 0640 /var/www/config.json \
      || fail_config "could not restrict the permissions of /var/www/config.json"
  else
    echo "start.sh: could not give /var/www/config.json to the www-data group, its permissions are left unchanged" >&2
  fi
fi
rm -f "$GENERATED_CONFIG"

nginx -t || fail "invalid nginx configuration, see the error above"

# Indexes are created here once, not on every request. Without them every query is a collection scan,
# so retry while MongoDB is not reachable yet, then refuse to start. See doc/storage/MONGO.md § "Index creation".
MONGO_INDEX_CREATION_ATTEMPTS=${MONGO_INDEX_CREATION_ATTEMPTS:-30}
MONGO_INDEX_CREATION_RETRY_DELAY=${MONGO_INDEX_CREATION_RETRY_DELAY:-2}
MONGO_INDEX_CREATION_REQUIRED=${MONGO_INDEX_CREATION_REQUIRED:-true}
[[ "$MONGO_INDEX_CREATION_ATTEMPTS" =~ ^[1-9][0-9]*$ ]] \
  || fail "MONGO_INDEX_CREATION_ATTEMPTS: invalid value (expected a positive integer)"
[[ "$MONGO_INDEX_CREATION_RETRY_DELAY" =~ ^[0-9]+$ ]] \
  || fail "MONGO_INDEX_CREATION_RETRY_DELAY: invalid value (expected a number of seconds)"
[[ "$MONGO_INDEX_CREATION_REQUIRED" =~ ^(true|false)$ ]] \
  || fail "MONGO_INDEX_CREATION_REQUIRED: invalid value (expected true or false)"

create_indexes() {
  local attempt
  for ((attempt = 1; attempt <= MONGO_INDEX_CREATION_ATTEMPTS; attempt++)); do
    php /var/www/scripts/create-indexes.php /var/www/config.json && return 0
    echo "start.sh: MongoDB index creation failed (attempt $attempt/$MONGO_INDEX_CREATION_ATTEMPTS)" >&2
    if ((attempt < MONGO_INDEX_CREATION_ATTEMPTS)); then
      sleep "$MONGO_INDEX_CREATION_RETRY_DELAY"
    fi
  done
  return 1
}

if ! create_indexes; then
  if [ "$MONGO_INDEX_CREATION_REQUIRED" = "true" ]; then
    fail "ERROR: MongoDB index creation failed after $MONGO_INDEX_CREATION_ATTEMPTS attempts," \
      "refusing to start without indexes (every query would be a collection scan)." \
      "See doc/storage/MONGO.md, or set MONGO_INDEX_CREATION_REQUIRED=false to start anyway"
  fi
  echo "start.sh: ERROR: MongoDB index creation failed after $MONGO_INDEX_CREATION_ATTEMPTS attempts," \
    "starting WITHOUT indexes because MONGO_INDEX_CREATION_REQUIRED=false: every query will be a collection scan." \
    "Create them manually, see doc/storage/MONGO.md" >&2
fi

# Run migrations in the background so DAV startup does not wait; keep stdout/stderr for operational logs.
php /var/www/scripts/migrate-database.php --on-startup /var/www/config.json < /dev/null &
# Same for the sync change logs: delete the changes older than their retention. See doc/storage/MONGO.md § "calendarchanges".
php /var/www/scripts/prune-change-logs.php --on-startup /var/www/config.json < /dev/null &

/usr/bin/supervisord
