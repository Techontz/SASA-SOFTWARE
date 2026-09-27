# API

Base URL: `/api/v1`. Everything is JSON.

## Conventions

| | |
| --- | --- |
| **Auth** | `Authorization: Bearer <token>` from `POST /auth/login` |
| **Project** | `X-Sasa-Project: <id>` — validated against your membership, never trusted as an assertion |
| **Device** | `X-Sasa-Device-Id: <id>` — optional; appears on audit rows and sync operations |
| **Correlation** | Every response carries `X-Sasa-Request-Id`, which also appears in the logs and on the audit row |
| **Envelope** | Single records: `{ "data": … }`. Lists: Laravel pagination plus `meta.summary`. |
| **Errors** | `{ "error": "code", "message": "something a person can act on", "request_id": "…" }`, plus `errors` on 422 |
| **Rate limits** | 240/min per user, 40/min per IP; sign-in 30/min per IP and 8/min per email address |

### Status codes

| Code | Meaning here |
| --- | --- |
| 200 / 201 / 204 | Fine |
| 400 `project_required` | No project chosen |
| 401 `unauthenticated` | No token, or it has expired |
| 403 `forbidden` | Your role on **this project** does not include it |
| 404 `not_found` | Does not exist — **or** belongs to another project, **or** is a restricted case you may not see. Deliberately indistinguishable. |
| 422 `validation_failed` | `errors` is keyed by field |
| 422 (domain code) | A business rule refused it, e.g. `not_resolved`, `already_escalated`, `not_fulfilled` |
| 423 `account_locked` | Too many failed sign-ins |
| 429 `rate_limited` | Slow down |
| 503 | `/health` reporting a failed dependency |

---

