# SASA — backend

Laravel 13 API for the SASA platform. See the repository root [README.md](../README.md) and
[docs/ARCHITECTURE.md](../docs/ARCHITECTURE.md).

## Layout

```
app/Domain/       business logic by module — services, engines, provider abstractions
app/Http/         thin controllers, form requests, API resources, middleware
app/Models/       Eloquent, with Concerns/ traits for tenancy, references, audit, archiving
app/Policies/     authorisation, per project and per record
```

## Non-negotiables

* Tenant scope comes from `TenantContext`, filled only by `EnsureProjectContext`. A request
  parameter is never trusted.
* A record from another project raises **not found**, not forbidden.
* Complainant identity is added to a response **only** inside `if ($canSeeIdentity)`.
* Audit rows are append-only. Archive; never delete.
* Reference numbers are allocated under `SELECT … FOR UPDATE`.
* Domain services own the logic and are wrapped in transactions; controllers call one.

## Commands

```bash
php artisan migrate --seed        # schema + the demo project
php artisan serve --port=8010
php artisan queue:work            # the SLA engine and notifications live here
php artisan schedule:work

php artisan sasa:sla-sweep --sync # evaluate every clock now
php artisan sasa:commitment-reminders
php artisan sasa:missed-engagements
php artisan sasa:daily-reminders
php artisan sasa:backup

vendor/bin/phpunit                # 176 tests, against MySQL
vendor/bin/pint                   # formatting
```

Tests run against **MySQL** (`sasa_testing`), not SQLite: the schema uses `FULLTEXT` and the
dashboards use `JSON_CONTAINS`, `DATE_FORMAT` and `TIMESTAMPDIFF`.
