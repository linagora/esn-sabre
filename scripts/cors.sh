#!/bin/bash
# CORS credentials, rendered from the environment at container boot.
# See doc/CONFIGURE.md § "CORS".
#
# Sourced by scripts/start.sh, after render_kerberos, which calls render_cors and defines fail().
#
# nginx reflects the request Origin in Access-Control-Allow-Origin. Access-Control-Allow-Credentials lets the
# browser send ambient credentials (cookies, a Kerberos ticket) on behalf of that origin. Without Kerberos, the
# browser has no ambient credential for Sabre, so it is kept for every origin as before. With Kerberos, any web
# page would act with the visitor's ticket: only the origins of CORS_ALLOWED_ORIGINS get it. Front-ends that send
# their own Authorization header (Bearer, Basic) do not need it.

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
  } > "$NGINX_CORS_CONF"

  if [ -n "$default" ]; then
    echo "start.sh: CORS credentials allowed for every origin"
  else
    echo "start.sh: CORS credentials allowed for: ${origins[*]:-no origin}"
  fi
}
