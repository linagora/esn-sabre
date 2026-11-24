#
# Optimized Docker container for ESN Sabre frontend
# Optimizations:
# - Use debian:bullseye-slim base image
# - Remove build tools after compilation
# - Install composer dependencies without dev packages
# - Combine RUN commands to reduce layers
# - Clean up caches and temporary files
#
# Build:
# docker build --pull --no-cache -t linagora/esn-sabre .
#
# Run:
# docker run -d -p 8001:80 -e "SABRE_MONGO_HOST=192.168.0.1" -e "ESN_MONGO_HOST=192.168.0.1" linagora/esn-sabre
#

FROM debian:trixie-slim
LABEL maintainer="Linagora Folks <openpaas@linagora.com>"

ENV PHPVERSION=8.4

# MongoDB PHP extension, bumped together with mongodb/mongodb in composer.json.
ARG MONGODB_VERSION=2.5.5
ARG MONGODB_SHA256=0eaf84ff709f73183d80597f31ff02937f6aefde01eb895a0aa19334a290cec0

# Copy PHP configuration files first (these rarely change)
ADD ./docker/config/20-apcu.ini /etc/php/${PHPVERSION}/fpm/conf.d/20-apcu.ini
ADD ./docker/config/05-opcache.ini /etc/php/${PHPVERSION}/fpm/conf.d/05-opcache.ini
ADD ./docker/config/05-opcache.ini /etc/php/${PHPVERSION}/cli/conf.d/05-opcache.ini

# Install runtime packages + build tools, compile mongodb extension, then remove build tools
RUN apt-get update && \
  DEBIAN_FRONTEND=noninteractive apt-get -y upgrade && \
  DEBIAN_FRONTEND=noninteractive apt-get -y install --no-install-recommends \
    # Runtime packages
    php${PHPVERSION}-fpm \
    php${PHPVERSION}-cli \
    php${PHPVERSION}-curl \
    php${PHPVERSION}-ldap \
    php${PHPVERSION}-bcmath \
    php${PHPVERSION}-mbstring \
    php${PHPVERSION}-zip \
    php${PHPVERSION}-xml \
    php-apcu \
    supervisor \
    nginx \
    # Kerberos (SPNEGO) authentication, only active with KERBEROS_ENABLED=true
    libnginx-mod-http-auth-spnego \
    gettext-base \
    ca-certificates \
    curl \
    tzdata \
    tzdata-legacy \
    # Build tools (temporary)
    php-pear \
    php${PHPVERSION}-dev \
    make \
    pkg-config \
    git && \
  # Download the release archive directly to avoid PECL REST metadata lookups.
  # Pinned by SHA-256 (the digest GitHub publishes for the asset, identical to
  # the PECL archive): a failed download or check fails the build rather than
  # building another, unverified version of an extension every worker loads.
  curl -fsSL --retry 3 --retry-all-errors "https://github.com/mongodb/mongo-php-driver/releases/download/${MONGODB_VERSION}/mongodb-${MONGODB_VERSION}.tgz" -o /tmp/mongodb.tgz && \
  echo "${MONGODB_SHA256}  /tmp/mongodb.tgz" | sha256sum -c - && \
  pecl install /tmp/mongodb.tgz && \
  rm -f /tmp/mongodb.tgz && \
  echo "extension=mongodb.so" >> /etc/php/${PHPVERSION}/fpm/php.ini && \
  echo "extension=mongodb.so" >> /etc/php/${PHPVERSION}/cli/php.ini && \
  # Remove build tools to reduce image size
  apt-get purge -y php-pear php${PHPVERSION}-dev make pkg-config && \
  apt-get autoremove -y && \
  apt-get clean && \
  rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /root/.pearrc

