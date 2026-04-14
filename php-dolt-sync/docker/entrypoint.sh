#!/usr/bin/env bash
set -euo pipefail

chown -R www-data:www-data /var/www/html/wp-content /var/www/html/wpsync-data 2>/dev/null || true
exec "$@"
