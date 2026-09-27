# SASA — Stakeholder Intelligence & Voice

A focused stakeholder intelligence, engagement, grievance and reporting platform for
infrastructure and extractive projects.

SASA keeps one chain of records:

```
STAKEHOLDER → ENGAGEMENT → CONCERN → GRIEVANCE → ACTION → COMMITMENT → RESOLUTION → REPORTING
```

Four connected modules over one shared data spine:

| Module | What it is | Where it lives |
| --- | --- | --- |
| **1 · Stakeholder Register** | Who the stakeholders are, ranked transparently, with consent and review dates | `/stakeholders` |
| **2 · Stakeholder Management** | Engagement plans, engagement logs, concerns, and the commitments register | `/engagements`, `/concerns`, `/commitments` |
| **3 · Grievance Management** | Every channel into one case, with a configurable SLA and escalation engine | `/grievances` |
| **4 · Dashboards & Reporting** | Five dashboards and a reporting engine, generated from live records | `/analytics`, `/reports` |

Plus an AI voice layer at intake (`/ai`), a real offline-first field mode (`/sync`), and an
append-only audit trail (`/audit`).

---

## What you need

| | Version | Notes |
| --- | --- | --- |
| PHP | 8.3+ | with `pdo_mysql`, `mbstring`, `gd`, `zip`, `intl` |
| Composer | 2.x | |
| MySQL | 8.0+ | 5.7 will not do: the schema uses `JSON_CONTAINS` and `FULLTEXT` on InnoDB |
| Node.js | 20+ | 24 recommended |
| npm | 10+ | |

Optional: Redis for queues and cache in production; ClamAV if you want uploads virus-scanned.

---

## Running it locally

```bash
# ---- database -------------------------------------------------------------
mysql -uroot -e "CREATE DATABASE sasa CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -uroot -e "CREATE DATABASE sasa_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# ---- backend ---------------------------------------------------------------
cd backend
composer install
cp .env.example .env          # then set DB_USERNAME / DB_PASSWORD
php artisan key:generate
php artisan migrate --seed    # schema + demo project, users and records
php artisan serve --port=8010

# in a second terminal — the SLA engine and notifications run here
php artisan queue:work
php artisan schedule:work

# ---- frontend --------------------------------------------------------------
cd ../web
npm install
cp .env.example .env.local    # NEXT_PUBLIC_API_URL=http://localhost:8010/api/v1
npm run dev                   # http://localhost:3010
```

Open <http://localhost:3010>. The seeded demo accounts all use the password `password`:

| Role | Email | What they see |
| --- | --- | --- |
| System administrator | `admin@sasa.test` | Everything, on every project |
| Project administrator | `project.admin@sasa.test` | One project, end to end |
| Management / executive | `executive@sasa.test` | "What is happening on this project?" |
| Project management | `pm@sasa.test` | "What needs my decision?" |
| Grievance officer | `grievance@sasa.test` | "What needs attention today?" — sees restricted cases |
| Community relations officer | `cro@sasa.test` | "Who am I engaging, and what did they raise?" |
| HR officer | `hr@sasa.test` | Worker cases, including restricted ones |
| HSE officer | `hse@sasa.test` | Environment, health and safety cases |
| Security officer | `security@sasa.test` | Security-related cases |
| Field officer | `field@sasa.test` | "What do I need to do today?" — the offline experience |
| Auditor / lender | `auditor@sasa.test` | Read-only on one project |

Sign in as the **field officer** and as the **grievance officer** side by side to see
field-level confidentiality working: the same case looks different to each of them.

---

## Testing

```bash
# Backend — 176 tests against MySQL, not SQLite, because the product uses MySQL
cd backend && vendor/bin/phpunit

# Frontend unit and component tests
cd web && npm test

# End to end, including the offline acceptance test.
# The offline test needs a production build so the service worker is active.
cd web && npm run build && npx next start --port 3010
npx playwright test
```

See [docs/TESTING.md](docs/TESTING.md).

---

## Documentation

| | |
| --- | --- |
| [Architecture](docs/ARCHITECTURE.md) | How the pieces fit, and why |
| [Design system](docs/DESIGN.md) | Tokens, the two themes, charts, loading states |
| [Database](docs/DATABASE.md) | The 40-table schema, entity by entity |
| [API](docs/API.md) | Every endpoint, with its permission |
| [Offline and sync](docs/OFFLINE.md) | The local database, the queue, and conflict handling |
| [Permissions](docs/PERMISSIONS.md) | Roles, permissions, and field-level confidentiality |
| [Configuration](docs/CONFIGURATION.md) | What is configurable, and where |
| [Deployment](docs/DEPLOYMENT.md) | Production setup, queues, scheduler, backups |
| [Testing](docs/TESTING.md) | How to run and extend the suites |
| [Troubleshooting](docs/TROUBLESHOOTING.md) | When something is not working |
| [Assumptions](docs/ASSUMPTIONS.md) | Every decision taken where the source specification was ambiguous |

---

## The shape of the code

```
backend/
  app/
    Domain/            # the business logic, by module — services, engines, providers
      Ai/              #   AI boundary: proposes, never decides
      Audit/           #   the append-only trail
      Configuration/   #   three-layer configuration resolution
      Dashboard/       #   the five dashboards and one definition per metric
      Engagement/      #   plans, logs, concerns, commitments
      Export/ Import/  #   the import wizard and permission-aware exports
      Grievance/       #   intake engine, lifecycle, confidentiality guard
      Identity/        #   the permission catalogue
      Reporting/       #   report documents and the four format writers
      Sla/             #   the working calendar and the SLA engine
      Stakeholder/     #   the register and the priority engine
      Sync/            #   the server half of the offline contract
      Tenancy/         #   tenant context and reference-number allocation
      Voice/           #   the AI voice agent and its provider abstraction
    Http/              # thin controllers, form requests, API resources
    Models/            # 30 Eloquent models over one shared spine
    Policies/          # authorisation, enforced at the API
  database/migrations/ # 7 schema migrations + Sanctum
  database/seeders/    # permissions, then a realistic Tanzanian demo project
  tests/               # 176 feature and unit tests

web/
  src/
    app/(app)/         # the authenticated application
    components/        # the SASA design system
    hooks/             # list state, offline-capable reference data
    lib/
      api/             # typed client and query hooks
      offline/         # LocalStore interface, IndexedDB driver, sync engine
    providers/         # session, sync, query, toast
  e2e/                 # Playwright, including the offline acceptance test
  tests/               # Vitest component and store tests
```
