# Database

MySQL 8 is the source of truth. 40 tables, deliberately compact: no generic workflow engine,
no EAV store, no separate analytics warehouse. Custom fields are bounded typed JSON on the
record that owns them; analytics run on the operational tables.

Every operational table carries `organisation_id`, `project_id`, `created_at`/`created_by`
and `updated_at`/`updated_by`.

## The spine

```
Organisation 1─n Project 1─n ProjectMembership n─1 Role n─n Permission
                     │
     ┌───────────────┼────────────────────────────┬──────────────────┐
     ▼               ▼                            ▼                  ▼
 Stakeholder    EngagementPlan 1─0..n Engagement  Grievance      Configuration
     │ 1─n StakeholderContact          │  1─n EngagementParticipant │
     │ 1─n StakeholderLocation         │  1─n Concern ──0..1──▶ Grievance
     │ 1─n StakeholderAssessment       │  1─n Commitment n──n Stakeholder
     │                                 │
     └──────────────── links ──────────┴─────────▶ Grievance 1─n GrievanceFollowUp
                                                            1─n Assignment  (history)
                                                            1─n GrievanceResolutionCycle
                                                            1─n GrievanceEscalation
                                                            1─n Communication
                                                            1─n SlaClock (polymorphic)
```

## Tables

### Identity and tenancy

| Table | Notes |
| --- | --- |
| `organisations` | The hard tenant boundary. Branding, default timezone, status. |
| `projects` | `(organisation_id, code)` unique. Fiscal year start for the reporting calendar. |
| `users` | Encrypted MFA secret and recovery codes; failed-attempt counter and lockout. |
| `roles` | `organisation_id` NULL means a platform-wide template. `escalation_rank` orders the escalation ladder. |
| `permissions` | 48 rows; `is_sensitive` marks the ones that release protected data. |
| `permission_role` | The grant table. |
| `project_memberships` | `(project_id, user_id)` unique. Carries `handling_groups`, which gate restricted categories regardless of role. |
| `sequences` | Per-project reference allocator, read `FOR UPDATE`. |
| `configurations` | `(organisation_id, project_id, key)` unique. `project_id` NULL is the organisation default. |

### Places and time

| Table | Notes |
| --- | --- |
| `locations` | Self-referencing: country → region → district → ward → village. `path` is materialised so list views need no joins. `population_profile` feeds the uptake-equity view. |
| `working_calendars` | Working week, working hours, timezone. Per project, or shared across the organisation. |
| `holidays` | Public holidays, optionally recurring annually. |

### Module 1 — the register

| Table | Notes |
| --- | --- |
| `stakeholders` | `(project_id, reference)` and `(project_id, client_uuid)` unique. Denormalised region/district/ward/village. `duplicate_hash` for duplicate detection. `demographics` and `custom_fields` are bounded JSON. FULLTEXT on name, alias, organisation and concerns. |
| `stakeholder_contacts` | Additional people at an organisation or household. |
| `stakeholder_location` | Many-to-many with a `relationship` (resides / operates / affected). |
| `stakeholder_assessments` | **Versioned.** Every recalculation or override writes a row with the weights in force at the time; `is_current` flags the live one. |

### Module 2 — engagement

| Table | Notes |
| --- | --- |
| `engagement_plans` | `PLAN-0001`. Target date and optional window; recurrence expands into individually trackable rows. |
| `engagements` | `ENG-0001`. `planned_vs_actual` and `variance_days` are stored, so dashboards do not recompute across the table. FULLTEXT on topic, discussion points and outcomes. |
| `engagement_stakeholder` | Which register entries were present. |
| `engagement_participants` | The attendance sheet, with optional per-person demographics. |
| `concerns` | `CON-0001`. Links to the engagement, the stakeholder and — once escalated — the grievance. |
| `commitments` | `COM-0001`. Owner, due date, risk, evidence and a separate verification status. `reminders_sent` prevents duplicate reminders. |
| `commitment_stakeholder` | Who the promise was made to. |

