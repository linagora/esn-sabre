#!/bin/bash
# Demo #483: install the keytab exported by the KDC, then start sabre as usual.
set -e

for _ in $(seq 1 60); do
  [ -f /shared/sabre.keytab ] && break
  echo "start-demo.sh: waiting for /shared/sabre.keytab"
  sleep 1
done
[ -f /shared/sabre.keytab ] || { echo "start-demo.sh: no keytab" >&2; exit 1; }

install -o root -g www-data -m 0640 /shared/sabre.keytab "$KERBEROS_KEYTAB"

exec bash /var/www/scripts/start.sh
