#!/bin/bash
#
# Integration test of the nginx refusal of cross-site Kerberos requests of the production image
# (see doc/CONFIGURE.md § "CORS" and § "Kerberos authentication").
#
# Usage: tests/nginx/kerberos_cross_site_test.sh [image]   (default: esn_sabre_nginx_test)
#
# No KDC is needed: the keytab is a dummy file and the Negotiate token is garbage. A request that passes the
# origin check reaches auth_gss, which fails on the dummy keytab with its own error page; a refused one gets
# 403 with the "cross-site request" body. No MongoDB is needed either (see WITHOUT_MONGO in rate_limit_test.sh).
set -uo pipefail

IMAGE=${1:-esn_sabre_nginx_test}
CONTAINERS=()
FAILURES=0
KEYTAB_DIR=$(mktemp -d)

cleanup() {
  if [ ${#CONTAINERS[@]} -gt 0 ]; then
    docker rm -f "${CONTAINERS[@]}" >/dev/null 2>&1 || true
  fi
  rm -rf "$KEYTAB_DIR"
}
trap cleanup EXIT

pass() { echo "  PASS: $*"; }
failed() { echo "  FAIL: $*"; FAILURES=$((FAILURES + 1)); }

WITHOUT_MONGO=(-e MONGO_INDEX_CREATION_ATTEMPTS=1 -e MONGO_INDEX_CREATION_REQUIRED=false)
printf 'not a keytab' > "$KEYTAB_DIR/dav.keytab"
chmod 0644 "$KEYTAB_DIR/dav.keytab"
KERBEROS=(-e KERBEROS_ENABLED=true -e KERBEROS_SERVICE_PRINCIPAL=HTTP/dav.example.com@CORP.EXAMPLE.COM
  -e KERBEROS_REALM_DOMAINS=CORP.EXAMPLE.COM:example.com -e KERBEROS_KEYTAB=/tmp/dav.keytab)
NEGOTIATE=(-H 'Authorization: Negotiate Z2FyYmFnZQ==')
REFUSED='Kerberos authentication refused for a cross-site request'

# Starts the image with the given `-e` options, waits for nginx and prints the container id. The keytab is
# copied in rather than bind-mounted: on CI the Docker daemon does not see this host's temporary directory.
start() {
  local cid
  cid=$(docker create "${WITHOUT_MONGO[@]}" "$@" "$IMAGE") || return 1
  CONTAINERS+=("$cid")
  docker cp "$KEYTAB_DIR/dav.keytab" "$cid:/tmp/dav.keytab" >/dev/null || return 1
  docker start "$cid" >/dev/null || return 1
  for _ in $(seq 1 60); do
    if [ "$(docker exec "$cid" curl -s -o /dev/null -w '%{http_code}' -X OPTIONS http://127.0.0.1/ 2>/dev/null)" = 200 ]; then
      echo "$cid"
      return 0
    fi
    if [ "$(docker inspect -f '{{.State.Running}}' "$cid")" != true ]; then
      docker logs "$cid" >&2
      return 1
    fi
    sleep 0.5
  done
  docker logs "$cid" >&2
  return 1
}

# Sends a CORS-simple POST (or the method of a later -X) from inside container $1 with the extra curl options
# $2..., prints the body then the status.
post() {
  local cid=$1
  shift
  docker exec "$cid" curl -s -w ' %{http_code}' -X POST -H 'Accept: application/json' -H 'Content-Type: text/plain' \
    --data '{"share":{"set":[]}}' "$@" http://127.0.0.1/calendars/home/calendar.json
}

expect_refused() {
  local description=$1 output
  shift
  output=$(post "$@")
  if grep -qF "$REFUSED" <<<"$output" && [ "${output##* }" = 403 ]; then
    pass "$description: refused"
  else
    failed "$description: not refused ($output)"
  fi
}

expect_not_refused() {
  local description=$1 output
  shift
  output=$(post "$@")
  if grep -qF "$REFUSED" <<<"$output"; then
    failed "$description: refused"
  else
    pass "$description: not refused (${output##* })"
  fi
}

echo "1. KERBEROS_ENABLED=true: a Negotiate request from another origin is refused"
cid=$(start "${KERBEROS[@]}" -e CORS_ALLOWED_ORIGINS=https://calendar.example.com) || { failed "container did not start"; exit 1; }
expect_refused "Origin https://evil.example" "$cid" "${NEGOTIATE[@]}" -H 'Origin: https://evil.example'
expect_refused "Origin null" "$cid" "${NEGOTIATE[@]}" -H 'Origin: null'
expect_refused "Origin of a look-alike host" "$cid" "${NEGOTIATE[@]}" -H 'Origin: http://127.0.0.1.evil.example'
expect_refused "Origin of a look-alike listed origin" "$cid" "${NEGOTIATE[@]}" -H 'Origin: https://calendar.example.com.evil.example'
expect_refused "no Origin, Sec-Fetch-Site: cross-site" "$cid" "${NEGOTIATE[@]}" -H 'Sec-Fetch-Site: cross-site'
expect_not_refused "no Origin (desktop client)" "$cid" "${NEGOTIATE[@]}"
expect_not_refused "Origin listed in CORS_ALLOWED_ORIGINS" "$cid" "${NEGOTIATE[@]}" -H 'Origin: https://calendar.example.com' \
  -H 'Sec-Fetch-Site: cross-site'
expect_not_refused "Origin of the DAV host" "$cid" "${NEGOTIATE[@]}" -H 'Origin: http://127.0.0.1'
expect_not_refused "Origin of the DAV host, other scheme and port" "$cid" "${NEGOTIATE[@]}" -H 'Origin: https://127.0.0.1:8443'
expect_not_refused "no Origin, Sec-Fetch-Site: same-origin" "$cid" "${NEGOTIATE[@]}" -H 'Sec-Fetch-Site: same-origin'
expect_not_refused "Origin https://evil.example without Negotiate (Bearer front-ends)" "$cid" -H 'Origin: https://evil.example'
expect_not_refused "no Origin, Sec-Fetch-Site: cross-site, GET" "$cid" "${NEGOTIATE[@]}" -H 'Sec-Fetch-Site: cross-site' -X GET
expect_not_refused "no Origin, Sec-Fetch-Site: cross-site, OPTIONS" "$cid" "${NEGOTIATE[@]}" -H 'Sec-Fetch-Site: cross-site' -X OPTIONS
docker rm -f "$cid" >/dev/null

echo "2. Kerberos disabled: nothing is refused"
cid=$(start) || { failed "container did not start"; exit 1; }
expect_not_refused "Origin https://evil.example" "$cid" "${NEGOTIATE[@]}" -H 'Origin: https://evil.example'
docker rm -f "$cid" >/dev/null

echo
if [ "$FAILURES" -gt 0 ]; then
  echo "Nginx Kerberos cross-site test: $FAILURES check(s) failed"
  exit 1
fi
echo "Nginx Kerberos cross-site test: all checks passed"
