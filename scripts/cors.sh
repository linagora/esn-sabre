#!/bin/bash
# CORS credentials, rendered from the environment at container boot.
# See doc/CONFIGURE.md § "CORS".
#
# Sourced by scripts/start.sh, after render_kerberos, which calls render_cors and defines fail().
#
# nginx reflects the request Origin in Access-Control-Allow-Origin. Access-Control-Allow-Credentials lets the
# browser send ambient credentials (cookies, a Kerberos ticket) on behalf of that origin. Without Kerberos, the
# browser has no ambient credential for Sabre, so it is kept for every origin as before. With Kerberos, any web
# page would read what it does with the visitor's ticket: only the origins of CORS_ALLOWED_ORIGINS get it. Front-ends that send
# their own Authorization header (Bearer, Basic) do not need it.
#
# Access-Control-Allow-Credentials only governs what a script may read and which preflights pass: a CORS-simple
# request (a POST with Content-Type text/plain, a form) is sent with the ticket anyway. $davserver_krb_cross_site
# tells /__krb (docker/config/kerberos.conf.template) to refuse a Negotiate request made by a web page of another
# origin than the DAV host's own or those of CORS_ALLOWED_ORIGINS. Desktop clients send no Origin.

NGINX_CORS_CONF=/etc/nginx/conf.d/cors.conf

render_cors() {
  local origin origins=() default=true

  for origin in ${CORS_ALLOWED_ORIGINS//,/ }; do
    origin=${origin,,}
    origin=${origin%/}
    [[ "$origin" =~ ^https?://[a-z0-9.-]+(:[0-9]{1,5})?$ ]] \
      || fail "CORS_ALLOWED_ORIGINS: invalid origin '$origin' (expected e.g. https://calendar.example.com)"
    origins+=("$origin")
  done

  # render_kerberos leaves its server snippet empty when Kerberos is disabled.
  if [ ${#origins[@]} -gt 0 ] || [ -s "$NGINX_KERBEROS_SERVER_CONF" ]; then
    default=""
  fi

  mkdir -p "$(dirname "$NGINX_CORS_CONF")"
  {
    echo "# Rendered by scripts/cors.sh. An empty value: nginx sends no Access-Control-Allow-Credentials."
    echo "map \$http_origin \$davserver_cors_credentials {"
    echo "    default \"$default\";"
    for origin in "${origins[@]}"; do
      echo "    \"$origin\" \"true\";"
    done
    echo "}"
    echo
    echo "# 1 when the Origin is the DAV host's own (any scheme or port) or listed in CORS_ALLOWED_ORIGINS, \"none\" without Origin."
    echo "map \$http_origin \$davserver_origin_listed {"
    echo "    default 0;"
    for origin in "${origins[@]}"; do
      echo "    \"$origin\" 1;"
    done
    echo "}"
    echo "map \"\$davserver_origin_listed|\$http_origin|\$host\" \$davserver_origin_trusted {"
    echo "    default 0;"
    echo "    \"~^1\\|\" 1;"
    echo "    \"~^0\\|\\|\" none;"
    echo "    \"~*^0\\|https?://([^/:|]+)(:[0-9]+)?\\|\\1\$\" 1;"
    echo "}"
    echo "# 1: a Negotiate request is refused, as made by a web page of an untrusted origin. Without Origin,"
    echo "# Sec-Fetch-Site: cross-site still refuses the methods that are not safe."
    echo "map \"\$davserver_origin_trusted|\$request_method|\$http_sec_fetch_site\" \$davserver_krb_cross_site {"
    echo "    default 0;"
    echo "    \"~^0\\|\" 1;"
    echo "    \"~^none\\|(GET|HEAD|OPTIONS)\\|\" 0;"
    echo "    \"~*^none\\|[^|]*\\|cross-site\$\" 1;"
    echo "}"
  } > "$NGINX_CORS_CONF"

  if [ -n "$default" ]; then
    echo "start.sh: CORS credentials allowed for every origin"
  else
    echo "start.sh: CORS credentials allowed for: ${origins[*]:-no origin}"
  fi
}
