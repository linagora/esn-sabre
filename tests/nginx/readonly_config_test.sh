#!/bin/bash
#
# Integration test: start.sh regenerates /var/www/config.json at every boot, but a config.json mounted
# read-only (a Kubernetes ConfigMap, as the Twake Calendar Helm chart does) is kept and the container
# starts on it. An invalid setting (ESN_SCHEME) still stops the container.
#
# Usage: tests/nginx/readonly_config_test.sh [image]   (default: esn_sabre_nginx_test)
#
# No MongoDB is needed (WITHOUT_MONGO, see rate_limit_test.sh).
set -uo pipefail

IMAGE=${1:-esn_sabre_nginx_test}
CONTAINERS=()
FAILURES=0
WORKDIR=$(mktemp -d)

cleanup() {
  if [ ${#CONTAINERS[@]} -gt 0 ]; then
    docker rm -f "${CONTAINERS[@]}" >/dev/null 2>&1 || true
  fi
  rm -rf "$WORKDIR"
}
trap cleanup EXIT

pass() { echo "  PASS: $*"; }
failed() { echo "  FAIL: $*"; FAILURES=$((FAILURES + 1)); }

WITHOUT_MONGO=(-e MONGO_INDEX_CREATION_ATTEMPTS=1 -e MONGO_INDEX_CREATION_REQUIRED=false)

# Starts the image with the given docker options, waits for nginx and prints the container id.
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

echo "Read-only config.json"
# The configuration the container generates, with a marker the generator never writes
GENERATED=$(docker run --rm --entrypoint sh "$IMAGE" -c 'sh /var/www/scripts/generate_config.sh')
MOUNTED="$WORKDIR/config.json"
sed 's|"baseUri": "/"|"baseUri": "/", "mountedMarker": "kept"|' <<< "$GENERATED" > "$MOUNTED"
chmod 644 "$MOUNTED"
if cid=$(start -v "$MOUNTED:/var/www/config.json:ro"); then
  pass "the container starts on a read-only config.json"
  if docker exec "$cid" grep -q '"mountedMarker": "kept"' /var/www/config.json; then
    pass "the mounted config.json is kept"
  else
    failed "the mounted config.json was replaced"
  fi
  if docker logs "$cid" 2>&1 | grep -q "read-only, keeping the mounted configuration"; then
    pass "start.sh logs that it keeps the mounted configuration"
  else
    failed "start.sh does not log that it keeps the mounted configuration"
  fi
else
  failed "the container does not start on a read-only config.json"
fi

echo "Writable config.json"
if cid=$(start); then
  if docker exec "$cid" grep -q '"apiRoot": "http://' /var/www/config.json; then
    pass "config.json is generated"
  else
    failed "config.json is not generated"
  fi
else
  failed "the container does not start without a mounted config.json"
fi

echo "Invalid setting"
cid=$(docker run -d "${WITHOUT_MONGO[@]}" -e ESN_SCHEME=ftp "$IMAGE")
CONTAINERS+=("$cid")
docker wait "$cid" >/dev/null
if docker logs "$cid" 2>&1 | grep -q "could not generate /var/www/config.json"; then
  pass "an invalid ESN_SCHEME stops the container"
else
  failed "an invalid ESN_SCHEME does not stop the container"
fi
cid=$(docker run -d "${WITHOUT_MONGO[@]}" -e ESN_SCHEME=ftp -v "$MOUNTED:/var/www/config.json:ro" "$IMAGE")
CONTAINERS+=("$cid")
docker wait "$cid" >/dev/null
if docker logs "$cid" 2>&1 | grep -q "could not generate /var/www/config.json"; then
  pass "an invalid ESN_SCHEME stops the container with a read-only config.json"
else
  failed "an invalid ESN_SCHEME does not stop the container with a read-only config.json"
fi

if [ "$FAILURES" -gt 0 ]; then
  echo "$FAILURES failure(s)"
  exit 1
fi
echo "All read-only config.json tests passed"
