#!/bin/sh
set -e

php artisan config:clear
php artisan migrate --force
php artisan db:seed --force
php artisan storage:link || true

PORT="${PORT:-10000}"
exec php artisan serve --host=0.0.0.0 --port="$PORT"
