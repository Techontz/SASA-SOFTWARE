# Permissions and confidentiality

> The source specification names the officer functions but does not define a permission
> model, and says so. What follows is SASA's proposal. It is **data, not code**: an
> organisation can change what a role may do without a release.

## The rules

1. **A role is held per project.** The same person can be a grievance officer on one project
   and read-only on another. There is no global role except system administrator.
2. **The backend decides.** Hiding a button in React is a UX nicety; `EnsurePermission`
   middleware and policies are the actual control. Every permission is re-checked on every
   request.
3. **Handling groups cut across roles.** A restricted category (SEA/SH, retaliation, ethics)
   is visible only to the named handling group — whatever your role is.
4. **A record from another project does not exist.** The policy raises *not found*, never
   *forbidden*: a 403 would confirm the id is real somewhere.

## Roles

| Role | Answers | Notable permissions |
| --- | --- | --- |
| **System administrator** | Runs the platform | Everything, everywhere |
| **Project administrator** | Runs one project | Everything on that project except restricted-category access |
| **Management / executive** | *What is happening?* | Dashboards, disaggregation, reports, exports, escalate, audit |
| **Project management** | *What needs my decision?* | Assign, escalate, resolve, close, verify commitments, disaggregation |
| **Grievance officer** | *What needs attention today?* | The full case lifecycle, **confidential identity**, restricted categories, AI review, conflict resolution |
| **Community relations officer** | *Who am I engaging, and what did they raise?* | The register including override and merge, engagement planning and logging, concerns, commitments, import |
| **HR officer** | Worker cases | Classify, investigate, resolve, escalate; confidential identity; restricted handling |
| **HSE officer** | Environment, health and safety | Classify, investigate, resolve; commitments and verification |
| **Security officer** | Security-related cases | Classify, investigate, escalate |
| **Field officer** | *What do I need to do today?* | Create stakeholders, log engagements, record concerns and commitments, log cases — and the offline experience |
| **Auditor / read-only** | *Can I verify what happened?* | Read everything on the project, audit trail, reports and exports. Writes nothing. |

## Permissions

48 keys across seven groups. `is_sensitive` marks the ones that release protected data or
change what other people can do.

| Group | Keys |
| --- | --- |
| Stakeholders | `view` `create` `update` `archive` `override_priority` `merge` `export` |
| Engagement | `view` `plan` `log` `archive` |
| Concerns | `view` `manage` `escalate` |
| Commitments | `view` `manage` `verify` |
| Grievances | `view` `create` `update` `classify` `assign` `acknowledge` `investigate` `resolve` `close` `reopen` `escalate` **`view_confidential`** **`view_restricted`** `export` |
| Reporting | `dashboard.view` `dashboard.view_disaggregation` `report.generate` `report.manage_definitions` |
| Administration | `project.view` `project.manage` `configuration.view` **`configuration.manage`** `user.view` **`user.manage`** `location.manage` `import.run` **`audit.view`** **`audit.view_sensitive`** `sync.resolve_conflicts` |
| AI | `ai.review` **`ai.configure`** |

*Where:* `app/Domain/Identity/PermissionCatalogue.php`, seeded by `PermissionSeeder`.

## Field-level confidentiality

Three levels, chosen at intake, before anything else is typed:

| Level | What is stored | Who sees the complainant |
| --- | --- | --- |
| **Standard** | Everything | Anyone who can see cases |
| **Confidential** | Everything, encrypted | Only `grievance.view_confidential` **and** the handling group. Every view is a sensitive-view audit event. |
| **Anonymous** | **No identity at all** | Nobody, including administrators. There is nothing to release. |

### How the guarantee is made

```php
// GrievanceResource — the fields are added ONLY for an authorised handler.
if ($canSeeIdentity) {
    $payload['complainant']      = [...];
    $payload['precise_location'] = $this->precise_location;
    $payload['coordinates']      = [...];
}
```

For everyone else those keys do not exist in the response. Not null. Not empty. **Absent** —
so there is nothing in the payload, a proxy log or a cached response to reveal.

An anonymous case is enforced at the model too: `Grievance::saving()` nulls every identity
field and its blind indexes whenever `confidentiality === 'anonymous'`, so identity cannot be
attached later by any path — API, import or sync.

### Restricted categories

A category with `is_restricted` and a `handling_groups` list makes its cases invisible outside
that group. `GrievanceVisibility::scopeVisible()` removes them from the query, so:

* they never appear in a list, a search, an export, a dashboard total or a sync pull;
* opening one directly returns **404**, so its existence does not leak;
* the case is forced to confidential;
* it cannot be assigned to somebody outside the handling group;
* it is reported in aggregate only.

### What is audited

Releasing confidential identity writes `grievance.sensitive_view` with the user, the time, the
IP and the device. `/audit` → *Who read confidential cases* queries only those, because that
is the question an investigation actually asks. An **unauthorised** read writes nothing —
there is nothing to log, because nothing was released.

## Exports

An export by someone without `grievance.view_confidential` has identity columns stripped
before the file is written, and `export_logs.identity_stripped` records that it happened.
Every export is logged with its filters and row count.
