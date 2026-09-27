# Assumptions and decisions

The source specification identifies several points that are ambiguous, inconsistent or
incomplete, and explicitly says they need client confirmation. Development did not stop for
them. Each one below was implemented as a **configurable default**, with the documented
terminology preserved, so the client can set their intended behaviour without a release.

Nothing here was silently corrected.

---

## 1. The stakeholder priority matrix is internally inconsistent

**What the source says.** A five-strategy matrix maps influence, interest and power/authority
to a priority, a strategy and a communication frequency.

**The problem.** Three things: columns B and C carry identical High/Medium/Low inputs but
different strategies and frequencies, so the mapping is not a function of the three inputs
alone; the `impact` field exists in the register but does not appear in the matrix; and
"High" priority is paired with "Monitor and track", which in conventional stakeholder mapping
is the *low*-priority strategy, while "Partner and delegate" sits at Low.

**What SASA does.** A transparent, configurable score:

```
score = w1·influence + w2·interest + w3·power + w4·impact     (High 3, Medium 2, Low 1)
default w1..w4 = 1;  0 disables a dimension
score ≥ 10 → HIGH      7–9 → MEDIUM      ≤ 6 → LOW
```

Weights, level values, thresholds and the strategy/frequency label attached to each band are
all configuration (`/configuration` → Priority). The calculated value is **always** shown
next to the stored one, an override records the previous value, the reason, the person and
the timestamp, and overridden records are countable — a systematic override signals a wrong
weighting rather than a wrong record.

*Where:* `app/Domain/Stakeholder/PriorityCalculator.php`, `config/sasa.php`.

---

## 2. Duplicate detection has no rules

**What the source says.** The register has a "Merged (duplicate)" status.

**The problem.** No detection or merge rules are given.

**What SASA does.** A normalised hash of **name + last nine phone digits + village**, plus a
looser name and phone-tail match, surfaced as a *warning* while typing — never a block. A
village really can hold two people with the same name. Merging marks the duplicate `merged`,
points it at the survivor, relinks engagements, concerns, grievances and commitments, and
archives rather than deletes.

*Where:* `app/Domain/Stakeholder/StakeholderService.php`.

---

## 3. The grievance status list has no "reopened" state

**What the source says.** A status list ending at closure.

**The problem.** Where a complainant does not accept the resolution, without a reopened state
dissatisfaction produces duplicate cases, and the resolution statistics flatter the project.

**What SASA does.** Reopening keeps the **original case ID**, records the reason, increments
`resolution_cycle`, and starts a fresh resolution clock for the new cycle. Both cycles stay
on the record in `grievance_resolution_cycles`, and the first cycle's SLA clock is preserved,
so timeliness cannot be laundered by reopening.

*Where:* `app/Domain/Grievance/GrievanceService::reopen()`.

---

## 4. The two-minute AI voice cap has no defined behaviour at the limit

**What the source says.** Calls are capped at two minutes.

**The problem.** Two minutes is enough for a clear, single-issue concern in a familiar
language. It is not enough for an elderly caller, a complex land dispute, or someone who
needs reassuring before they will speak.

**What SASA does.** The default is the **soft cap**: the agent signals the time, offers to
continue or to arrange a call-back, and every truncated call is flagged for human review.
`cap_seconds`, `warn_at_seconds`, `extension_seconds` and `cap_behaviour`
(`soft` | `hard` | `structured_only`) are all configurable per project.

*Where:* `config/sasa.php` → `voice`, `app/Domain/Voice/VoiceAgentService.php`.

---

## 5. "Seven working days" is meaningless without a calendar

**What the source says.** Acknowledgement within seven working days.

**The problem.** A working day depends on a working week, working hours and a public holiday
list, and those differ by country and by project.

**What SASA does.** Nothing is hard-coded. `sla_policies` rows carry the number, the unit
(`working_days`, `working_hours`, `calendar_days`, `calendar_hours`) and the calendar; the
calendar carries the working week, the working hours and the holidays. Policies match
most-specific-first: project + category + severity → category → severity → project →
organisation default. Tanzanian public holidays are seeded as a starting point.

*Where:* `app/Domain/Sla/{SlaEngine,WorkingCalendarService}.php`, `/configuration` → SLA
standards and Working calendar.

