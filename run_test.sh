#!/bin/bash
set -euo pipefail

cleanup() {
  echo "Cleaning up..."
  rm -rf it-tests
  docker compose -f docker-compose.test.yaml down --volumes --remove-orphans || true
}
trap cleanup EXIT  # finally: sera exécuté *quoi qu'il arrive*

# Pre Cleanup
docker compose -f docker-compose.test.yaml down --volumes --remove-orphans || true

docker build -t esn-sabre-ldap-test -f Dockerfile.ldap .
docker build -t esn_sabre_test .

docker compose -f docker-compose.test.yaml run --rm esn_test
exit_code_1=$?

if [ $exit_code_1 -ne 0 ]; then
  exit 1
fi

(
  git clone https://github.com/linagora/twake-calendar-integration-tests.git it-tests
  cd it-tests
  bash pre-build.sh esn_sabre_test
  mvn clean install -Dtest=com.linagora.dav.sabrev4.**
)
exit_code_2=$?

if [ $exit_code_2 -ne 0 ]; then
  exit 1
fi
