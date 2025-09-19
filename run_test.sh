#!/bin/bash
set -euo pipefail

# Pre Cleanup
docker compose -f docker-compose.test.yaml down --volumes --remove-orphans || true

docker build -t esn-sabre-ldap-test -f Dockerfile.ldap .
docker build -t esn_sabre_test .

docker compose -f docker-compose.test.yaml run --rm esn_test
exit_code=$?

# Post Cleanup
docker compose -f docker-compose.test.yaml down --volumes --remove-orphans || true

exit $exit_code
