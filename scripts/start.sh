#!/bin/bash

export NGINX_RATE_LIMIT=${NGINX_RATE_LIMIT:-50r/s}
export NGINX_RATE_ZONE_SIZE=${NGINX_RATE_ZONE_SIZE:-10m}
export NGINX_RATE_BURST=${NGINX_RATE_BURST:-100}

envsubst '${NGINX_RATE_LIMIT} ${NGINX_RATE_ZONE_SIZE}' \
  < /etc/nginx/conf.d/rate_limit.conf.template \
  > /etc/nginx/conf.d/rate_limit.conf

envsubst '${NGINX_RATE_BURST}' \
  < /etc/nginx/sites-available/default.template \
  > /etc/nginx/sites-available/default

/root/set_nginx_htpasswd.sh

sh /var/www/scripts/generate_config.sh > /var/www/config.json

# Indexes are created here once, not on every request. A failure must not keep sabre from starting.
php /var/www/scripts/create-indexes.php /var/www/config.json || echo "WARNING: MongoDB index creation failed, see doc/storage/MONGO.md to create them manually" >&2

/usr/bin/supervisord
