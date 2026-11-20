# Demo #483: MIT Kerberos KDC for realm TEST.LOCAL.
# The same container is the Kerberos client of the tests (kinit, curl).
FROM debian:trixie-slim

RUN apt-get update && \
    DEBIAN_FRONTEND=noninteractive apt-get -y install --no-install-recommends \
      krb5-kdc krb5-admin-server krb5-user curl ca-certificates && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/*

COPY entrypoint.sh /entrypoint.sh

EXPOSE 88/tcp 88/udp

CMD ["bash", "/entrypoint.sh"]
