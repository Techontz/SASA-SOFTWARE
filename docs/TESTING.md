# Testing

| Suite | Count | Runs against |
| --- | --- | --- |
| Backend (PHPUnit) | **176 tests, 717 assertions** | MySQL `sasa_testing`, refreshed per test |
| Frontend (Vitest) | **65 tests** | jsdom + `fake-indexeddb` |
| End to end (Playwright) | **77 tests** across desktop and mobile | A real API, a real database, a production build |

## Backend

```bash
cd backend
vendor/bin/phpunit                       # everything
vendor/bin/phpunit --filter=OfflineSync  # one file
vendor/bin/phpunit --testsuite=Unit
```

Tests run against **MySQL, not SQLite in memory**. The schema uses `FULLTEXT` indexes and the
dashboards use `JSON_CONTAINS`, `DATE_FORMAT` and `TIMESTAMPDIFF`; testing on a different
engine would test a different product.

`tests/TestCase.php` seeds a minimal but realistic tenant — an organisation, a project, the
role templates, a location hierarchy, a working calendar, the SLA defaults, one open category
and one restricted category — and gives you `makeUser($roleKey, $handlingGroups)` and
`asUser($user)`.

| File | Covers |
| --- | --- |
| `AuthenticationTest` | Sign-in, indistinguishable failures, lockout, suspension, session revocation, health |
| `TenantIsolationTest` | Cross-project 404s, ignored client-supplied ids, per-project roles, revoked membership |
| `StakeholderRegisterTest` | References, priority scoring, override with reason and audit, duplicates, merge, archive, filters |
| `EngagementChainTest` | Plan → log → planned-vs-actual, missed sweep, nested concerns and commitments, escalation without retyping, verification order |
| `GrievanceLifecycleTest` | Every channel, idempotency, the full lifecycle, reopen on the same ID, assignment history, guard rails |
| `ConfidentialityTest` | Absent fields, anonymous storing nothing, restricted invisibility, sensitive-view audit, export stripping, encryption at rest |
| `SlaEngineTest` | Working-day arithmetic, holidays, policy specificity, breach, escalation, pause and resume, reminders |
| `OfflineSyncTest` | Idempotent replay, per-field last-write-wins, protected-field conflicts, resolution, bootstrap, cross-tenant refusal |
| `DashboardAndReportingTest` | Live KPIs, role-aware landing, cell suppression, all four formats, filtered reports, retention |
| `ImportExportTest` | Template, map, validate, preview, commit; partial failures; audit logging |
| `AuditTrailTest` | Append-only enforcement, before/after, secrets excluded, sensitive-view queries |
| `ConfigurationTest` | Layer resolution, live weight changes, retired categories, SLA per severity, calendars |
| `AiVoiceTest` | Suggestions stored apart, human review, consent refusal creating no case, soft and hard caps, webhook signatures |
| `PriorityCalculatorTest`, `WorkingCalendarTest` | The two engines, in isolation |

## Frontend

```bash
cd web
npm test              # vitest run
npm run test:watch
npm run typecheck     # tsc --noEmit, strict
```

`tests/offline-store.test.ts` runs the real Dexie store against `fake-indexeddb` and asserts
the promise the product makes: a record written offline comes back, the queue drains
oldest-first, a backing-off operation is not re-offered, a conflict waits for a person, a
failed operation is still on the device, and clearing one project leaves another alone.

## End to end

The offline test needs a **production build**, because the service worker is only registered
in production — and the service worker is what lets the app open with no signal.

```bash
# terminal 1
cd backend && php artisan serve --port=8010

# terminal 2
# `npm run dev` and `npm start` share port 3010. If a dev server is already
# holding it, `next start` fails to bind and the whole suite silently runs
# against development — where the service worker never registers, so the
# offline test fails and nothing else explains why.
pkill -f 'next dev'
cd web && rm -rf .next && npm run build && npx next start --port 3010

# terminal 3
cd web && npx playwright test                    # both viewports
npx playwright test --project=mobile             # phone only
npx playwright test e2e/06-offline.spec.ts       # the offline acceptance test
npx playwright show-report
```

| Spec | Acceptance criterion |
| --- | --- |
| `01-authentication` | Readable failures; the landing question changes by role |
| `02-stakeholder` | A stakeholder gets a unique ID and a scored priority; an override keeps the calculated value and the reason; a field officer cannot override; an auditor cannot write |
| `03-engagement-chain` | Plan → log links and calculates planned-vs-actual; a concern and a commitment become records; an unplanned engagement is flagged; a concern escalates without being retyped |
| `04-grievance-lifecycle` | Intake → classify → assign → acknowledge → investigate → resolve → confirm → close → **reopen on the same ID** |
| `05-confidentiality` | Identity absent from the payload; restricted cases invisible; anonymous stores nothing; the intake form removes the fields |
| `06-offline` | **The offline acceptance test** — see below |
| `07-dashboards-and-reports` | Live KPIs with definitions; drill-through; permission gating; report generation and retention |
| `08-mobile-field` | Bottom navigation; the central action; no sideways scrolling on any screen; cards instead of a squeezed table; thumb-sized controls |

### The offline acceptance test

`e2e/06-offline.spec.ts` runs the mandatory sequence:

1. sign in online and cache the field data
2. go offline
3. create a stakeholder — the UI says **saved on this device**, not *saved*
4. create a grievance
5. confirm both are in the browser's own IndexedDB queue
6. **reload the whole application**
7. confirm the work is still queued
8. go back online
9. confirm it synchronises
10. confirm the server has exactly one of each — no duplicates

Plus: a failed sync keeps the work and retries rather than dropping it; and the indicator
never claims the server has something it does not.

## Colour

Two checks, and neither of them is anyone's eye.

`web/tools/contrast.mjs` reads `globals.css`, resolves **both** themes, and checks every
text/background pairing the app actually uses against WCAG AA. It exits non-zero on a
failure, so it can run in CI beside the unit tests. Run it after touching any colour.

```bash
cd web && node tools/contrast.mjs
```

The chart palette is held to the data-visualisation gates separately — colour-blind
separation, chroma floor, lightness band — and the invariants a stylesheet cannot enforce
are covered by `tests/chart-theme.test.tsx`. See [DESIGN.md](DESIGN.md).

## Visual QA

`web/tools/qa.mjs` signs in and walks every screen, saving a screenshot of each and
reporting three things: any page that scrolls sideways, any console error, and the measured
contrast of **what actually rendered** — every visible text run against the background it is
really painted on. That last check is what catches a token that flipped in one place but not
in the element sitting on top of it, which a palette check cannot see.

It takes an account, a viewport and a theme. All four combinations should be clean:

```bash
node tools/qa.mjs project.admin@sasa.test desktop light
node tools/qa.mjs project.admin@sasa.test desktop dark
node tools/qa.mjs field@sasa.test mobile light
node tools/qa.mjs field@sasa.test mobile dark
```

Screenshots land in `/tmp/sasa-shots/<theme>-<viewport>/`.

Next's own dev-tools overlay is hidden during the sweep, so a review of SASA's interface is
a review of SASA's interface.

## What is not covered

* **Load and soak testing.** The indexes and query shapes are built for it, but no load test
  has been run — do that against production-like data volumes before go-live.
* **A real telephony provider.** The voice workflow is fully tested through the simulator
  provider; connecting Twilio or Africa's Talking needs its own integration test.
* **Penetration testing.** The controls are in place and unit-tested; an independent test is
  a separate exercise.
