#!/bin/bash
#
# Integration test: the PHP-FPM status and ping pages are served on the private port of PHP_FPM_STATUS_LISTEN only,
# never on the public port 80 (see doc/CONFIGURE.md § "PHP-FPM slow log and status page"). /status?full shows the URI
# and the user of every request under way: answered on port 80, anyone reaching the DAV endpoint would read them.
#
# Usage: tests/nginx/fpm_status_test.sh [image]   (default: esn_sabre_nginx_test)
#
# No MongoDB is needed (WITHOUT_MONGO): the status pages are answered by FPM before PHP runs.
set -uo pipefail

IMAGE=${1:-esn_sabre_nginx_test}
CONTAINERS=()
FAILURES=0

cleanup() {
  if [ ${#CONTAINERS[@]} -gt 0 ]; then
    docker rm -f "${CONTAINERS[@]}" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

pass() { echo "  PASS: $*"; }
failed() { echo "  FAIL: $*"; FAILURES=$((FAILURES + 1)); }

WITHOUT_MONGO=(-e MONGO_INDEX_CREATION_ATTEMPTS=1 -e MONGO_INDEX_CREATION_REQUIRED=false)

# Starts the image with the given `-e` options, waits for nginx and prints the container id.
start() {
  local cid
  cid=$(docker run -d "${WITHOUT_MONGO[@]}" "$@" "$IMAGE") || return 1
  CONTAINERS+=("$cid")
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

body() { docker exec "$1" curl -s --max-time 5 "$2"; }

# The URIs FPM would recognise on port 80, as written and as nginx normalises them.
PUBLIC_URIS=("/status" "/status?full" "/status?json&full" "/ping" "//status?full" "/./ping" "/x/../status" "/st%61tus?full")

assert_public_closed() {
  local cid=$1 uri out
  for uri in "${PUBLIC_URIS[@]}"; do
    out=$(body "$cid" "http://127.0.0.1$uri")
    if grep -q "accepted conn\|^pong$" <<< "$out"; then
      failed "port 80 $uri serves the FPM page"
    else
      pass "port 80 $uri does not serve the FPM page"
    fi
  done
}

echo "Default configuration (PHP_FPM_STATUS_LISTEN=127.0.0.1:8081)"
if cid=$(start); then
  assert_public_closed "$cid"
  grep -q "accepted conn" <<< "$(body "$cid" http://127.0.0.1:8081/status)" \
    && pass "port 8081 /status serves the FPM page" || failed "port 8081 /status does not serve the FPM page"
  [ "$(body "$cid" http://127.0.0.1:8081/ping)" = pong ] \
    && pass "port 8081 /ping answers pong" || failed "port 8081 /ping does not answer pong"
else
  failed "the container did not start"
fi

echo "PHP_FPM_STATUS_LISTEN=off"
if cid=$(start -e PHP_FPM_STATUS_LISTEN=off); then
  assert_public_closed "$cid"
  grep -q "accepted conn" <<< "$(body "$cid" http://127.0.0.1:8081/status)" \
    && failed "port 8081 /status still serves the FPM page" || pass "port 8081 /status is closed"
  docker exec "$cid" sh -c 'grep -q "^pm.status_path\|^ping.path\|^pm.status_listen" /etc/php/*/fpm/pool.d/www.conf' \
    && failed "the main pool still has a status URI" || pass "the main pool has no status URI"
else
  failed "the container did not start"
fi

if [ "$FAILURES" -gt 0 ]; then
  echo "$FAILURES failure(s)"
  exit 1
fi
echo "All FPM status tests passed"
