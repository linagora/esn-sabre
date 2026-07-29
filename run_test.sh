#!/bin/bash
set -euo pipefail

# Usage: ./run_test.sh [--filter=TestClassName] [--skip-java] [--skip-php] [--skip-build]
# Examples:
#   ./run_test.sh                                          # Run everything
#   ./run_test.sh --skip-build                            # Skip docker build, run all tests
#   ./run_test.sh --skip-java                             # Run PHP tests only
#   ./run_test.sh --skip-php                              # Run Java integration tests only
#   ./run_test.sh --filter=IMipPluginTest                 # Run PHP tests with filter
#   ./run_test.sh --filter=IMipPluginTest --skip-build    # Run PHP tests with filter, skip build
#   ./run_test.sh --skip-java --skip-php                  # Build Docker images only

FILTER=""
SKIP_JAVA=false
SKIP_PHP=false
SKIP_BUILD=false
MANUAL_MODE=false
CLEANUP_IMAGES=false

# Images built by this script. They are named (not tagged per-run), so an image
# left behind by a previous run is silently reused by --skip-build invocations.
BUILT_IMAGES=(esn_sabre_test esn-sabre-ldap-test)

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
    --skip-php)
      SKIP_PHP=true
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
      echo "Usage: $0 [--filter=TestClassName] [--skip-java] [--skip-php] [--skip-build] [--cleanup-images]"
      exit 1
      ;;
  esac
done

cleanup() {
  echo "Cleaning up..."
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

javatest() {
    git clone https://github.com/linagora/twake-calendar-integration-tests.git it-tests ||  exit 1
    cd it-tests || exit 1
    bash pre-build.sh esn_sabre_test || exit 1
    mvn clean install -Dapi.version=1.43 -Dtest=com.linagora.dav.sabrev4_7.** -Damqp.scheduling.enabled=true || exit 1
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
