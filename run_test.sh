#!/bin/bash
set -euo pipefail

# Usage: ./run_test.sh [--filter=TestClassName] [--skip-java]
# Examples:
#   ./run_test.sh --filter=IMipPluginTest
#   ./run_test.sh --skip-java
#   ./run_test.sh --filter=IMipPluginTest --skip-java

FILTER=""
SKIP_JAVA=false

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
    *)
      echo "Unknown option: $arg"
      echo "Usage: $0 [--filter=TestClassName] [--skip-java]"
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

docker build -t esn-sabre-ldap-test -f Dockerfile.ldap .
docker build -t esn_sabre_test -f Dockerfile.test .

# Build PHP test command
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

if [ "$SKIP_JAVA" = true ]; then
  echo "Skipping Java integration tests"
  exit 0
fi

(
  git clone https://github.com/linagora/twake-calendar-integration-tests.git it-tests
  cd it-tests
  git checkout fix-sabre-4.1.5-tests-with-vobject-upgrade
  bash pre-build.sh esn_sabre_test
  mvn clean install -Dtest=com.linagora.dav.sabrev4.**
)
exit_code_2=$?

if [ $exit_code_2 -ne 0 ]; then
  exit 1
fi
