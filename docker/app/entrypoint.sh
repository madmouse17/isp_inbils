#!/bin/sh
set -eu

mkdir -p \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache

if [ "${1:-}" = "frankenphp" ] || { [ "${1:-}" = "php" ] && [ "${2:-}" = "artisan" ]; }; then
    php artisan package:discover --ansi
fi

if [ "${1:-}" = "frankenphp" ]; then
    php artisan storage:link 2>/dev/null || true
    php artisan config:cache
    php artisan event:cache
    php artisan route:cache
    php artisan view:cache
fi

# A crash leaves overlap mutexes in the cache; clear them so the scheduler does not skip runs until they expire.
if [ "${1:-}" = "php" ] && [ "${2:-}" = "artisan" ] && [ "${3:-}" = "schedule:work" ]; then
    php artisan schedule:clear-cache
fi

exec "$@"
