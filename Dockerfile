#
# Docker container for ESN Sabre frontend
#
# Build:
# docker build -t linagora/esn-sabre .
#
# Run:
# docker run -d -p 8001:80 --add-host esn_host:<ESN_HOST_IP> --add-host esn_mongo:<ESN_MONGO_IP> --add-host sabre_mongo:<SABRE_MONGO_IP> linagora/esn-sabre
#

FROM linagora/sabre-dav:0.1.0
MAINTAINER Linagora Folks <openpaas@linagora.com>

# Install Packages
RUN apt-get update && \
    apt-get -y upgrade && \
    apt-get -y install git php5-curl php-pear php5-dev make
RUN pecl install mongo

# Configure PHP
RUN echo "extension=mongo.so" >> /etc/php5/fpm/php.ini && \
    echo "extension=mongo.so" >> /etc/php5/cli/php.ini

# Set up Sabre DAV
WORKDIR /var/www
RUN rm -rf composer.json composer.lock vendor data html server.php
ADD composer.json /var/www
RUN composer update

ADD . /var/www
RUN mv esn.php server.php
RUN chown -R www-data:www-data /var/www

EXPOSE 80

# Clean up APT when done.
RUN apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

CMD ["sh", "./scripts/start.sh"]
