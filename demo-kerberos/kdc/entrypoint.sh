#!/bin/bash
# Demo #483: create realm TEST.LOCAL, its principals and the sabre keytab.
set -euo pipefail

rm -f /shared/ready

if [ ! -f /var/lib/krb5kdc/principal ]; then
  kdb5_util create -s -r TEST.LOCAL -P masterpw

  # Users: "username" exists in Mongo (username@linagora.com), "nobody" does not.
  kadmin.local -q "addprinc -pw secret username@TEST.LOCAL"
  kadmin.local -q "addprinc -pw secret nobody@TEST.LOCAL"
  # Their keys, for kinit -k in run-tests.sh (-norandkey keeps the passwords).
  for user in username nobody; do
    kadmin.local -q "ktadd -norandkey -k /etc/${user}.keytab ${user}@TEST.LOCAL"
  done

  # One SPN per demo sabre container, plus one that no sabre holds a key for.
  for host in sabre sabre-kerberos-off sabre-norealm other; do
    kadmin.local -q "addprinc -randkey HTTP/${host}.test.local@TEST.LOCAL"
  done

  rm -f /shared/sabre.keytab.tmp
  for host in sabre sabre-kerberos-off sabre-norealm; do
    kadmin.local -q "ktadd -k /shared/sabre.keytab.tmp HTTP/${host}.test.local@TEST.LOCAL"
  done
  chmod 0644 /shared/sabre.keytab.tmp
  mv /shared/sabre.keytab.tmp /shared/sabre.keytab
fi

touch /shared/ready
exec krb5kdc -n
