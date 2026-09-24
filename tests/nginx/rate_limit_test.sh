#!/bin/bash
#
# Integration test of the nginx rate limiting of the production image, configured
# only through `docker run -e` (see doc/CONFIGURE.md § "Nginx rate limiting").
#
# Usage: tests/nginx/rate_limit_test.sh [image]   (default: esn_sabre_nginx_test)
#
# No MongoDB is needed: requests that pass the rate limit get a fast 500 from PHP,
# rejected ones get the rate limit status from nginx. Two peers are used:
#  - "inside":  curl run in the container, the peer is 127.0.0.1;
#  - "outside": curl run on the docker host, the peer is the docker gateway.
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

# Starts the image with the given `-e` options, waits for nginx and prints the container id.
start() {
  local cid
  cid=$(docker run -d -p 127.0.0.1::80 "$@" "$IMAGE") || return 1
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

# Sends $2 concurrent requests from inside container $1, prints one status code per line.
inside_burst() {
  docker exec "$1" curl -s -o /dev/null -w '%{http_code}\n' -Z --parallel-immediate --parallel-max "$2" \
    "http://127.0.0.1/rate-limit-test/[1-$2]"
}

# Sends $2 sequential requests from inside container $1, extra curl options in $3...
inside_seq() {
  local cid=$1 count=$2
  shift 2
  docker exec "$cid" curl -s -o /dev/null -w '%{http_code}\n' "$@" "http://127.0.0.1/rate-limit-test/[1-$count]"
}

# Sends $2 sequential requests from the docker host to container $1, extra curl options in $3...
outside_seq() {
  local cid=$1 count=$2 port
  shift 2
  port=$(docker port "$cid" 80/tcp | head -1 | sed 's/.*://')
  curl -s -o /dev/null -w '%{http_code}\n' "$@" "http://127.0.0.1:$port/rate-limit-test/[1-$count]"
}

count() { grep -c "^$1\$" || true; }

expect_count() {
  local description=$1 actual=$2 operator=$3 expected=$4
  if [ "$actual" "$operator" "$expected" ]; then
    pass "$description ($actual $operator $expected)"
  else
    failed "$description (got $actual, expected $operator $expected)"
  fi
}

# Starts the image with invalid `-e` options, expects it to exit with a message matching $1.
expect_startup_failure() {
  local expected_message=$1 output status
  shift
  output=$(timeout 60 docker run --rm "$@" "$IMAGE" 2>&1)
  status=$?
  if [ "$status" -ne 0 ] && [ "$status" -ne 124 ] && grep -qF -- "$expected_message" <<<"$output"; then
    pass "container exits at startup: $expected_message"
  else
    failed "expected the container to exit with '$expected_message', got status $status: $(tail -3 <<<"$output")"
  fi
}

# A slow limit with a small burst makes bucket sizes deterministic: 1 + burst requests pass.
SLOW=(-e NGINX_RATE_LIMIT=1r/m -e NGINX_RATE_BURST=5)

echo "1. Defaults: a burst gets 429 (not 503), with CORS and Retry-After headers"
cid=$(start) || { failed "container did not start"; exit 1; }
codes=$(inside_burst "$cid" 300)
expect_count "429 answers to 300 concurrent requests" "$(count 429 <<<"$codes")" -gt 0
expect_count "503 answers to 300 concurrent requests" "$(count 503 <<<"$codes")" -eq 0
docker exec "$cid" nginx -T 2>/dev/null | grep -q 'limit_req_status 429;' \
  && pass "limit_req_status 429 rendered" || failed "limit_req_status 429 not rendered"
docker rm -f "$cid" >/dev/null

cid=$(start "${SLOW[@]}") || { failed "container did not start"; exit 1; }
rejected=$(docker exec "$cid" curl -s -D - -o /dev/null -H 'Origin: https://calendar.example.com' \
  "http://127.0.0.1/rate-limit-test/[1-7]" | tr -d '\r' | awk '/^HTTP\//{block=""} {block=block $0 "\n"} /^$/{last=block} END{printf "%s", last}')
grep -q '^HTTP/1.1 429' <<<"$rejected" && pass "7th request answered 429" || failed "7th request not answered 429: $rejected"
grep -qi '^Access-Control-Allow-Origin: https://calendar.example.com$' <<<"$rejected" \
  && pass "429 carries Access-Control-Allow-Origin" || failed "429 lacks Access-Control-Allow-Origin"
grep -qi '^Access-Control-Allow-Credentials: true$' <<<"$rejected" \
  && pass "429 carries Access-Control-Allow-Credentials" || failed "429 lacks Access-Control-Allow-Credentials"
grep -qi '^Retry-After: 1$' <<<"$rejected" && pass "429 carries Retry-After: 1" || failed "429 lacks Retry-After"
docker rm -f "$cid" >/dev/null

echo "2. NGINX_RATE_LIMIT=off disables rate limiting"
for value in off FALSE 0 Disabled; do
  cid=$(start -e NGINX_RATE_LIMIT=$value -e NGINX_RATE_BURST=5) || { failed "container did not start with $value"; continue; }
  if docker exec "$cid" nginx -T 2>/dev/null | grep -Eq '^\s*limit_req(_zone)?\s'; then
    failed "NGINX_RATE_LIMIT=$value: nginx -T still shows a limit_req directive"
  else
    pass "NGINX_RATE_LIMIT=$value: no limit_req directive in nginx -T"
  fi
  if [ "$value" = off ]; then
    expect_count "429 answers to 300 concurrent requests" "$(count 429 <<<"$(inside_burst "$cid" 300)")" -eq 0
  fi
  docker rm -f "$cid" >/dev/null
done

echo "3. NGINX_TRUSTED_CLIENTS_CIDR exempts trusted clients only"
for cidr in 127.0.0.0/8 127.0.0.1 "10.42.0.0/16, 127.0.0.1/32"; do
  cid=$(start "${SLOW[@]}" -e "NGINX_TRUSTED_CLIENTS_CIDR=$cidr") || { failed "container did not start with '$cidr'"; continue; }
  expect_count "'$cidr': 429 answers to a trusted client" "$(count 429 <<<"$(inside_seq "$cid" 20)")" -eq 0
  expect_count "'$cidr': 429 answers to a client outside the range" "$(count 429 <<<"$(outside_seq "$cid" 20)")" -eq 14
  docker rm -f "$cid" >/dev/null
done
# Same image, restarted with a range that excludes the client: it is limited again.
cid=$(start "${SLOW[@]}" -e NGINX_TRUSTED_CLIENTS_CIDR=10.42.0.0/16) || failed "container did not start"
expect_count "client outside 10.42.0.0/16 is limited" "$(count 429 <<<"$(inside_seq "$cid" 20)")" -eq 14
docker rm -f "$cid" >/dev/null

echo "4. X-Forwarded-For is trusted by default; NGINX_TRUSTED_PROXIES can restrict it"
cid=$(start "${SLOW[@]}") || failed "container did not start"
codes=""
for ip in 198.51.100.1 198.51.100.2 198.51.100.3; do
  codes+=$(outside_seq "$cid" 6 -H "X-Forwarded-For: $ip")$'\n'
done
expect_count "default: forwarded IPs from any peer get separate buckets" "$(count 429 <<<"$codes")" -eq 0
docker exec "$cid" nginx -T 2>/dev/null | grep -q 'set_real_ip_from 0.0.0.0/0;' \
  && pass "IPv4 peers trusted by default" || failed "IPv4 peers not trusted by default"
docker exec "$cid" nginx -T 2>/dev/null | grep -q 'set_real_ip_from ::/0;' \
  && pass "IPv6 peers trusted by default" || failed "IPv6 peers not trusted by default"
docker rm -f "$cid" >/dev/null

cid=$(start "${SLOW[@]}" -e NGINX_TRUSTED_PROXIES=127.0.0.1) || failed "container did not start"
codes=""
for ip in 198.51.100.1 198.51.100.2 198.51.100.3; do
  codes+=$(inside_seq "$cid" 6 -H "X-Forwarded-For: $ip")$'\n'
done
expect_count "trusted proxy, 3 forwarded IPs x 6 requests: one bucket each, 429 answers" "$(count 429 <<<"$codes")" -eq 0
# The spoofed first hop is skipped: 203.0.113.9 is not trusted, so it is the client.
codes=$(inside_seq "$cid" 7 -H "X-Forwarded-For: 198.51.100.4, 203.0.113.9")
expect_count "trusted proxy, 7 requests for one forwarded IP: 429 answers" "$(count 429 <<<"$codes")" -eq 1
codes=""
for ip in 198.51.100.1 198.51.100.2 198.51.100.3; do
  codes+=$(outside_seq "$cid" 6 -H "X-Forwarded-For: $ip")$'\n'
done
expect_count "untrusted peer, 3 forwarded IPs x 6 requests share one bucket: 429 answers" "$(count 429 <<<"$codes")" -eq 12
docker rm -f "$cid" >/dev/null

cid=$(start "${SLOW[@]}" -e NGINX_TRUSTED_PROXIES=127.0.0.1 -e NGINX_TRUSTED_CLIENTS_CIDR=198.51.100.0/24) \
  || failed "container did not start"
expect_count "exemption applies to the forwarded client IP" \
  "$(count 429 <<<"$(inside_seq "$cid" 20 -H 'X-Forwarded-For: 198.51.100.7')")" -eq 0
expect_count "the proxy IP itself is not exempted" "$(count 429 <<<"$(inside_seq "$cid" 20)")" -eq 14
docker rm -f "$cid" >/dev/null

cid=$(start "${SLOW[@]}" -e NGINX_TRUSTED_PROXIES=) || failed "container did not start"
codes=""
for ip in 198.51.100.1 198.51.100.2 198.51.100.3; do
  codes+=$(inside_seq "$cid" 6 -H "X-Forwarded-For: $ip")$'\n'
done
expect_count "explicitly empty trusted proxies: X-Forwarded-For is ignored, 429 answers" "$(count 429 <<<"$codes")" -eq 12
docker rm -f "$cid" >/dev/null

echo "5. NGINX_RATE_LIMIT_STATUS is configurable"
cid=$(start "${SLOW[@]}" -e NGINX_RATE_LIMIT_STATUS=503) || failed "container did not start"
codes=$(inside_seq "$cid" 10)
expect_count "503 answers with NGINX_RATE_LIMIT_STATUS=503" "$(count 503 <<<"$codes")" -eq 4
docker rm -f "$cid" >/dev/null

echo "6. Invalid values stop the container at startup"
expect_startup_failure "NGINX_TRUSTED_CLIENTS_CIDR: invalid IP address in '10.42.0.300/16'" \
  -e NGINX_TRUSTED_CLIENTS_CIDR=10.42.0.300/16
expect_startup_failure "NGINX_TRUSTED_CLIENTS_CIDR: invalid prefix length in '10.42.0.0/33'" \
  -e NGINX_TRUSTED_CLIENTS_CIDR=10.42.0.0/33
expect_startup_failure "NGINX_TRUSTED_CLIENTS_CIDR: invalid IP address in 'not-an-ip'" \
  -e "NGINX_TRUSTED_CLIENTS_CIDR=10.42.0.0/16,not-an-ip"
expect_startup_failure "use NGINX_RATE_LIMIT=off to disable rate limiting" -e NGINX_TRUSTED_CLIENTS_CIDR=0.0.0.0/0
expect_startup_failure "use NGINX_RATE_LIMIT=off to disable rate limiting" -e NGINX_TRUSTED_CLIENTS_CIDR=::/0
expect_startup_failure "NGINX_TRUSTED_PROXIES: invalid prefix length in 'fd00::/129'" -e NGINX_TRUSTED_PROXIES=fd00::/129
expect_startup_failure "NGINX_RATE_LIMIT: invalid rate '50r/h'" -e NGINX_RATE_LIMIT=50r/h
expect_startup_failure "NGINX_RATE_BURST: invalid burst 'lots'" -e NGINX_RATE_BURST=lots
expect_startup_failure "NGINX_RATE_LIMIT_STATUS: invalid status '200'" -e NGINX_RATE_LIMIT_STATUS=200

echo
if [ "$FAILURES" -gt 0 ]; then
  echo "Nginx rate limit test: $FAILURES failure(s)"
  exit 1
fi
echo "Nginx rate limit test: all checks passed"
