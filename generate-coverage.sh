#!/bin/bash
#
# Generate test coverage report with Xdebug
#
# This script builds a Docker image with Xdebug and generates an HTML coverage report.
# The report will be available at: tests/report/index.html
#
# Requirements:
# - Docker
# - MongoDB, RabbitMQ, and LDAP services running (docker-compose.test.yaml)
#
# Usage:
#   ./generate-coverage.sh
#

set -e

echo "Building coverage image with Xdebug..."
docker build -f Dockerfile.coverage -t esn_sabre_coverage . -q

echo "Starting test services..."
docker compose -f docker-compose.test.yaml up -d mongodb amqp_host esn_ldap

echo "Waiting for MongoDB to be ready..."
until docker compose -f docker-compose.test.yaml exec -T mongodb mongo --eval "db.adminCommand('ping')" 2>&1 > /dev/null; do
  echo "MongoDB not ready yet, waiting..."
  sleep 2
done
echo "Services ready!"

echo "Generating coverage report (this may take a minute)..."
docker run --rm \
  --network esn-sabre_esn-sabre-test-net \
  -e LDAP_ADMIN_DN=cn=admin,dc=linagora.com,dc=lng \
  -e LDAP_ADMIN_PASSWORD=admin \
  -e LDAP_BASE=ou=users,dc=linagora.com,dc=lng \
  -e LDAP_BASE_WITH_MAIL=ou=users,dc=linagora.com,dc=lng \
  -e LDAP_SERVER=ldap://esn_ldap:389 \
  -e LDAP_USERNAME_MODE=username \
  -v "$(pwd)/tests/report:/var/www/tests/report" \
  esn_sabre_coverage \
  bash -c "cd /var/www && make test-report 2>&1 | tail -20"

echo ""
echo "✅ Coverage report generated!"
echo "📊 Open: file://$(pwd)/tests/report/index.html"
echo ""
echo "To view the report:"
echo "  firefox tests/report/index.html"
echo "  # or"
echo "  xdg-open tests/report/index.html"
