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
NGINX_TRUSTED_PROXIES=${NGINX_TRUSTED_PROXIES:-}
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

if [ -f /etc/nginx/sites-available/default.template ]; then
  cp /etc/nginx/sites-available/default.template /etc/nginx/sites-available/default
fi

/root/set_nginx_htpasswd.sh

sh /var/www/scripts/generate_config.sh > /var/www/config.json

nginx -t || fail "invalid nginx configuration, see the error above"

# Indexes are created here once, not on every request. A failure must not keep sabre from starting.
php /var/www/scripts/create-indexes.php /var/www/config.json || echo "WARNING: MongoDB index creation failed, see doc/storage/MONGO.md to create them manually" >&2

/usr/bin/supervisord
