#
# Docker container for building openpaas-davserver Debian Stretch package
#

FROM linagora/fpm-debian-stretch
MAINTAINER Linagora Folks <lgs-openpaas-dev@linagora.com>

ARG DEBIAN_FRONTEND=noninteractive
ARG VERSION=0.0.0
ARG ITERATION=1
ARG TREEISH=master

RUN apt-get install -y curl apt-transport-https && \
    curl -s https://packages.sury.org/php/apt.gpg | apt-key add - && \
    echo "deb https://packages.sury.org/php/ stretch main" > /etc/apt/sources.list.d/sury.org.list && \
    apt-get update && \
    apt-get -y install git php5.6-curl php5.6-dev php5.6-mbstring php5.6-bcmath php5.6-xml php-mongo

RUN curl -sS https://getcomposer.org/installer | php && mv composer.phar /usr/local/bin/composer.phar && ln -s /usr/local/bin/composer.phar /usr/local/bin/composer

RUN git clone --no-single-branch --depth=1 https://ci.linagora.com/linagora/lgs/openpaas/esn-sabre.git /package/usr/share/openpaas-davserver

ADD common/package /package
ADD debian/common/package /package

ADD common/openpaas-davserver.preinst /root/
ADD common/openpaas-davserver.postinst /root/
ADD common/openpaas-davserver.postrm /root/

WORKDIR /package/usr/share/openpaas-davserver
RUN git checkout $TREEISH && composer update --no-dev && find . -name .git -type d -exec rm -rf {} +

WORKDIR /package
RUN fpm \
  -s dir \
  -t deb \
  --name openpaas-davserver \
  --version $VERSION \
  --iteration "$ITERATION+stretch" \
  --license AGPLv3 \
  --vendor Linagora \
  --maintainer "Linagora Folks <lgs-openpaas-dev@linagora.com>" \
  --description "OpenPaas Enterprise Social Network - *DAV Server" \
  --url "http://open-paas.org" \
  --architecture x86_64 \
  --deb-user openpaas \
  --deb-group openpaas \
  --depends nginx \
  --depends php-mongo \
  --depends php5.6-curl \
  --depends php5.6-fpm \
  --depends php5.6-mbstring \
  --depends php5.6-bcmath \
  --depends php5.6-xml \
  --depends mongodb \
  --depends rabbitmq-server \
  --before-install /root/openpaas-davserver.preinst \
  --after-install /root/openpaas-davserver.postinst \
  --after-remove /root/openpaas-davserver.postrm \
  .

VOLUME /result

ENTRYPOINT cp /package/openpaas-davserver*.deb /result/