### Module 3 — grievances

| Table | Notes |
| --- | --- |
| `grievance_categories` | Self-referencing for subcategories. `is_restricted` + `handling_groups`; `retired_at` hides it from new cases and keeps it on old ones. |
| `grievances` | `GRV-0001`. Complainant name, phone, email, address and precise location are **encrypted**; `complainant_name_hash` and `complainant_phone_hash` are HMAC blind indexes for exact-match search. `(project_id, idempotency_key)` unique. Denormalised SLA state for list views. |
| `grievance_resolution_cycles` | One row per cycle. Reopening adds a row and keeps the case ID. |
| `grievance_follow_ups` | The case history: notes, investigation steps, contacts, site visits, decisions. |
| `assignments` | Assignment **history**, not a single field. `is_current` flags the live one. |
| `grievance_escalations` | From level, to level, trigger, reason, who to. |
| `communications` | Every message to or from the complainant, with the recipient encrypted. |

### SLA

| Table | Notes |
| --- | --- |
| `sla_policies` | Clock, unit, target, and optional category/severity/country. `specificity` is precomputed so the most specific match wins with one `ORDER BY`. |
| `sla_clocks` | Polymorphic on the subject. One row per (subject, clock, cycle). `pause_history` records every pause with its reason. |

### Platform

| Table | Notes |
| --- | --- |
| `attachments` | Polymorphic. `path` and `disk` are hidden from the API — downloads go through a signed route. `scan_status` is `skipped` when no scanner is configured, never falsely `clean`. |
| `audit_logs` | **Append-only** — `save()` on an existing row and `delete()` both throw. `user_name` is denormalised so the trail survives user archival. |
| `notifications` | Laravel's table. |
| `notification_rules` | Per event key: recipients, channels, conditions and template text. |
| `saved_views` | Named filter combinations, private / role / project. |
| `import_jobs` | The wizard's state, including `last_committed_row` so a commit resumes rather than restarts. |
| `export_logs` | Who exported what, with which filters, how many rows, and whether identity was stripped. |
| `report_definitions` / `reports` | A definition, and each generated artefact with its parameters and a snapshot of its numbers. |
| `ai_suggestions` | Polymorphic. Proposals live here, **never** in the subject's own columns, until a person accepts them. |
| `voice_calls` | Caller number encrypted; consent, duration, truncation, transcript, and the case it produced (or did not). |
| `sync_operations` | The server-side ledger. `operation_uuid` unique — this is what makes a replayed batch safe. |
| `sync_conflicts` | Field-level diffs awaiting a human decision. |
| `personal_access_tokens` | Sanctum. |

## Indexing

Composite indexes follow the queries the product actually runs:

```sql
stakeholders    (project_id, status, priority), (project_id, type),
                (project_id, review_date), duplicate_hash, FULLTEXT(name, alias, …)
engagements     (project_id, held_at), (project_id, planned_vs_actual), FULLTEXT(topic, …)
commitments     (project_id, status, due_date), (project_id, risk_level),
                (project_id, verification_status)
grievances      (project_id, status, severity), (project_id, received_at),
                (project_id, assigned_to_id), (project_id, resolution_sla_state),
                complainant_phone_hash, FULLTEXT(title, description, …)
sla_clocks      (project_id, state, due_at), (subject_type, subject_id, clock, cycle) UNIQUE
audit_logs      (project_id, created_at), (entity_type, entity_id), (action, created_at),
                (project_id, is_sensitive_view)
```

## Migration safety

Nothing is hard-deleted. "Delete" sets `archived_at` and writes an audit event; foreign keys
use `nullOnDelete()` or `restrictOnDelete()` rather than cascading through history. Deferred
foreign keys (`concerns.grievance_id`, `grievances.voice_call_id`) are added in a later
migration so the table order stays sane. For a schema change on live data, use the standard
safe sequence: add nullable → backfill → add the constraint → drop the old column in a later
release.
