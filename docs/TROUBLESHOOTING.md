# Troubleshooting

## Setting up

**`SQLSTATE[42000]: Syntax error … FULLTEXT`**
MySQL 5.7 or MariaDB. SASA needs MySQL 8.0+: the schema uses `FULLTEXT` on InnoDB and the
dashboards use `JSON_CONTAINS`.

**`Rate limiter [api] is not defined`**
`AppServiceProvider::configureRateLimiting()` did not run. Clear the cached config:
`php artisan config:clear`.

**`No morph map defined for model […]`**
`Relation::enforceMorphMap()` is deliberately strict, so a class rename can never orphan an
audit row. Add the model to the map in `AppServiceProvider::boot()`.

**Migrations fail on a re-run**
`php artisan migrate:fresh --seed` — but never in production.

## Signing in

**"That email address and password do not match" for a password you are sure of**
The answer is deliberately identical whether the account exists or not. Check the account is
`active` and not archived, then check `locked_until`: five failed attempts locks it for 15
minutes. `php artisan tinker` → `User::where('email', …)->update(['locked_until' => null, 'failed_login_attempts' => 0])`.

**CORS errors in the browser console**
`SASA_CORS_ORIGINS` in the backend `.env` must contain the exact browser origin, scheme and
port included. Then `php artisan config:clear`.

**"Choose a project before continuing" (400)**
The request has no `X-Sasa-Project`, or the signed-in user has no active membership. Add them
on `/people`.

## Records and permissions

**A record returns 404 that I know exists**
By design, one of three things: it belongs to another project; it is a restricted-category
case and you are not in its handling group; or it has been archived. A 403 would confirm the
id is real somewhere, so SASA does not distinguish them.

**Complainant details are missing from a case**
Also by design. Check `identity_withheld_reason` on the case. Either it is anonymous (nothing
was ever stored) or it is confidential and you lack `grievance.view_confidential`, the
handling group, or both.

**A button is missing**
Hidden buttons follow permissions, but permissions are enforced on the server. Check your
role on **this** project — it may differ from your role on another.

## SLA and notifications

**Deadlines never move; nothing is ever flagged as breached**
The scheduler is not running. `php artisan schedule:work` in development, or the cron entry in
production. Confirm with `php artisan sasa:sla-sweep --sync`.

**No notifications arrive**
Two possibilities. The queue worker is not running (`php artisan queue:work`), or the
notification rule is inactive — check `/configuration` → Notifications. Users can also mute
their own notifications on `/account`.

**A deadline looks wrong**
Check the working calendar. Two working days from a Friday is a Tuesday, and a public holiday
pushes it further. `/configuration` → Working calendar shows exactly what the engine counts.

## Offline and sync

**"You are offline" when the connection is fine**
`navigator.onLine` reports the network interface, not reachability. Press **Sync now** on
`/sync`; if the request succeeds the state corrects itself.

**Work is stuck in the queue**
Open `/sync`. Each operation shows its status and, when it failed, the reason. Use "Retry
everything that failed". Backoff is exponential to 10 minutes, so a recently failed operation
may simply be waiting.

**A conflict will not go away**
It needs `sync.resolve_conflicts` — a project administrator or a grievance officer. Somebody
must choose which version is right; SASA will not guess with a protected field.

**"Clear the local copy" is disabled**
Deliberate: it is disabled while work is still waiting to sync. That is the whole point of the
local copy.

**The app will not open offline on a device**
It must be opened online at least once so the service worker can cache the shell. The service
worker is registered in production builds only — `npm run dev` will not do it.

## Reports and files

**A report fails to generate**
Check `storage/logs/laravel.log`. The usual causes are the storage disk not being writable,
PHP memory on a very large register (`memory_limit`), or the FPM read timeout being shorter
than generation takes.

**A download link says it has expired**
Signed URLs live for `SASA_SIGNED_URL_TTL_MINUTES` (default 10). Reopen the record for a fresh
one.

**An upload is refused**
Either the size cap (`SASA_ATTACHMENT_MAX_MB`, and nginx's `client_max_body_size` must exceed
it) or the MIME allow-list in `config/sasa.php`.

## Performance

**A dashboard is slow**
Check the reporting period — twelve months of a busy project is a lot of rows. The composite
indexes in [DATABASE.md](DATABASE.md) cover the intended queries; if one is missing from your
deployment, `EXPLAIN` the query the slow log names.

**A list is slow**
Reduce `per_page`, or narrow the location filter — it expands to every descendant location.

## Getting the detail

Every response carries `X-Sasa-Request-Id`, and the same id is on the log line and on any
audit row the request produced:

```bash
grep "<request-id>" storage/logs/laravel.log
```

```sql
SELECT * FROM audit_logs WHERE request_id = '<request-id>';
```
