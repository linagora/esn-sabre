#!/bin/bash
# Demo #483: end-to-end tests of Kerberos authentication (curl against the sabre containers),
# run from the KDC container (it has kinit, kadmin.local, curl):
#
#   docker compose exec kdc bash /demo/run-tests.sh
#
# The last case rotates the key of HTTP/sabre.test.local: run
# "docker compose down -v" before running the tests again.
set -uo pipefail

SABRE=http://sabre.test.local
USER_ID=5f000000000000000000000a     # username@linagora.com (mongo/init.js)
JWT_USER_ID=5f000000000000000000000b # user1@open-paas.org
JWT='eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJ1c2VyMUBvcGVuLXBhYXMub3JnIiwiaWF0IjoxNjExNTkzNjA0fQ.FddHtNdatKnSub_zAWxLFfFyf-azfGnpL-eBjAulLtIzPvDMDfYY0W5VWe4FIkmA59Gi0JxBq_topZM6mrrQyMtWEVBIb3IFHbHGYWtKcnrBmQN7UdLuaa7V5EEQ5_8JZU4l-qkXFeqYr-LTq2KTz3NiZPZgSaEcam4_C9ByzUBQ_-jMiMK5nb_gGGPMEUYGmxobrf7I9tXQHSqSHK58Igg67FnqtEMHsfrya4g_Fs13zSYk_TkUY9x0IpiU5g-PgjEN6-7ts1Or8maBQsmKgn9pqmsYEg3VM2QZIyw7MMNG9gLQRE6QRchlc1ReJZRJk6eZY2d-xenzX8lqn5e40A'
PROPFIND_BODY='<d:propfind xmlns:d="DAV:"><d:prop><d:current-user-principal/></d:prop></d:propfind>'

out=$(mktemp -d)
pass=0
fail=0

# req NAME CURL_ARGS... : runs curl, keeps status/headers/body in $out/NAME.*
req() {
  local name=$1; shift
  curl -s -o "$out/$name.body" -D "$out/$name.headers" -w '%{http_code}' "$@" > "$out/$name.status"
}
status()  { cat "$out/$1.status"; }
headers() { tr -d '\r' < "$out/$1.headers"; }
body()    { cat "$out/$1.body"; }

check() { # check CASE DESCRIPTION CONDITION...
  local name=$1 description=$2; shift 2
  if "$@"; then
    echo "PASS  $name  $description"; pass=$((pass + 1))
  else
    echo "FAIL  $name  $description"; fail=$((fail + 1))
    echo "      status: $(status "$name" 2>/dev/null)"
    # last response only (curl --negotiate keeps every hop), long tokens cut
    headers "$name" 2>/dev/null | awk '/^HTTP\//{n++} {l[n]=l[n] $0 "\n"} END{printf "%s", l[n]}' \
      | grep -iv '^access-control\|^vary\|^date\|^server' | cut -c1-110 | sed 's/^/      < /'
    body "$name" 2>/dev/null | head -c 400 | sed 's/^/      | /'; echo
  fi
}
info() { echo "INFO  $1  $2"; }

is_status()      { [ "$(status "$1")" = "$2" ]; }
body_has()       { body "$1" | grep -qF -- "$2"; }
body_lacks()     { ! body "$1" | grep -qF -- "$2"; }
challenges()     { headers "$1" | grep -i '^WWW-Authenticate:' | sed 's/^[^:]*: *//'; }
propfind()       { req "$1" -X PROPFIND -H 'Depth: 0' -H 'Content-Type: application/xml' --data "$PROPFIND_BODY" "${@:2}"; }

# User keytabs exported by kdc/entrypoint.sh: no password prompt, TTY or not.
login() { kdestroy -q 2>/dev/null; kinit -k -t "/etc/${1%@*}.keytab" "$1"; }

echo "== tickets"
login username@TEST.LOCAL && klist | sed 's/^/   /'
echo

# --- 1. Negotiate: the main flow --------------------------------------------
propfind c01 --negotiate -u : "$SABRE/"
check c01 "Negotiate -> 207 as username@linagora.com" \
  eval 'is_status c01 207 && body_has c01 "/principals/users/$USER_ID/"'

# --- 2. No credentials: both challenges, Negotiate first ---------------------
propfind c02 "$SABRE/"
check c02 "no credentials -> 401 + Negotiate then Basic" \
  eval 'is_status c02 401 && [ "$(challenges c02 | head -1)" = "Negotiate" ] && challenges c02 | grep -q "^Basic "'

# --- 3. Basic against LDAP still works ---------------------------------------
propfind c03 -u username:password "$SABRE/"
check c03 "Basic (LDAP) -> 207 as username@linagora.com" \
  eval 'is_status c03 207 && body_has c03 "/principals/users/$USER_ID/"'

# --- 4. Bearer JWT still works -----------------------------------------------
propfind c04 -H "Authorization: Bearer $JWT" "$SABRE/"
check c04 "Bearer JWT -> 207 as user1@open-paas.org" \
  eval 'is_status c04 207 && body_has c04 "/principals/users/$JWT_USER_ID/"'

# --- 5. Basic with a wrong password: $remote_user is not trusted -------------
propfind c05 -u username:wrong "$SABRE/"
check c05 "Basic with a wrong password -> 401" is_status c05 401