## Public

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/health` | Database, cache, storage and queue. 503 when any is failing. |
| POST | `/auth/login` | `{ email, password, device_name? }` |
| POST | `/auth/forgot-password` | Always answers the same, whether or not the address exists |
| POST | `/auth/reset-password` | |
| GET | `/attachments/{attachment}/download` | Signed URL only; expires; audited |
| POST | `/voice/{projectCode}/webhook` | Telephony webhook, HMAC-verified |
| GET | `/voice/{projectCode}/script` | The prompts and cap settings for a provider script |

## Session (no project needed)

`GET /auth/me` · `POST /auth/logout` · `PATCH /auth/profile` · `POST /auth/change-password`
`GET /projects` · `POST /projects` (system administrator)
`GET /notifications` · `GET /notifications/unread-count` · `POST /notifications/{id}/read` · `POST /notifications/read-all`

---

## Project-scoped

Everything below requires `X-Sasa-Project` and the listed permission.

### Module 1 — the register

| Method | Path | Permission |
| --- | --- | --- |
| GET | `/stakeholders` | `stakeholder.view` |
| POST | `/stakeholders` | `stakeholder.create` |
| GET | `/stakeholders/{id}` | `stakeholder.view` |
| PATCH | `/stakeholders/{id}` | `stakeholder.update` |
| DELETE | `/stakeholders/{id}` | `stakeholder.archive` — archives, never deletes |
| POST | `/stakeholders/{id}/restore` | `stakeholder.archive` |
| POST | `/stakeholders/{id}/priority` | `stakeholder.override_priority` — requires a reason |
| POST | `/stakeholders/{id}/recalculate-priority` | `stakeholder.update` |
| POST | `/stakeholders/{id}/merge` | `stakeholder.merge` |
| GET | `/stakeholders/{id}/timeline` | `stakeholder.view` — everything on the chain |
| GET | `/stakeholders/duplicates` | `stakeholder.view` |
| GET | `/stakeholders/priority-model` | `stakeholder.view` — the live weights and thresholds |
| GET | `/stakeholders/export` | `stakeholder.export` |

**Filters:** `search`, `type`, `status`, `priority`, `owner_id`, `is_vulnerable`,
`is_indigenous_or_minority`, `consent_status`, `location_id` (cascades to descendants),
`region`/`district`/`ward`/`village`, `review=due`, `priority_overridden`,
`created_from`/`created_to`, `sort`, `page`, `per_page`, `include_archived`.

### Module 2 — engagement

| Method | Path | Permission |
| --- | --- | --- |
| GET/POST | `/engagement-plans` | `engagement.view` / `engagement.plan` |
| GET | `/engagement-plans/calendar` | `engagement.view` |
| GET/PATCH/DELETE | `/engagement-plans/{id}` | `engagement.view` / `engagement.plan` / `engagement.archive` |
| POST | `/engagement-plans/{id}/status` | `engagement.plan` — reschedule, postpone, cancel, miss |
| GET/POST | `/engagements` | `engagement.view` / `engagement.log` |
| GET/PATCH/DELETE | `/engagements/{id}` | as above |
| GET | `/engagements/export` | `engagement.view` |
| GET/POST | `/concerns` | `concern.view` / `concern.manage` |
| GET | `/concerns/{id}/escalation-draft` | `concern.escalate` — the pre-filled case |
| POST | `/concerns/{id}/escalate` | `concern.escalate` + `grievance.create` |
| GET/POST | `/commitments` | `commitment.view` / `commitment.manage` |
| POST | `/commitments/{id}/status` | `commitment.manage` |
| POST | `/commitments/{id}/verify` | `commitment.verify` — only after it is fulfilled |
| GET | `/commitments/export` | `commitment.view` |

`POST /engagements` accepts nested `concerns[]` and `commitments[]`. They become their own
records with their own reference numbers, so nothing is typed twice.

### Module 3 — grievances

| Method | Path | Permission |
| --- | --- | --- |
| GET | `/grievances` | `grievance.view` — restricted cases are removed from the query |
| POST | `/grievances` | `grievance.create` |
| GET | `/grievances/{id}` | `grievance.view` (+ handling group if restricted) |
| PATCH | `/grievances/{id}` | `grievance.update`; identity fields also need `grievance.view_confidential` |
| POST | `/grievances/{id}/classify` | `grievance.classify` |
| POST | `/grievances/{id}/assign` | `grievance.assign` |
| POST | `/grievances/{id}/acknowledge` | `grievance.acknowledge` |
| POST | `/grievances/{id}/investigation/start` | `grievance.investigate` |
| POST | `/grievances/{id}/investigation` | `grievance.investigate` |
| POST | `/grievances/{id}/resolve` | `grievance.resolve` |
| POST | `/grievances/{id}/complainant-response` | `grievance.resolve` |
| POST | `/grievances/{id}/close` | `grievance.close` |
| POST | `/grievances/{id}/reopen` | `grievance.reopen` — keeps the case ID, opens a new cycle |
| POST | `/grievances/{id}/escalate` | `grievance.escalate` |
| POST | `/grievances/{id}/withdraw` | `grievance.close` |
| POST | `/grievances/{id}/follow-ups` | `grievance.investigate` |
| POST | `/grievances/{id}/communications` | `grievance.acknowledge` |
| GET | `/grievances/export` | `grievance.export` — identity stripped without `view_confidential` |

**Intake accepts** `idempotency_key`, so a retried webhook or a replayed offline batch cannot
open a second case.

**Field-level confidentiality.** For a viewer outside the handling group, `complainant`,
`precise_location`, `coordinates` and `stakeholder` are **absent from the response** — not
null, not empty. `identity_visible: false` and `identity_withheld_reason` explain why.

### Module 4 — dashboards and reporting

| Method | Path | Permission |
| --- | --- | --- |
| GET | `/dashboard/landing` | `dashboard.view` — role-aware |
| GET | `/dashboard/executive` | `dashboard.view` |
| GET | `/dashboard/timeliness` | `dashboard.view` |
| GET | `/dashboard/severity` | `dashboard.view` |
| GET | `/dashboard/engagement` | `dashboard.view` |
| GET | `/dashboard/disaggregation` | `dashboard.view_disaggregation` |
| GET | `/dashboard/definitions` | `dashboard.view` |
| GET | `/reports/templates` | `report.generate` |
| GET/POST | `/reports` | `report.generate` |
| GET | `/reports/{id}/download` | `report.generate` |
| GET/POST | `/reports/definitions` | `report.generate` / `report.manage_definitions` |

All dashboards take `from`, `to`, `location_id`, `category_id`, `severity`, `channel`,
`owner_id`, `project_phase`. Every KPI returns its `key`, `label`, `definition`, `unit`,
`value`, `delta_percent`, `context` and a `drill` path.

### Offline sync

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/sync/bootstrap` | Categories, locations, members, severity levels, channels, configuration |
| POST | `/sync/push` | A batch of operations; idempotent by `operation_uuid` |
| GET | `/sync/pull` | Everything changed since a cursor; confidential identity stripped |
| GET | `/sync/status` | What the server thinks this device's queue looks like |
| GET | `/sync/conflicts` | Open conflicts |
| POST | `/sync/conflicts/{id}/resolve` | `sync.resolve_conflicts` — `keep_local`, `keep_server`, `merged` |

Push results are per operation: `applied`, `duplicate`, `conflict` or `rejected`, each with
the reason. See [OFFLINE.md](OFFLINE.md).

### Everything else

`GET /search` — grouped across stakeholders, grievances, engagements and commitments; a
confidential case never appears for a user outside its handling group, and an anonymous
complainant is unsearchable by definition.

`/saved-views` · `/locations` · `/attachments` · `/imports` · `/configuration` · `/members` ·
`/audit` · `/ai` — see the route table with `php artisan route:list --path=api/v1`.
