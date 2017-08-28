#
# Docker container for building openpaas-davserver package
#

FROM linagora/fpm-centos-7
MAINTAINER Linagora Folks <lgs-openpaas-dev@linagora.com>

ARG VERSION=0.0.0
ARG ITERATION=1
ARG TREEISH=master

RUN rpm -ivh http://dl.fedoraproject.org/pub/epel/epel-release-latest-7.noarch.rpm && \
    rpm -ivh http://rpms.famillecollet.com/enterprise/remi-release-7.rpm && \
    yum --setopt=alwaysprompt=no --enablerepo=remi,remi-php56 -y install git rpm-build php php-cli php-fpm php-curl php-bcmath php-mbstring php-xml php-pecl-mongo

RUN curl -sS https://getcomposer.org/installer | php && \
    mv composer.phar /usr/local/bin/composer.phar && \
    ln -s /usr/local/bin/composer.phar /usr/local/bin/composer

RUN git clone --no-single-branch --depth=1 https://ci.linagora.com/linagora/lgs/openpaas/esn-sabre.git /package/usr/share/openpaas-davserver

ADD common/package /package
ADD redhat/7/package /package

ADD common/openpaas-davserver.preinst /root/
ADD redhat/7/openpaas-davserver.postinst /root/
ADD common/openpaas-davserver.postrm /root/

WORKDIR /package/usr/share/openpaas-davserver
RUN git checkout $TREEISH && composer update --no-dev && find . -name .git -type d -exec rm -rf {} +

WORKDIR /package
RUN fpm \
  -s dir \
  -t rpm \
  --name openpaas-davserver \
  --version $VERSION \
  --iteration $ITERATION \
  --license AGPLv3 \
  --vendor Linagora \
  --maintainer "Linagora Folks <lgs-openpaas-dev@linagora.com>" \
  --description "OpenPaas Enterprise Social Network - *DAV Server" \
  --url "http://open-paas.org" \
  --architecture x86_64 \
  --rpm-user openpaas \
  --rpm-group openpaas \
  --rpm-dist el7 \
  --depends nginx \
  --depends php \
  --depends php-fpm \
  --depends php-curl \
  --depends php-bcmath \
  --depends php-mbstring \
  --depends php-xml \
  --depends php-pecl-mongo \
  --depends mongodb-org \
  --depends rabbitmq-server \
  --before-install /root/openpaas-davserver.preinst \
  --after-install /root/openpaas-davserver.postinst \
  --after-remove /root/openpaas-davserver.postrm \
  .

VOLUME /result

ENTRYPOINT cp /package/openpaas-davserver*.rpm /result/
