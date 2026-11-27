#!/bin/bash
#
# Integration test: start.sh regenerates /var/www/config.json at every boot, but a config.json mounted
# read-only (a Kubernetes ConfigMap, as the Twake Calendar Helm chart does) is kept and the container
# starts on it. An invalid setting (ESN_SCHEME) still stops the container.
#
# Usage: tests/nginx/readonly_config_test.sh [image]   (default: esn_sabre_nginx_test)
#
# No MongoDB is needed (WITHOUT_MONGO, see rate_limit_test.sh).
#
# The docker daemon may not share this machine's filesystem (docker-in-docker CI agents): no bind mount
# is used. The read-only config.json lives in a docker volume mounted read-only, filled through stdin,
# and /var/www/config.json is a symbolic link to it, copied with docker cp: writing it fails on a
# read-only file system, as on a ConfigMap mount.
set -uo pipefail

IMAGE=${1:-esn_sabre_nginx_test}
FAILURES=0
WORKDIR=$(mktemp -d)
# Every container and volume of this run carries the label, so that cleanup finds them even when they are
# created in a command substitution (a subshell, whose variables the script never sees).
RUN_ID=$(basename "$WORKDIR")
LABEL=(--label "esn-sabre-readonly-config-test=$RUN_ID")
VOLUME=esn-sabre-readonly-config-test-$RUN_ID

cleanup() {
  local containers
  containers=$(docker ps -aq --filter "label=esn-sabre-readonly-config-test=$RUN_ID")
  if [ -n "$containers" ]; then
    # shellcheck disable=SC2086
    docker rm -f $containers >/dev/null 2>&1 || true
  fi
  docker volume rm -f "$VOLUME" >/dev/null 2>&1 || true
  rm -rf "$WORKDIR"
}
trap cleanup EXIT

pass() { echo "  PASS: $*"; }
failed() { echo "  FAIL: $*"; FAILURES=$((FAILURES + 1)); }

WITHOUT_MONGO=(-e MONGO_INDEX_CREATION_ATTEMPTS=1 -e MONGO_INDEX_CREATION_REQUIRED=false)

# Creates a container of the image with the given docker options and prints its id. With READONLY_CONFIG=true,
# its /var/www/config.json is the read-only file of the volume.
create() {
  local cid
  if [ "${READONLY_CONFIG:-false}" = true ]; then
    cid=$(docker create "${LABEL[@]}" "${WITHOUT_MONGO[@]}" -v "$VOLUME:/readonly:ro" "$@" "$IMAGE") || return 1
    docker cp "$WORKDIR/config-link" "$cid:/var/www/config.json" >/dev/null || return 1
  else
    cid=$(docker create "${LABEL[@]}" "${WITHOUT_MONGO[@]}" "$@" "$IMAGE") || return 1
  fi
  echo "$cid"
}

# Starts a container of the image with the given docker options, waits for nginx and prints its id.
start() {
  local cid
  cid=$(create "$@") || return 1
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

echo "Read-only config.json"
# The configuration the container generates, with a marker the generator never writes
GENERATED=$(docker run --rm "${LABEL[@]}" --entrypoint sh "$IMAGE" -c 'sh /var/www/scripts/generate_config.sh')
docker volume create "${LABEL[@]}" "$VOLUME" >/dev/null || { echo "could not create the volume $VOLUME" >&2; exit 1; }
sed 's|"baseUri": "/"|"baseUri": "/", "mountedMarker": "kept"|' <<< "$GENERATED" \
  | docker run --rm -i "${LABEL[@]}" --entrypoint sh -v "$VOLUME:/readonly" "$IMAGE" \
    -c 'cat > /readonly/config.json && chmod 644 /readonly/config.json' \
  || { echo "could not fill the volume $VOLUME" >&2; exit 1; }
ln -s /readonly/config.json "$WORKDIR/config-link"
if cid=$(READONLY_CONFIG=true start); then
  pass "the container starts on a read-only config.json"
  if docker exec "$cid" grep -q '"mountedMarker": "kept"' /var/www/config.json; then
    pass "the mounted config.json is kept"
  else
    failed "the mounted config.json was replaced"
  fi
  if [ "$(docker exec "$cid" stat -L -c '%a' /var/www/config.json)" = 644 ]; then
    pass "the mode of the mounted config.json is left unchanged"
  else
    failed "the mode of the mounted config.json changed"
  fi
  if grep -q "read-only, keeping the mounted configuration" <<< "$(docker logs "$cid" 2>&1)"; then
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
  mode=$(docker exec "$cid" stat -c '%a %U:%G' /var/www/config.json)
  if [ "$mode" = "640 root:www-data" ]; then
    pass "the generated config.json is readable by root and www-data only"
  else
    failed "the generated config.json is $mode, expected 640 root:www-data"
  fi
  if docker exec -u www-data "$cid" cat /var/www/config.json >/dev/null 2>&1; then
    pass "php-fpm (www-data) reads config.json"
  else
    failed "www-data cannot read config.json"
  fi
  if [ "$(docker exec "$cid" curl -s -o /dev/null -w '%{http_code}' -X PROPFIND http://127.0.0.1/ 2>/dev/null)" != 500 ]; then
    pass "PHP answers with the restricted config.json"
  else
    failed "PHP answers 500 with the restricted config.json"
  fi
else
  failed "the container does not start without a mounted config.json"
fi

echo "Invalid setting"
cid=$(docker run -d "${LABEL[@]}" "${WITHOUT_MONGO[@]}" -e ESN_SCHEME=ftp "$IMAGE")
docker wait "$cid" >/dev/null
if grep -q "could not generate /var/www/config.json" <<< "$(docker logs "$cid" 2>&1)"; then
  pass "an invalid ESN_SCHEME stops the container"
else
  failed "an invalid ESN_SCHEME does not stop the container"
fi
cid=$(READONLY_CONFIG=true create -e ESN_SCHEME=ftp)
docker start "$cid" >/dev/null
docker wait "$cid" >/dev/null
if grep -q "could not generate /var/www/config.json" <<< "$(docker logs "$cid" 2>&1)"; then
  pass "an invalid ESN_SCHEME stops the container with a read-only config.json"
else
  failed "an invalid ESN_SCHEME does not stop the container with a read-only config.json"
fi

echo "Temporary configuration"
# Any temporary copy of the configuration (it carries the passwords) is gone, after a start and a failure
check_no_leftover() {
  local cid=$1 what=$2 leftovers
  leftovers=$(docker export "$cid" | tar -t 2>/dev/null | grep '^tmp/config\.json\.' || true)
  if [ -z "$leftovers" ]; then
    pass "no temporary configuration is left $what"
  else
    failed "temporary configuration left $what: $leftovers"
  fi
}
if cid=$(start); then
  check_no_leftover "$cid" "after a start"
else
  failed "the container does not start"
fi
cid=$(docker run -d "${LABEL[@]}" "${WITHOUT_MONGO[@]}" -e ESN_SCHEME=ftp "$IMAGE")
docker wait "$cid" >/dev/null
check_no_leftover "$cid" "after a failed generation"

if [ "$FAILURES" -gt 0 ]; then
  echo "$FAILURES failure(s)"
  exit 1
fi
echo "All read-only config.json tests passed"
