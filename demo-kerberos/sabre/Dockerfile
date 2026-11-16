# Demo #483: the regular esn-sabre image (it ships the SPNEGO nginx module and
# docker/config/kerberos.conf.template), plus the keytab installation.
#
# Built by demo-kerberos/build.sh on top of esn-sabre:krb-base.
FROM esn-sabre:krb-base

COPY demo-kerberos/sabre/start-demo.sh /root/start-demo.sh

CMD ["bash", "/root/start-demo.sh"]
