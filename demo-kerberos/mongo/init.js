// Demo #483: the two domains and users the tests authenticate as.
// Fixed ids so that run-tests.sh can assert the principal URLs.
const esn = db.getSiblingDB('esn');

esn.domains.insertMany([
  { _id: ObjectId('5f0000000000000000000001'), name: 'linagora.com' },
  { _id: ObjectId('5f0000000000000000000002'), name: 'open-paas.org' }
]);

esn.users.insertMany([
  {
    // Kerberos username@TEST.LOCAL and LDAP uid=username
    _id: ObjectId('5f000000000000000000000a'),
    firstname: 'userFirstname',
    lastname: 'userSurname',
    preferredEmail: 'username@linagora.com',
    domains: [{ domain_id: ObjectId('5f0000000000000000000001') }],
    accounts: [{ type: 'email', emails: ['username@linagora.com'] }]
  },
  {
    // sub of the JWT fixture of tests/DAV/Auth/Backend/EsnTest.php
    _id: ObjectId('5f000000000000000000000b'),
    firstname: 'user1',
    lastname: 'user1',
    preferredEmail: 'user1@open-paas.org',
    domains: [{ domain_id: ObjectId('5f0000000000000000000002') }],
    accounts: [{ type: 'email', emails: ['user1@open-paas.org'] }]
  }
]);
