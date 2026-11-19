#!/bin/bash
# Kerberos (SPNEGO) authentication, rendered from the environment at container boot.
# See doc/CONFIGURE.md § "Kerberos authentication".
#
# Sourced by scripts/start.sh, which calls render_kerberos and defines fail().

NGINX_KERBEROS_SERVER_CONF=/etc/nginx/snippets/kerberos_server.conf
NGINX_KERBEROS_LOCATION_CONF=/etc/nginx/snippets/kerberos_location.conf
NGINX_KERBEROS_TEMPLATE=/etc/nginx/kerberos.conf.template

render_kerberos() {
  local enabled value=${KERBEROS_ENABLED:-false}

  # Same spellings as ESN\Utils\Env::getBoolean, which reads it on the PHP side.
  value=${value,,}
  value=${value//[[:space:]]/}
  case "$value" in
    1|true|on|yes) enabled=true ;;
    0|false|off|no|"") enabled=false ;;
    *) fail "KERBEROS_ENABLED: invalid value '$KERBEROS_ENABLED' (expected true or false)" ;;
  esac

  mkdir -p "$(dirname "$NGINX_KERBEROS_SERVER_CONF")"

  if [ "$enabled" = false ]; then
    : > "$NGINX_KERBEROS_SERVER_CONF"
    : > "$NGINX_KERBEROS_LOCATION_CONF"
    return
  fi

  export KERBEROS_KEYTAB=${KERBEROS_KEYTAB:-/etc/krb5.keytab}
  export KERBEROS_LARGE_CLIENT_HEADER_BUFFERS=${KERBEROS_LARGE_CLIENT_HEADER_BUFFERS:-4 64k}

  # The realm is given to nginx explicitly: the image has no krb5.conf with a default realm.
  [[ "${KERBEROS_SERVICE_PRINCIPAL:-}" =~ ^([A-Za-z]+/[A-Za-z0-9.-]+)@([A-Za-z0-9.-]+)$ ]] \
    || fail "KERBEROS_SERVICE_PRINCIPAL: invalid value '${KERBEROS_SERVICE_PRINCIPAL:-}' (expected the service principal of the keytab, e.g. HTTP/dav.example.com@CORP.EXAMPLE.COM)"
  export NGINX_KERBEROS_SERVICE=${BASH_REMATCH[1]}
  export NGINX_KERBEROS_REALM=${BASH_REMATCH[2]}
  [ -n "${KERBEROS_REALM_DOMAINS:-}" ] \
    || fail "KERBEROS_REALM_DOMAINS: required when KERBEROS_ENABLED=true (e.g. CORP.EXAMPLE.COM:example.com)"
  # Two realms on one domain would sign alice@REALM1 and alice@REALM2 in as the same user.
  local shared_domain
  shared_domain=$(tr ',' '\n' <<< "$KERBEROS_REALM_DOMAINS" | cut -s -d: -f2- | tr -d ' \t\r' \
    | tr '[:upper:]' '[:lower:]' | sort | uniq -d | head -n 1)
  [ -z "$shared_domain" ] \
    || fail "KERBEROS_REALM_DOMAINS: several realms are mapped onto the domain '$shared_domain'"
  [[ "$KERBEROS_LARGE_CLIENT_HEADER_BUFFERS" =~ ^[1-9][0-9]*\ [1-9][0-9]*[kKmM]?$ ]] \
    || fail "KERBEROS_LARGE_CLIENT_HEADER_BUFFERS: invalid value '$KERBEROS_LARGE_CLIENT_HEADER_BUFFERS' (expected e.g. 4 64k)"
  [ -f "$KERBEROS_KEYTAB" ] \
    || fail "KERBEROS_KEYTAB: '$KERBEROS_KEYTAB' does not exist"
  # nginx workers run as www-data and read the keytab on every Negotiate request.
  runuser -u www-data -- test -r "$KERBEROS_KEYTAB" \
    || fail "KERBEROS_KEYTAB: '$KERBEROS_KEYTAB' is not readable by www-data (e.g. chown root:www-data and chmod 0640)"
  # nginx basic authentication runs in "location /", which Negotiate requests leave.
  [ -z "${NGINX_AUTH_BASIC:-}" ] \
    || fail "NGINX_AUTH_BASIC cannot be combined with KERBEROS_ENABLED=true"

  # Only our placeholders: the nginx variables ($remote_user...) stay untouched.
  envsubst '${KERBEROS_KEYTAB} ${NGINX_KERBEROS_SERVICE} ${NGINX_KERBEROS_REALM} ${KERBEROS_LARGE_CLIENT_HEADER_BUFFERS}' \
    < "$NGINX_KERBEROS_TEMPLATE" > "$NGINX_KERBEROS_SERVER_CONF" \
    || fail "could not write the nginx Kerberos configuration"
  {
    echo "# Rendered by scripts/kerberos.sh: Kerberos tickets are validated in /__krb."
    echo "if (\$http_authorization ~* \"^Negotiate\\s\") {"
    echo "  rewrite ^ /__krb last;"
    echo "}"
  } > "$NGINX_KERBEROS_LOCATION_CONF"

  echo "start.sh: Kerberos authentication enabled: service_principal=$KERBEROS_SERVICE_PRINCIPAL keytab=$KERBEROS_KEYTAB" \
    "realm_domains=[$KERBEROS_REALM_DOMAINS] header_buffers=[$KERBEROS_LARGE_CLIENT_HEADER_BUFFERS]"
}
