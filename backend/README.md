# SASA — backend

The Laravel API behind SASA. See the repository root [README.md](../README.md) to run the
whole thing, and [docs/](../docs) for architecture, database, API, permissions and deployment.

## Quick start

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan serve --port=8010
php artisan queue:work &     # the SLA engine and notifications
php artisan schedule:work &  # sweeps, reminders, backups
```

`GET /api/v1/health` reports the database, cache, storage and queue.

Demo accounts are listed in the root README; every one uses the password `password`.
