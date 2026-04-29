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
    *)
      echo "Unknown option: $arg"
      echo "Usage: $0 [--filter=TestClassName] [--skip-java] [--skip-php] [--skip-build]"
      exit 1
      ;;
  esac
done

cleanup() {
  echo "Cleaning up..."
  rm -rf it-tests
  docker compose -f docker-compose.test.yaml down --volumes --remove-orphans || true
}
trap cleanup EXIT  # finally: sera exécuté *quoi qu'il arrive*

# Pre Cleanup
docker compose -f docker-compose.test.yaml down --volumes --remove-orphans || true

# Build Docker images
if [ "$SKIP_BUILD" = false ]; then
  docker build -t esn-sabre-ldap-test -f Dockerfile.ldap .
  docker build -t esn_sabre_test -f Dockerfile.test .
fi

# PHP tests
if [ "$SKIP_PHP" = false ]; then
  if [ -n "$FILTER" ]; then
    echo "Running PHP tests with filter: $FILTER"
    docker compose -f docker-compose.test.yaml run --rm esn_test bash -c "sleep 5 && make lint && vendor/bin/phpunit -c tests/phpunit.xml --filter=$FILTER tests"
  else
    echo "Running all PHP tests"
    docker compose -f docker-compose.test.yaml run --rm esn_test bash -c "sleep 5 && make lint && make test"
  fi
  exit_code_1=$?

  if [ $exit_code_1 -ne 0 ]; then
    exit 1
  fi
fi

# Java integration tests
if [ "$SKIP_JAVA" = true ]; then
  echo "Skipping Java integration tests"
  exit 0
fi

(
  git clone https://github.com/linagora/twake-calendar-integration-tests.git it-tests
  cd it-tests
  bash pre-build.sh esn_sabre_test
  mvn clean install -Dapi.version=1.43 -Dtest=com.linagora.dav.sabrev4_7.**
)
exit_code_2=$?

if [ $exit_code_2 -ne 0 ]; then
  exit 1
fi
