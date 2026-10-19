#!/bin/bash
set -euo pipefail

# Temporary defaults for integration test PR #394; environment overrides still apply.
INTEGRATION_TEST_REPO="${INTEGRATION_TEST_REPO:-https://github.com/linagora/twake-calendar-integration-tests.git}"
INTEGRATION_TEST_BRANCH="${INTEGRATION_TEST_BRANCH:-test/carddav-subscription-destination-acl}"

# Usage: ./run_test.sh [--filter=TestClassName] [--skip-java] [--skip-php] [--skip-nginx] [--skip-build] [--java-sequential]
# Examples:
#   ./run_test.sh                                          # Run everything
#   ./run_test.sh --skip-build                            # Skip docker build, run all tests
#   ./run_test.sh --skip-java --skip-nginx                # Run PHP tests only
#   ./run_test.sh --skip-php --skip-nginx                 # Run Java integration tests only
#   ./run_test.sh --skip-php --skip-nginx --java-sequential # Run Java tests with one stack (less memory)
#   ./run_test.sh --skip-php --skip-java                  # Run nginx rate limit tests only
#   ./run_test.sh --filter=IMipPluginTest                 # Run PHP tests with filter
#   ./run_test.sh --filter=IMipPluginTest --skip-build    # Run PHP tests with filter, skip build
#   ./run_test.sh --skip-java --skip-php --skip-nginx     # Build Docker images only

FILTER=""
SKIP_JAVA=false
SKIP_PHP=false
SKIP_NGINX=false
SKIP_BUILD=false
MANUAL_MODE=false
CLEANUP_IMAGES=false
JAVA_SEQUENTIAL=false
JAVA_PIDS=()
RESOURCE_MONITOR_PID=""

cd "$(dirname "${BASH_SOURCE[0]}")"
JAVA_REPORT_DIR="$PWD/tests/report/java"

# Images built by this script. They are named (not tagged per-run), so an image
# left behind by a previous run is silently reused by --skip-build invocations.
BUILT_IMAGES=(esn_sabre_test esn-sabre-ldap-test esn_sabre_nginx_test)

for arg in "$@"; do
  case $arg in
    --filter=*)
      FILTER="${arg#*=}"
      shift
      ;;
    --skip-java)
      SKIP_JAVA=true
      shift
      ;;
    --java-sequential)
      JAVA_SEQUENTIAL=true
      shift
      ;;
    --skip-php)
      SKIP_PHP=true
      shift
      ;;
    --skip-nginx)
      SKIP_NGINX=true
      shift
      ;;
    --skip-build)
      SKIP_BUILD=true
      shift
      ;;
    --manual-mode)
      MANUAL_MODE=true
      shift
      ;;
    --cleanup-images)
      CLEANUP_IMAGES=true
      shift
      ;;
    *)
      echo "Unknown option: $arg"
      echo "Usage: $0 [--filter=TestClassName] [--skip-java] [--skip-php] [--skip-nginx] [--skip-build] [--java-sequential] [--manual-mode] [--cleanup-images]"
      exit 1
      ;;
  esac
done

