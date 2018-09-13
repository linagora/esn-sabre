#
# Docker container for ESN Sabre frontend
#
# Build:
# docker build -t linagora/esn-sabre .
#
# Run:
# docker run -d -p 8001:80 -e "SABRE_MONGO_HOST=192.168.0.1" -e "ESN_MONGO_HOST=192.168.0.1" linagora/esn-sabre
#

FROM debian:stretch-slim
LABEL maintainer Linagora Folks <openpaas@linagora.com>

RUN apt-get update && \
  DEBIAN_FRONTEND=noninteractive apt-get -y upgrade && \
  DEBIAN_FRONTEND=noninteractive apt-get -y install php7.0-fpm php7.0-cli curl supervisor nginx git php7.0-curl php7.0-bcmath php7.0-mbstring php7.0-zip php-pear php7.0-dev make pkg-config && \
  DEBIAN_FRONTEND=noninteractive apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# Configure PHP
RUN sed -i "s/;date.timezone =.*/date.timezone = UTC/" /etc/php/7.0/fpm/php.ini && \
  sed -i "s/;date.timezone =.*/date.timezone = UTC/" /etc/php/7.0/cli/php.ini && \
  sed -i -e "s/;daemonize\s*=\s*yes/daemonize = no/g" /etc/php/7.0/fpm/php-fpm.conf && \
  sed -i "s/;cgi.fix_pathinfo=1/cgi.fix_pathinfo=0/" /etc/php/7.0/fpm/php.ini && \
  sed -i "s/;listen.owner = www-data/listen.owner = www-data/" /etc/php/7.0/fpm/pool.d/www.conf && \
  sed -i "s/;listen.group = www-data/listen.group = www-data/" /etc/php/7.0/fpm/pool.d/www.conf && \
  sed -i "s/;listen.mode = 0660/listen.mode = 0660/" /etc/php/7.0/fpm/pool.d/www.conf

RUN pecl install mongodb \
    && echo "extension=mongodb.so" >> /etc/php/7.0/fpm/php.ini \
    && echo "extension=mongodb.so" >> /etc/php/7.0/cli/php.ini

# Set up Sabre DAV
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=bin --filename=composer

# Set up Nginx
RUN echo "daemon off;" >> /etc/nginx/nginx.conf
RUN ln -sf /dev/stderr /var/log/nginx/error.log
RUN ln -sf /dev/stdout /var/log/nginx/access.log

WORKDIR /var/www
COPY . /var/www
RUN cp -v docker/prepare/set_nginx_htpasswd.sh /root/set_nginx_htpasswd.sh \
    && cp -v docker/config/nginx.conf     /etc/nginx/sites-available/default \
    && cp -v docker/supervisord.conf /etc/supervisor/conf.d/ \
    && rm -frv html \
    && chown -R www-data:www-data /var/www \
    && /root/set_nginx_htpasswd.sh \
    && mkdir -p /var/run/php

RUN composer clearcache && composer update

EXPOSE 80

CMD ["sh", "./scripts/start.sh"]
