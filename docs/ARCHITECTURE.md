# Architecture

## The shape of it

```
┌─────────────────────────────────────────────────────────────────────┐
│  Browser / phone                                                    │
│                                                                     │
│  Next.js (App Router, React, TypeScript strict)                     │
│    ├── TanStack Query        server state, cached, stale-while-revalidate
│    ├── LocalStore            IndexedDB — records, queue, files, drafts
│    ├── SyncEngine            queue, backoff, conflict handling
│    └── Service worker        app shell only, never API responses
└───────────────┬─────────────────────────────────────────────────────┘
                │  HTTPS · Bearer token · X-Sasa-Project header
┌───────────────▼─────────────────────────────────────────────────────┐
│  Laravel API (/api/v1)                                              │
│    Middleware   CORS → JSON → request id → security headers →       │
│                 Sanctum → throttle → EnsureProjectContext           │
│    Controllers  thin; validation in form requests                   │
│    Domain       services, engines and provider abstractions         │
│    Policies     authorisation, per project, per record              │
│    Resources    field-level confidentiality happens here            │
└───────────────┬─────────────────────────────────────────────────────┘
                │
┌───────────────▼─────────────────────────────────────────────────────┐
│  MySQL 8            the source of truth, 40 tables, one spine        │
│  Queue (database    SLA sweeps, notifications, reminders             │
│    or Redis)                                                         │
│  Scheduler          every 15 min: SLA · daily: commitments, missed   │
│                     engagements, register reviews, backup            │
│  Private storage    attachments and generated reports, signed URLs   │
└─────────────────────────────────────────────────────────────────────┘
```

## Decisions, and why

### Tenant scope comes from the token, never from the request

`EnsureProjectContext` reads `X-Sasa-Project`, looks up the caller's **membership**, and only
then puts a project into `TenantContext`. A client may *ask* for a project; it may not assert
that it belongs to one. A project the user has no membership on returns 404, not 403 — a 403
would confirm the id is real somewhere.

On top of that, `BelongsToTenant` adds a global scope so a forgotten `where('project_id', …)`
in a controller cannot leak another project's rows. Background jobs run with no project
context and therefore see everything, which is what a scheduler needs.

### Confidentiality is enforced in the API resource, not the interface

`GrievanceResource` asks `GrievanceVisibility` whether this viewer may receive identity. If
not, the `complainant`, `precise_location` and `coordinates` keys are **not added to the
array** — they are absent from the response body, not blanked in React. A restricted category
goes further: `scopeVisible` removes the case from the query entirely, and the policy denies
it as *not found*.

Complainant name, phone, email, address and precise location are encrypted at rest, with
HMAC blind-index columns so exact-match search still works without decrypting the table.

### The AI proposes; a person decides

`AiSuggestion` is a separate table. Nothing an AI produces is ever written into
`grievances.category_id` or `grievances.severity` — a human review moves it. This is what
makes classification accuracy measurable, and it is why the case reads
"AI suggested — confirm" until somebody confirms it.

The `AiProvider` and `VoiceProvider` interfaces mean no vendor SDK appears above
`app/Domain/{Ai,Voice}`. The default `NullAiProvider` is a deterministic keyword classifier
that runs with no API key, no network and no cost, so the whole AI pathway is exercised in
development, in tests and in a demo.

### Configuration beats code

Three layers resolve most-specific-first: **project row → organisation row → `config/sasa.php`**.
Categories, severity descriptions, SLA standards, working calendars, priority weights,
notification rules, disaggregation dimensions and report templates are all rows, not
constants. Every change is audited, because configuration is powerful enough to break
comparability between projects.

### The SLA engine holds the mechanism; the client sets the numbers

Nothing is hard-coded to "7 working days". `SlaPolicy` rows are matched most-specific-first
(project + category + severity → category → severity → project → organisation default), and
`WorkingCalendarService` counts against a configured working week, working hours and public
holiday list. Pauses record a reason and are reported — otherwise pausing becomes a way to
make breaches disappear.

### Offline is a real architecture, not a cache

The client writes to IndexedDB through a `LocalStore` **interface**; no component knows which
driver is underneath. Every offline mutation becomes a queue row with a client-generated
UUID, which is unique on the server too — so replaying a partly-succeeded batch is safe.
See [OFFLINE.md](OFFLINE.md).

### Thin controllers, real domain services

A controller validates, authorises, calls one service and returns a resource. The interesting
logic — reference-number allocation under a row lock, priority versioning, planned-vs-actual
classification, the grievance lifecycle, SLA arithmetic, conflict detection — lives in
`app/Domain`, is unit-testable, and is reused by the API, the sync endpoint and the importer
alike. There is no generic workflow engine and no EAV store; custom fields are bounded typed
JSON on the record that owns them.

### Reports read the operational database

There is no second reporting store to reconcile. A report is a saved definition executed
against current records, retained with its parameters, its generator and its timestamp — so
the same definition run twice a week apart legitimately produces different numbers, and both
remain explicable. Every metric has exactly one definition, in `MetricDefinitions`, shown in
the interface and reproduced in every export footer.

## Request lifecycle, end to end

1. `HandleCors` → `ForceJsonResponse` → `TrackRequest` (correlation id) → `SecurityHeaders`
2. `auth:sanctum` resolves the user from the Bearer token
3. `throttle:api` — 240/min per user, 40/min per IP
4. `EnsureProjectContext` resolves and validates the project from the membership
5. The route's `permission:` middleware, or a policy call in the controller
6. Form request validation, with messages written for a person
7. A domain service inside a database transaction
8. Model events write the audit row and allocate the reference number
9. An API resource serialises, applying field-level confidentiality
10. `ApiExceptionRenderer` turns anything thrown into a message somebody can act on

## Where things are

| Concern | File |
| --- | --- |
| Tenant resolution | `app/Domain/Tenancy/TenantContext.php`, `app/Http/Middleware/EnsureProjectContext.php` |
| Reference numbers (`GRV-0001`) | `app/Domain/Tenancy/ReferenceGenerator.php` |
| Priority engine | `app/Domain/Stakeholder/PriorityCalculator.php` |
| Working days and holidays | `app/Domain/Sla/WorkingCalendarService.php` |
| SLA policies and clocks | `app/Domain/Sla/SlaEngine.php` |
| Multi-channel intake | `app/Domain/Grievance/IntakeEngine.php` |
| Case lifecycle | `app/Domain/Grievance/GrievanceService.php` |
| Confidentiality | `app/Domain/Grievance/GrievanceVisibility.php`, `app/Http/Resources/GrievanceResource.php` |
| Sync (server) | `app/Domain/Sync/SyncService.php` |
| Sync (client) | `web/src/lib/offline/syncEngine.ts` |
| Metric definitions | `app/Domain/Dashboard/MetricDefinitions.php` |
| Design tokens | `web/src/app/globals.css` |
