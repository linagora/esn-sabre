#
# Docker container for ESN Sabre frontend
#
# Build:
# docker build -t linagora/esn-sabre .
#
# Run:
# docker run -d -p 8001:80 -e "SABRE_MONGO_HOST=192.168.0.1" -e "ESN_MONGO_HOST=192.168.0.1" linagora/esn-sabre
#

FROM linagora/sabre-dav
MAINTAINER Linagora Folks <openpaas@linagora.com>

# Install Packages
RUN apt-get update && \
    DEBIAN_FRONTEND=noninteractive apt-get -y upgrade && \
    DEBIAN_FRONTEND=noninteractive apt-get -y install git php5-curl php-pear php5-dev make
RUN pecl install mongo

# Configure PHP
RUN echo "extension=mongo.so" >> /etc/php5/fpm/php.ini && \
    echo "extension=mongo.so" >> /etc/php5/cli/php.ini

# Set up Sabre DAV
WORKDIR /var/www
RUN rm -rf composer.json composer.lock vendor data html server.php
COPY composer.json /var/www/composer.json
RUN composer update

COPY . /var/www
RUN sed -i 's/server.php/esn.php/' /etc/nginx/sites-enabled/default
RUN sed -i 's/server.php/esn.php/' /etc/nginx/sites-available/default
RUN chown -R www-data:www-data /var/www

EXPOSE 80

# Clean up APT when done.
RUN apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

CMD ["sh", "./scripts/start.sh"]
