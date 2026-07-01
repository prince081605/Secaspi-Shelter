# Local vs Production parity

The app runs on **three** databases and must behave identically on all of them:

| Environment | Database |
|-------------|----------|
| Production (Render) | **PostgreSQL** |
| Local development | **MySQL** (`secaspi_shelter_mock`) |
| Automated tests | **SQLite** (in-memory) |

Most of the code is already engine-agnostic. The notes below are the things that can make a
feature "work in one environment but not another," and how each is handled.

## SQL dialect rules (so queries behave the same on MySQL + Postgres)

- **Case-insensitive `LIKE` searches.** Plain `LIKE` is case-insensitive on MySQL/SQLite but
  **case-sensitive on Postgres**. Always normalise both sides:
  ```php
  $needle = '%' . mb_strtolower($q) . '%';
  $query->whereRaw('LOWER(column) LIKE ?', [$needle]);
  ```
  See `AnimalController::index()` and `UserController::adminIndex()`.
- **Raw SQL must use standard functions only** (`COUNT`, `COALESCE`, `SUM`, `LOWER`,
  `ROW_NUMBER() OVER`, `CASE`). Avoid MySQL-only (`DATE_FORMAT`, `IF()`, `RAND()`) and
  Postgres-only (`ILIKE`, `::cast`) functions in shared queries.
- **Date filters:** use Laravel's `whereDate/whereMonth/whereYear` (it emits the right SQL per
  driver) rather than raw `YEAR()/EXTRACT()`.
- **Aggregation that needs DB-specific bucketing** (e.g. month grouping) is done in PHP — see the
  transparency trend in `routes/api_public.php`.
- **JSON columns** (`animals.behavioral_assessment`, `care_guides.*_keywords`) are read through
  Eloquent `array` casts and iterated in PHP — never via `whereJsonContains` / JSON operators.
- **Driver-specific DDL in migrations** must branch on the driver:
  ```php
  if (Schema::getConnection()->getDriverName() === 'pgsql') { /* Postgres */ }
  else { /* MySQL + SQLite */ }
  ```
  See `..._convert_behavioral_assessment_to_json.php` and
  `..._alter_volunteer_tasks_status_to_string.php`.

## Environment/config parity (so local mirrors prod functionality)

- **Uploaded images.** Prod uses Cloudflare R2 (`FILESYSTEM_DISK=s3`); local uses the `public`
  disk. Locally you must run `php artisan storage:link` and set `APP_URL` correctly, or image
  URLs 404. All URLs are generated via `Storage::url()`.
- **CORS.** `config/cors.php` reads `FRONTEND_URL`. Local `.env` must set
  `FRONTEND_URL=http://localhost:5173` or the React dev app is CORS-blocked.
- **APP_ENV.** Keep `APP_ENV=local` locally so dev conveniences work (e.g. the forgot-password
  endpoint returns the reset token in the response when mail isn't configured).
- **Queue + scheduler.** Prod runs `schedule:work` and the queue worker via
  `docker-entrypoint.sh`. Locally run `composer dev` (starts `php artisan serve`,
  `queue:listen`, and vite) so queued notifications/reminders are processed.
- **Mail.** Prod needs real SMTP; local can stay `MAIL_MAILER=log`.

## Catching dialect bugs before deploy

Tests run on SQLite, whose `LIKE` is case-insensitive like MySQL — so a Postgres-only bug can
pass the suite. Two safeguards:

1. Tests that depend on case-sensitivity flip SQLite to match Postgres:
   `DB::statement('PRAGMA case_sensitive_like = ON;')` — see `tests/Feature/UserSearchTest.php`.
2. (Optional, recommended before a release) run the suite against a scratch Postgres:
   point `DB_CONNECTION=pgsql` at a throwaway database and run `php artisan test`.