cleanup() {
  echo "Cleaning up..."
  # Stop Maven before removing its files if the script is interrupted.
  if [ "${#JAVA_PIDS[@]}" -gt 0 ]; then
    kill "${JAVA_PIDS[@]}" 2>/dev/null || true
    wait "${JAVA_PIDS[@]}" 2>/dev/null || true
  fi
  if [ -n "$RESOURCE_MONITOR_PID" ]; then
    kill "$RESOURCE_MONITOR_PID" 2>/dev/null || true
    wait "$RESOURCE_MONITOR_PID" 2>/dev/null || true
  fi
  rm -rf it-tests
  docker compose -f docker-compose.test.yaml down --volumes --remove-orphans || true

  # Not done by default: the Jenkins pipeline builds once, then runs the PHP and
  # Java stages with --skip-build, so they need the images to survive. Only the
  # last stage of a pipeline should pass --cleanup-images.
  if [ "$CLEANUP_IMAGES" = true ]; then
    echo "Removing built images and dangling build layers..."
    docker image rm -f "${BUILT_IMAGES[@]}" >/dev/null 2>&1 || true
    docker image prune -f >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT  # finally: sera exécuté *quoi qu'il arrive*
trap 'exit 130' INT
trap 'exit 143' TERM

monitor_resources() {
  local sleep_pid=""
  # Reap the timer too, so stopping the monitor leaves no background process.
  trap 'if [ -n "$sleep_pid" ]; then kill "$sleep_pid" 2>/dev/null || true; wait "$sleep_pid" 2>/dev/null || true; fi' EXIT
  trap 'exit 0' INT TERM
  free -m | head -n 1
  while true; do
    printf '%s %s load %s\n' "$(date +%T)" "$(free -m | sed -n 2p)" "$(cut -d ' ' -f 1-3 /proc/loadavg)"
    sleep 30 &
    sleep_pid=$!
    wait "$sleep_pid" || true
    sleep_pid=""
  done
}

run_java_group() {
  cd "$JAVA_REPORT_DIR/$1"
  # exec lets cleanup signal Maven directly, rather than leaving it in a subshell.
  exec mvn -B clean install -Dapi.version=1.43 -Dtest="$2" -Damqp.scheduling.enabled=true \
    -Daether.syncContext.named.factory=file-lock -Daether.syncContext.named.nameMapper=file-gav
}

javatest() {
  local group index status=0
  local clone_options=(--depth 1)
  local groups=(cal other)
  local selections=('com/linagora/dav/sabrev4_7/cal/**' 'com/linagora/dav/sabrev4_7/**,!com/linagora/dav/sabrev4_7/cal/**')
  if [ "$JAVA_SEQUENTIAL" = true ]; then
    groups=(all)
    selections=('com.linagora.dav.sabrev4_7.**')
  fi

  if [ -n "$INTEGRATION_TEST_BRANCH" ]; then
    clone_options+=(--branch "$INTEGRATION_TEST_BRANCH")
  fi
  git clone "${clone_options[@]}" "$INTEGRATION_TEST_REPO" it-tests
  (cd it-tests && bash pre-build.sh esn_sabre_test)

  # As in integration-tests commit e920d102, each JVM gets its own compilation,
  # reports and Testcontainers stack; Docker images are built only once above.
  # Keep the workspaces outside it-tests so reports survive cleanup, also on failure.
  rm -rf "$JAVA_REPORT_DIR"
  for group in "${groups[@]}"; do
    mkdir -p "$JAVA_REPORT_DIR/$group"
    cp -R it-tests/pom.xml it-tests/src "$JAVA_REPORT_DIR/$group/"
  done
  # Both groups share the same host, so one monitor covers both Maven processes.
  if command -v free >/dev/null 2>&1 && [ -r /proc/loadavg ]; then
    echo "Logging RAM (MiB) and CPU load every 30s: $JAVA_REPORT_DIR/test-resources.txt"
    monitor_resources > "$JAVA_REPORT_DIR/test-resources.txt" 2>&1 &
    RESOURCE_MONITOR_PID=$!
  else
    echo "Skipping resource monitor: requires Linux /proc/loadavg and the free command."
  fi
  for index in "${!groups[@]}"; do
    echo "Starting Java tests: ${groups[$index]} (reports: $JAVA_REPORT_DIR/${groups[$index]}/target)"
    run_java_group "${groups[$index]}" "${selections[$index]}" &
    JAVA_PIDS+=("$!")
  done

  # Wait for every JVM, even if one fails, before allowing cleanup to run.
  for index in "${!JAVA_PIDS[@]}"; do
    if wait "${JAVA_PIDS[$index]}"; then
      echo "Java tests passed: ${groups[$index]}"
    else
      echo "Java tests failed: ${groups[$index]} (reports: $JAVA_REPORT_DIR/${groups[$index]}/target)" >&2
      status=1
    fi
  done
  JAVA_PIDS=()
  if [ -n "$RESOURCE_MONITOR_PID" ]; then
    kill "$RESOURCE_MONITOR_PID" 2>/dev/null || true
    wait "$RESOURCE_MONITOR_PID" 2>/dev/null || true
    RESOURCE_MONITOR_PID=""
  fi
  return "$status"
}

# Pre Cleanup
docker compose -f docker-compose.test.yaml down --volumes --remove-orphans || true

# Build Docker images
if [ "$SKIP_BUILD" = false ]; then
  # Drop images from previous runs first: otherwise a failed build leaves the
  # old image in place and the test stages run against it without saying so.
  docker image rm -f "${BUILT_IMAGES[@]}" >/dev/null 2>&1 || true

  docker build --pull -t esn-sabre-ldap-test -f Dockerfile.ldap . || exit 1
  docker build --pull -t esn_sabre_test -f Dockerfile.test . || exit 1
  # The production image: the nginx rate limit test runs against it.
  docker build --pull -t esn_sabre_nginx_test -f Dockerfile . || exit 1
else
  for image in "${BUILT_IMAGES[@]}"; do
    if [ -z "$(docker images -q "$image" 2>/dev/null)" ]; then
      echo "Image '$image' is missing: build it first, or drop --skip-build." >&2
      exit 1
    fi
  done
fi

# PHP tests
if [ "$SKIP_PHP" = false ]; then
  if [ -n "$FILTER" ]; then
    echo "Running PHP tests with filter: $FILTER"
    docker compose -f docker-compose.test.yaml run --rm esn_test bash -c "sleep 5 && make lint && vendor/bin/phpunit -c tests/phpunit.xml --filter=$FILTER tests" || exit 1
  else
    echo "Running all PHP tests"
    docker compose -f docker-compose.test.yaml run --rm esn_test bash -c "sleep 5 && make lint && make test" || exit 1
  fi
fi

# Nginx rate limit tests, against the production image
if [ "$SKIP_NGINX" = false ]; then
  bash tests/nginx/rate_limit_test.sh esn_sabre_nginx_test || exit 1
else
  echo "Skipping nginx rate limit tests"
fi

# Java integration tests
if [ ! "$SKIP_JAVA" = true ]; then
  javatest
else
  echo "Skipping Java integration tests"
fi
  
if [ "$MANUAL_MODE" = true ]; then
  echo "Do not cleanup (manual mode) please do when done"
  echo "rm -rf it-tests"
  echo "docker compose -f docker-compose.test.yaml down --volumes --remove-orphans"
  trap - EXIT
  docker compose -f docker-compose.test.yaml run
fi
