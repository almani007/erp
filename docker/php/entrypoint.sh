#!/bin/bash
set -e

# Ensure Laravel storage and cache directories exist and have proper permissions
mkdir -p /var/www/erp/storage/framework/cache/data \
         /var/www/erp/storage/framework/sessions \
         /var/www/erp/storage/framework/views \
         /var/www/erp/storage/logs \
         /var/www/erp/bootstrap/cache

chown -R www-data:www-data /var/www/erp/storage /var/www/erp/bootstrap/cache || true
chmod -R 775 /var/www/erp/storage /var/www/erp/bootstrap/cache || true

# The application key is generated once by setup.sh. It is deliberately NOT
# regenerated here: rotating it on container restart would invalidate every
# encrypted value.

exec "$@"
