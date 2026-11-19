#!/bin/bash
# Demo #483: build the images used by demo-kerberos/docker-compose.yml.
set -euo pipefail

repo_root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$repo_root"

# The regular image, with the patched lib/DAV/Auth/Backend/Esn.php.
docker build -t esn-sabre:krb-base -f Dockerfile .
# + the installation of the keytab exported by the KDC.
docker build -t esn-sabre:krb-demo -f demo-kerberos/sabre/Dockerfile .
# The LDAP test directory (uid=username / password).
docker build -t esn-sabre-ldap-test:krb-demo -f Dockerfile.ldap .

docker compose -f demo-kerberos/docker-compose.yml build kdc
