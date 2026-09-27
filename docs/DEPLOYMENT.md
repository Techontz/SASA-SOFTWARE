# Deployment

## Shape

```
                    ┌──────────────┐
   users ─── TLS ──▶│  Web server  │
                    │ nginx/Caddy  │
                    └──┬────────┬──┘
                       │        │
        ┌──────────────▼─┐   ┌──▼─────────────────┐
        │ Next.js (3010) │   │ PHP-FPM — Laravel  │
        │ node, standalone│  │ /api/v1            │
        └────────────────┘   └──┬───────┬─────────┘
                                │       │
             ┌──────────────────▼┐   ┌──▼──────────┐
             │ MySQL 8 (primary) │   │ Redis       │
             │ + read replica    │   │ queue+cache │
             └───────────────────┘   └─────────────┘
                                │
                  ┌─────────────▼────────────┐
                  │ queue worker × N         │
                  │ scheduler (cron)         │
                  │ private object storage   │
                  └──────────────────────────┘
```

## Backend

```bash
git clone … && cd backend
composer install --no-dev --optimize-autoloader

cp .env.example .env          # fill it in — see docs/CONFIGURATION.md
php artisan key:generate
php artisan migrate --force
php artisan db:seed --class=PermissionSeeder --force   # permissions and role templates only

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link      # only if you serve public assets from storage
```

Do **not** run `DemoSeeder` in production — it creates a demo organisation and test accounts.

### The queue worker

The SLA engine, notifications and reminders run here. Without it, deadlines are not evaluated
and nobody is told anything.

```ini
# /etc/supervisor/conf.d/sasa-worker.conf
[program:sasa-worker]
command=php /var/www/sasa/backend/artisan queue:work --queue=default --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
numprocs=2
user=www-data
stopwaitsecs=3600
redirect_stderr=true
stdout_logfile=/var/log/sasa/worker.log
```

### The scheduler

```cron
* * * * * cd /var/www/sasa/backend && php artisan schedule:run >> /dev/null 2>&1
```

| Job | When | What it does |
| --- | --- | --- |
| `sasa:sla-sweep` | every 15 min | Re-evaluates every open clock, sends threshold reminders, escalates breaches |
| `sasa:commitment-reminders` | 07:00 daily | Flips due commitments to overdue and fires the configured reminders |
| `sasa:missed-engagements` | 01:30 daily | Flags plans whose window passed with nothing logged |
| `sasa:daily-reminders` | 07:15 daily | Register entries due for review, engagements coming up |
| `sasa:backup` | 02:00 daily | Compressed `mysqldump`, pruned to the retention window |
| `queue:prune-failed` | weekly | |
| `sanctum:prune-expired` | daily | |

## Frontend

```bash
cd web
npm ci
cp .env.example .env.local     # NEXT_PUBLIC_API_URL must be the public API URL
npm run build
npx next start --port 3010     # or run under pm2 / systemd
```

```ini
# /etc/systemd/system/sasa-web.service
[Service]
WorkingDirectory=/var/www/sasa/web
ExecStart=/usr/bin/npx next start --port 3010
Restart=always
User=www-data
Environment=NODE_ENV=production
```

## nginx

```nginx
server {
    listen 443 ssl http2;
    server_name sasa.example;

    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options DENY always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;

    client_max_body_size 30M;   # must exceed SASA_ATTACHMENT_MAX_MB

    location / {
        proxy_pass http://127.0.0.1:3010;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    # The service worker must never be served stale, or a fix can take days to
    # reach a field device.
    location = /sw.js {
        proxy_pass http://127.0.0.1:3010;
        add_header Cache-Control "public, max-age=0, must-revalidate";
    }
}

server {
    listen 443 ssl http2;
    server_name api.sasa.example;
    root /var/www/sasa/backend/public;
    index index.php;
    client_max_body_size 30M;

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;   # report generation
    }
}
```

Set `SASA_CORS_ORIGINS=https://sasa.example` so the browser origin is allowed and nothing else is.

## MySQL

```ini
[mysqld]
character-set-server = utf8mb4
collation-server     = utf8mb4_unicode_ci
innodb_buffer_pool_size = 2G          # ~70% of available RAM on a dedicated host
innodb_flush_log_at_trx_commit = 1    # durability over throughput: this is a record of record
max_connections = 200
ft_min_word_len = 3                   # FULLTEXT search on names and descriptions
```

Point the read replica at `DB_HOST_READ` when analytics load starts competing with intake.

## Health and observability

`GET /api/v1/health` checks the database, cache, storage and queue, and returns **503** when
any is failing. Point the load balancer at it.

Structured logs carry a `request_id` that also appears in the response header and on every
audit row it produced, so a user's report of "something went wrong at about 11:20" is
traceable. Slow requests (>2s) and every 5xx are logged with the path, status, duration and
user.

## Backups

`php artisan sasa:backup` writes a gzipped `mysqldump` (`--single-transaction --quick
--routines --events`) to `SASA_BACKUP_PATH` and prunes beyond `SASA_BACKUP_RETENTION_DAYS`
(default 30). It runs at 02:00 from the scheduler.

**This is a real, runnable backup — but a backup you have never restored is a hope, not a
backup.** Set up in addition:

* off-host replication of the backup directory (`rclone`, `aws s3 sync`, or a mounted bucket);
* versioning and lifecycle rules on the object storage that holds attachments and reports —
  the database dump does not contain them;
* a **restore rehearsal** on a schedule you actually keep.

```bash
# Restore
gunzip -c /var/backups/sasa/sasa-2026-08-30-020000.sql.gz | mysql -u root -p sasa
php artisan config:cache
# then re-sync the attachment bucket
```

## Release checklist

1. `php artisan down --render="errors::503"`
2. Pull, `composer install --no-dev -o`, `npm ci && npm run build`
3. `php artisan migrate --force` — additive migrations only; see [DATABASE.md](DATABASE.md)
4. `php artisan config:cache route:cache view:cache`
5. Restart PHP-FPM, the queue workers and the Next.js service
6. `php artisan up`
7. `curl https://api.sasa.example/api/v1/health`

## Security posture

| | |
| --- | --- |
| Transport | TLS everywhere; HSTS; the API forces HTTPS in production |
| Auth | Sanctum bearer tokens with an expiry; shorter for administrators; password change revokes every other session |
| Brute force | Five failed attempts locks the account for 15 minutes; a coarse per-IP throttle on top |
| Authorisation | Policies and `permission:` middleware on every route; never inferred from the UI |
| Tenancy | Derived from the membership; a global scope as defence in depth |
| At rest | Complainant identity, precise location, message recipients, caller numbers and MFA secrets are encrypted; blind indexes keep search working |
| Files | Private disk, short-lived signed URLs, MIME allow-list, size cap, optional ClamAV; the storage path is never exposed |
| Injection | Eloquent and parameter binding throughout; no string-built SQL |
| XSS | React escapes by default; no `dangerouslySetInnerHTML` anywhere in the codebase |
| Headers | `nosniff`, `DENY`, `no-referrer`, a restrictive `Permissions-Policy`, and `Cache-Control: no-store` on every API response |
| Audit | Append-only; `save()` and `delete()` on an existing row throw |