# Configure PHP (combine all sed commands)
RUN sed -i "s/;date.timezone =.*/date.timezone = UTC/" /etc/php/${PHPVERSION}/fpm/php.ini && \
  sed -i "s/;date.timezone =.*/date.timezone = UTC/" /etc/php/${PHPVERSION}/cli/php.ini && \
  sed -i -e "s/;daemonize\s*=\s*yes/daemonize = no/g" /etc/php/${PHPVERSION}/fpm/php-fpm.conf && \
  sed -i "s/;cgi.fix_pathinfo=1/cgi.fix_pathinfo=0/" /etc/php/${PHPVERSION}/fpm/php.ini && \
  sed -i -e "s/memory_limit\s*=\s*128M/memory_limit = 512M/g" /etc/php/${PHPVERSION}/fpm/php.ini && \
  sed -i "s/max_execution_time = 30/max_execution_time = 120/" /etc/php/${PHPVERSION}/fpm/php.ini && \
  sed -i "s/;listen.owner = www-data/listen.owner = www-data/" /etc/php/${PHPVERSION}/fpm/pool.d/www.conf && \
  sed -i "s/;listen.group = www-data/listen.group = www-data/" /etc/php/${PHPVERSION}/fpm/pool.d/www.conf && \
  sed -i "s/;listen.mode = 0660/listen.mode = 0660/" /etc/php/${PHPVERSION}/fpm/pool.d/www.conf && \
  sed -i "s|^listen = .*|listen = /var/run/php/php-fpm.sock|" /etc/php/${PHPVERSION}/fpm/pool.d/www.conf && \
  sed -i "s/;listen.backlog = 511/listen.backlog = 4096/" /etc/php/${PHPVERSION}/fpm/pool.d/www.conf && \
  sed -i "s/pm.max_children = 5/pm.max_children = 96/" /etc/php/${PHPVERSION}/fpm/pool.d/www.conf && \
  sed -i "s/pm.start_servers = 2/pm.start_servers = 8/" /etc/php/${PHPVERSION}/fpm/pool.d/www.conf && \
  sed -i "s/pm.max_spare_servers = 3/pm.max_spare_servers = 16/" /etc/php/${PHPVERSION}/fpm/pool.d/www.conf && \
  sed -i "s/;clear_env = no/clear_env = no/" /etc/php/${PHPVERSION}/fpm/pool.d/www.conf && \
  sed -i "s/;request_terminate_timeout = 0/request_terminate_timeout = 0/" /etc/php/${PHPVERSION}/fpm/pool.d/www.conf && \
  # Logs configuration
  sed -i "s/error_log = \/var\/log\/php${PHPVERSION}-fpm.log/error_log = \/proc\/self\/fd\/2/" /etc/php/${PHPVERSION}/fpm/php-fpm.conf && \
  sed -i "s/;catch_workers_output = yes/catch_workers_output = yes/" /etc/php/${PHPVERSION}/fpm/pool.d/www.conf

# Set up Nginx (combine RUN commands)
RUN ln -sf /dev/stderr /var/log/nginx/error.log && \
    ln -sf /dev/stdout /var/log/nginx/access.log

# Copy composer from the official image, pinned by digest
COPY --from=composer:2.11.2@sha256:cd3a90dc5624c59a2f9ddf80dad08569f06416db31de519024f249d63bca554b /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www

# Copy application code
COPY . /var/www

# Install dependencies without dev packages (using install to respect composer.lock)
RUN git config --global --add safe.directory '/var/www/vendor/sabre/vobject' && \
    composer clearcache && \
    composer install --no-dev --optimize-autoloader --apcu-autoloader --no-interaction && \
    # Clean up composer cache and remove git
    rm -rf /root/.composer/cache && \
    apt-get purge -y git && \
    apt-get autoremove -y && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# Configure application
RUN cp -v docker/prepare/set_nginx_htpasswd.sh /root/set_nginx_htpasswd.sh && \
    cp -v docker/config/nginx.conf /etc/nginx/nginx.conf && \
    cp -v docker/config/default.conf.template /etc/nginx/sites-available/default.template && \
    cp -v docker/config/kerberos.conf.template /etc/nginx/kerberos.conf.template && \
    cp -v docker/config/cors.conf /etc/nginx/conf.d/cors.conf && \
    cp -v docker/supervisord.conf /etc/supervisor/conf.d/ && \
    rm -rf html && \
    chown -R www-data:www-data /var/www && \
    mkdir -p /var/run/php

EXPOSE 80

CMD ["bash", "./scripts/start.sh"]