---

## 6. Some disaggregation dimensions are not collected anywhere

**What the source says.** The disaggregation dashboard should cross-tabulate gender, age,
disability, employment, marital status/orphanhood, gender identity or sexual orientation,
migrant status and economic status.

**The problem.** Most of those are not fields on the register or the grievance form. A
dashboard cannot disaggregate on data that is not collected — and some of them cannot be
collected lawfully or safely without a deliberate decision.

**What SASA does.** Every dimension is an **optional, configurable** field, stored as bounded
typed JSON on the stakeholder, the engagement participant and the grievance. Sensitive
dimensions — disability, marital status, migrant status, economic status, gender identity or
sexual orientation — are flagged as sensitive and **disabled by default**. None is ever
mandatory.

Any cross-tab cell below a configurable minimum (**default 5**) is suppressed and shown as
`<5`, to prevent re-identification, and the number of suppressed cells is reported so nobody
mistakes suppression for absence.

*Where:* `/configuration` → Lists and fields, `app/Domain/Dashboard/DashboardService::disaggregation()`.

---

## 7. The permission model is not defined

**What the source says.** Officer functions — Social, HR, HSE, Security, Project Management.

**The problem.** No permission model is given, and the source itself flags this as requiring
confirmation.

**What SASA does.** 48 named permissions across 11 roles, as **data** rather than code, so an
organisation can adjust a role without a release. Roles are assigned **per project**: the same
person can be a grievance officer on one and read-only on another. See [PERMISSIONS.md](PERMISSIONS.md).

---

## 8. Restricted categories

**What the source says.** Categories including Human Rights & Workplace Conduct (SEA/SH,
retaliation) and Ethics & Compliance.

**What SASA does.** A category carries `is_restricted` and a `handling_groups` list. A case in
one becomes invisible outside that group (the policy denies it *as not found*, so its
existence does not leak), is forced to confidential, is excluded from general exports, and is
reported in aggregate only. A restricted case cannot be assigned to somebody outside its
handling group. This is a configuration flag on the category, not hard-coded behaviour.

---

## 9. Acknowledgement where the channel cannot carry one

**The problem.** A suggestion-box note or a fully anonymous submission has no return address.
Leaving the acknowledgement field blank would silently degrade the timeliness statistics.

**What SASA does.** Records `acknowledgement_possible = false` with a reason, and excludes
those cases from the acknowledgement-time metric rather than counting them as late. The case
says so on screen.

---

## 10. Where the AI's authority ends

**What SASA does.** AI may propose a category, subcategory, severity, summary and routing.
Proposals live in `ai_suggestions` with a confidence score and are **never** written into the
case's own columns until a person accepts them. Investigation findings, the resolution
decision, corrective-action approval and closure are human-only, and the interface says so.

---

## 11. Offline, mobile and the AI voice channel were sequenced into later phases

The source roadmap places offline field use, the mobile experience and the AI voice channel
after the MVP. The build brief overrides that sequencing, and all three are implemented here:
a real offline architecture with a local database and a resumable sync queue, a mobile
experience designed for one hand rather than a shrunken desktop, and a complete voice
workflow behind a provider abstraction with a deterministic default that needs no vendor
account.

---

## 12. Smaller decisions taken in passing

| Decision | Why |
| --- | --- |
| Bearer tokens rather than session cookies | Works across separate API and web hosts, and replays cleanly from an offline queue. Admin tokens get a shorter life than field tokens. |
| Complainant identity encrypted at rest, with HMAC blind indexes | Search by phone number still works without decrypting the table. |
| MySQL for the test suite, not SQLite | The schema uses `FULLTEXT` and the dashboards use `JSON_CONTAINS`, `DATE_FORMAT` and `TIMESTAMPDIFF`. Testing on another engine would test another product. |
| Reference numbers allocated under `SELECT … FOR UPDATE` | Two field officers syncing at the same instant must not both receive `GRV-0042`. |
| List summaries ignore the filter they break down | Filtering by status still shows the open/closed split for everything else you filtered to. |
| An offline save does not navigate away | Navigating offline forces a full page reload — exactly the moment a user starts to doubt their work survived. |
| Report row limits are not imposed | A lender asking for the full register should get the full register; generation is queued work, not a request-cycle concern. |
