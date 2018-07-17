#
# Docker container for ESN Sabre frontend
#
# Build:
# docker build -t linagora/esn-sabre .
#
# Run:
# docker run -d -p 8001:80 -e "SABRE_MONGO_HOST=192.168.0.1" -e "ESN_MONGO_HOST=192.168.0.1" linagora/esn-sabre
#

FROM debian:8
MAINTAINER Linagora Folks <openpaas@linagora.com>

RUN apt-get update && \
  DEBIAN_FRONTEND=noninteractive apt-get -y upgrade && \
  DEBIAN_FRONTEND=noninteractive apt-get -y install php5-fpm php5-cli curl supervisor nginx git php5-curl php-pear php5-dev make pkg-config && \
  DEBIAN_FRONTEND=noninteractive apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# Configure PHP
RUN sed -i "s/;date.timezone =.*/date.timezone = UTC/" /etc/php5/fpm/php.ini && \
  sed -i "s/;date.timezone =.*/date.timezone = UTC/" /etc/php5/cli/php.ini && \
  sed -i -e "s/;daemonize\s*=\s*yes/daemonize = no/g" /etc/php5/fpm/php-fpm.conf && \
  sed -i "s/;cgi.fix_pathinfo=1/cgi.fix_pathinfo=0/" /etc/php5/fpm/php.ini && \
  sed -i "s/;listen.owner = www-data/listen.owner = www-data/" /etc/php5/fpm/pool.d/www.conf && \
  sed -i "s/;listen.group = www-data/listen.group = www-data/" /etc/php5/fpm/pool.d/www.conf && \
  sed -i "s/;listen.mode = 0660/listen.mode = 0660/" /etc/php5/fpm/pool.d/www.conf

# Configure PHP
RUN echo "extension=mongo.so" >> /etc/php5/fpm/php.ini && \
    echo "extension=mongo.so" >> /etc/php5/cli/php.ini

# Set up Sabre DAV
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=bin --filename=composer

# Set up Nginx
RUN echo "daemon off;" >> /etc/nginx/nginx.conf
ADD docker/prepare/set_nginx_htpasswd.sh /root/set_nginx_htpasswd.sh
RUN sh /root/set_nginx_htpasswd.sh
ADD docker/config/nginx.conf     /etc/nginx/sites-available/default

RUN pecl install mongo

# Set up Sabre DAV
WORKDIR /var/www
RUN rm -rf composer.json composer.lock vendor data html server.php
COPY composer.json /var/www/composer.json
RUN composer clearcache
RUN composer update

COPY . /var/www
RUN chown -R www-data:www-data /var/www

ADD docker/supervisord.conf /etc/supervisor/conf.d/

EXPOSE 80

CMD ["sh", "./scripts/start.sh"]
