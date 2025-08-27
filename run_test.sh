#!/bin/bash
set -euo pipefail

docker build -t esn-sabre-ldap-test -f Dockerfile.ldap .
docker build -t esn_sabre_test .

docker compose -f docker-compose.test.yaml run --rm esn_test
exit_code=$?

# Always clean
docker compose -f docker-compose.test.yaml stop
docker compose -f docker-compose.test.yaml rm --force

exit $exit_code
