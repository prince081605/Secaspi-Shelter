#!/bin/sh
set -e

php artisan config:clear
php artisan migrate --force
php artisan db:seed --force
php artisan storage:link || true

# Run Laravel's scheduler in the background so daily jobs (e.g. health-reminder
# dispatch) actually fire on this single-container host. If it ever dies, the app
# keeps serving — the admin dashboard reads reminders directly and doesn't depend
# on this process.
php artisan schedule:work &

PORT="${PORT:-10000}"
exec php artisan serve --host=0.0.0.0 --port="$PORT"
