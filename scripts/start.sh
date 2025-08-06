#!/bin/bash

sh /var/www/scripts/generate_config.sh > /var/www/config.json
/usr/bin/supervisord