# --- 6. Forged principal headers ---------------------------------------------
propfind c06 -u username:wrong \
  -H 'KRB5_PRINCIPAL: username@TEST.LOCAL' -H 'KRB5-PRINCIPAL: username@TEST.LOCAL' "$SABRE/"
check c06 "client headers named like the FastCGI param -> 401" is_status c06 401

propfind c06b -H 'KRB5-PRINCIPAL: username@TEST.LOCAL' "$SABRE/"
check c06b "forged principal header alone -> 401" is_status c06b 401

# --- 7. Garbage Negotiate token: rejected by nginx, never reaches PHP --------
propfind c07 -H 'Authorization: Negotiate Z2FyYmFnZQ==' "$SABRE/"
check c07 "garbage Negotiate token -> 403 from nginx, PHP not reached" \
  eval 'is_status c07 403 && body_lacks c07 "<d:error"'

# --- 8. Ticket for another SPN -----------------------------------------------
propfind c08 --negotiate -u : --connect-to other.test.local:80:sabre.test.local:80 "http://other.test.local/"
check c08 "ticket for HTTP/other.test.local -> 403 from nginx" \
  eval 'is_status c08 403 && body_lacks c08 "<d:error"'

# --- 9. Valid ticket, no matching user ---------------------------------------
login nobody@TEST.LOCAL
propfind c09 --negotiate -u : "$SABRE/"
check c09 "nobody@TEST.LOCAL (no Mongo user) -> 403 from Sabre, no retry loop" \
  eval 'is_status c09 403 && body_has c09 "no user found" && [ "$(grep -c "^HTTP/" "$out/c09.headers")" -le 2 ]'
login username@TEST.LOCAL

# --- 10. Realm not allowed ---------------------------------------------------
propfind c10 --negotiate -u : "http://sabre-norealm.test.local/"
check c10 "realm TEST.LOCAL not in KERBEROS_REALM_DOMAINS -> 403 from Sabre" \
  eval 'is_status c10 403 && body_has c10 "realm not allowed"'

# --- 11. Kerberos disabled ---------------------------------------------------
propfind c11 --negotiate -u : "http://sabre-kerberos-off.test.local/"
check c11 "KERBEROS_ENABLED=false + valid ticket -> 401" is_status c11 401
propfind c11b "http://sabre-kerberos-off.test.local/"
check c11b "KERBEROS_ENABLED=false -> no Negotiate challenge" \
  eval 'is_status c11b 401 && ! challenges c11b | grep -q "^Negotiate"'
propfind c11c -u username:password "http://sabre-kerberos-off.test.local/"
check c11c "KERBEROS_ENABLED=false, Basic still works -> 207" is_status c11c 207

# --- 12. Mutual authentication token on success (observation) ----------------
if challenges c01 | grep -q '^Negotiate .'; then
  info c12 "mutual auth: 207 carries 'WWW-Authenticate: Negotiate <token>'"
else
  info c12 "mutual auth: NO 'WWW-Authenticate: Negotiate <token>' on the 207"
fi

# --- 13. Large Negotiate header, like an AD ticket with a big PAC (observation)
big=$(head -c 12000 /dev/zero | tr '\0' 'A')
propfind c13 -H "Authorization: Negotiate $big" "$SABRE/"
info c13 "12 KB Negotiate header -> HTTP $(status c13) (400 = large_client_header_buffers too small)"

# --- 14. Methods with a body go through the /__krb rewrite -------------------
CAL="$SABRE/calendars/$USER_ID/demo-krb"
req c14a --negotiate -u : -X MKCALENDAR "$CAL/"
check c14a "MKCALENDAR -> 201" is_status c14a 201

ICS=$'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//demo//krb//EN\r\nBEGIN:VEVENT\r\nUID:demo-krb-1\r\nDTSTAMP:20261113T090000Z\r\nDTSTART:20261120T090000Z\r\nDTEND:20261120T100000Z\r\nSUMMARY:Kerberos demo\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n'
req c14b --negotiate -u : -X PUT -H 'Content-Type: text/calendar' --data-binary "$ICS" "$CAL/demo-krb-1.ics"
check c14b "PUT event -> 201" is_status c14b 201

req c14c --negotiate -u : "$CAL/demo-krb-1.ics"
check c14c "GET event -> 200 with its UID" \
  eval 'is_status c14c 200 && body_has c14c "UID:demo-krb-1"'

req c14d --negotiate -u : -X REPORT -H 'Depth: 1' -H 'Content-Type: application/xml' --data \
  '<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><d:getetag/></d:prop><c:filter><c:comp-filter name="VCALENDAR"/></c:filter></c:calendar-query>' \
  "$CAL/"
check c14d "REPORT calendar-query -> 207 listing the event" \
  eval 'is_status c14d 207 && body_has c14d "demo-krb-1.ics"'

req c14e --negotiate -u : -X DELETE "$CAL/"
check c14e "DELETE calendar -> 204" is_status c14e 204

# --- 15. Key rotation: the keytab of sabre is now stale (destructive, last) ---
kadmin.local -q "cpw -randkey HTTP/sabre.test.local@TEST.LOCAL" > /dev/null
login username@TEST.LOCAL
propfind c15 --negotiate -u : "$SABRE/"
check c15 "ticket for a newer kvno than the keytab -> 403 from nginx" is_status c15 403

echo
echo "== $pass passed, $fail failed (raw responses in $out)"
[ "$fail" -eq 0 ]
