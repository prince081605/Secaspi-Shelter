# SECASPI Shelter — Local Setup

Laravel backend + React (Vite) frontend.

## Requirements

- PHP 8.2+ with the `pdo_sqlite` and `sqlite3` extensions enabled
  (on Windows, uncomment `extension=pdo_sqlite` and `extension=sqlite3` in `php.ini`,
  then confirm with `php -r "print_r(PDO::getAvailableDrivers());"`)
- Composer
- Node.js 18+

## Backend

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan serve
```

The default `.env.example` uses SQLite, so no database server or credentials
are needed. To use MySQL instead, set `DB_CONNECTION=mysql` plus the `DB_*`
credentials in `.env` and create the database first.

## Frontend

```bash
cd frontend
npm install
npm run dev
```

## Notes

- `.env` is intentionally not committed. Always start from `.env.example`;
  do not copy a `.env` between machines, since database settings differ per machine.
- `SESSION_DRIVER` and `CACHE_STORE` both default to `database`, so the
  `sessions`, `cache` and `cache_locks` tables must exist. `php artisan migrate`
  creates them.
