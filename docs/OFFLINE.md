# Offline and synchronisation

The promise: **a field officer never loses a completed form because the connection
disappeared, and is never told a record reached the server when it only reached the device.**

## The contract

```
type                    →  local write  →  queue  →  connection returns  →  push  →  synced
                                   ↓                                          ↓
                          survives reload                          conflict → a person decides
```

## The local database

Application code talks to the `LocalStore` **interface** (`web/src/lib/offline/types.ts`).
It never touches IndexedDB directly, and no React component knows what is underneath.

```ts
interface LocalStore {
  putRecord(record: LocalRecord): Promise<void>;
  enqueue(operation: SyncOperation): Promise<void>;
  claimDueOperations(projectId: number, limit: number): Promise<SyncOperation[]>;
  putAttachment(attachment: LocalAttachment): Promise<void>;
  putDraft(draft: FormDraft): Promise<void>;
  // …
}
```

### Why IndexedDB, and not SQLite/WASM

SQLite compiled to WebAssembly over OPFS is the more familiar-looking answer, and the brief
asked for it "if practical". It is not, here, and the reason is concrete:

* SQLite's OPFS VFS needs `SharedArrayBuffer` for its synchronous access handles, which
  requires **cross-origin isolation** (`COOP: same-origin` + `COEP: require-corp`).
* Cross-origin isolation would break the signed attachment URLs the API issues, any map
  tiles a location picker loads, and every third-party embed a project might add later.
* The fallback VFS that does not need isolation gives up durability guarantees, which is
  precisely what this feature exists to provide.

IndexedDB is a real transactional database with structured indexes and native blob storage,
available in every browser a field officer will actually have, and — unlike `localStorage` —
it is not a 5 MB string bucket. It is the honest choice.

The interface exists so this can change. A SQLite driver, or a native SQLite store inside a
future mobile shell, implements `LocalStore` and drops in; not one component changes.

### What is stored

| Table | Holds |
| --- | --- |
| `records` | Records as the device knows them, synced or not |
| `operations` | The outbound queue: one row per offline mutation |
| `attachments` | Photos and documents, as blobs, until the server confirms them |
| `references` | The bootstrap payload — categories, locations, members, severity levels |
| `drafts` | Half-finished forms, autosaved as the user types |

**Confidential complainant details are never cached on a device.** `SyncService::pull()`
strips `Grievance::SENSITIVE_FIELDS` from any confidential or anonymous case before it is
sent to a client, and marks it `identity_withheld`. The service worker caches the app shell
and static assets only — never an API response — so a shared field device cannot leave one
officer's cases readable by the next person who picks it up. Signing out clears the whole
local database.

## The queue

Each operation carries:

```jsonc
{
  "operation_uuid": "…",     // unique on the server too — this is what makes replay safe
  "entity": "grievance",
  "operation": "create",
  "entity_uuid": "…",        // the record's identity, stable across retries
  "server_id": 42,           // once known
  "payload": { },
  "base_values": { },        // what the device believed the server held
  "base_updated_at": "…",
  "status": "pending",       // pending | uploading | synced | failed | conflict | review
  "retryCount": 0,
  "nextAttemptAt": null
}
```

Ordering is oldest-first, so a stakeholder syncs before the engagement that references it.

### Statuses, and what each means to the user

| Status | Shown as | Meaning |
| --- | --- | --- |
| `pending` | On this device | Written locally, waiting for a connection |
| `uploading` | Syncing | In flight right now |
| `synced` | Synced | The server has it; the queue row is removed |
| `failed` | Sync failed | The batch could not be delivered; retrying with backoff |
| `conflict` | Needs a decision | The server moved too; a person must choose |
| `review` | Needs attention | The server refused it; a person must fix it |

Nothing is ever deleted from the device because a send failed. A rejected operation keeps its
error message so the officer can see *why*, rather than watching work vanish.

### Retry

Exponential backoff: 5s, 10s, 20s, 40s … capped at 10 minutes, up to 8 attempts, after which
it is parked as `failed` and offered as "Retry everything that failed" on `/sync`. The queue
is flushed on `online`, on tab focus, every 30 seconds, and on demand.

## Idempotency — why a replay cannot duplicate

Three independent guards:

1. **`sync_operations.operation_uuid` is unique.** Replaying a batch returns each
   operation's original result instead of applying it again.
2. **`(project_id, client_uuid)` is unique on every entity.** A device that lost the response
   and retried under a *new* operation id still resolves to the same record.
3. **`(project_id, idempotency_key)` is unique on grievances.** A retried webhook or a
   re-imported migration row cannot open a second case.

## Conflicts

Default policy is **last-write-wins per field**. The exception is the fields in
`config('sasa.sync.protected_fields')` — a grievance's status, severity, resolution, closure,
assignee and confidentiality; a commitment's status and verification; a stakeholder's status
and priority. Those are never silently overwritten.

"Did the server move while the device was away?" is answered by comparing the server's
**current** value with the value the device last saw — not by timestamps, which have
one-second resolution and would miss two changes inside the same second:

```php
$serverMovedToo = array_key_exists($field, $base)
    ? ! $this->valuesMatch($serverValue, $base[$field])   // precise
    : $serverChangedWhileOffline;                          // fallback
```

When a protected field moved on both sides, the unprotected fields are still applied and the
protected one becomes a `SyncConflict` showing the local value, the server value, who changed
it, when, and from which device. A user with `sync.resolve_conflicts` chooses. Every step is
audited.

## Attachments

Photographs are compressed on the device before they are queued — a phone camera produces
4–8 MB, which on a rural connection is the difference between a sync that finishes and one
that never does. The local blob is deleted **only** after the server confirms the upload. An
attachment whose parent record has not synced yet waits for the parent's server id.

## The service worker

`web/public/sw.js` is deliberately narrow:

* **Navigations** — network first, cache as the fallback, so the app opens with no signal.
* **Static assets** — cache first; they are content-hashed by the build.
* **Anything under `/api`** — left completely alone.

## Verifying it

`web/e2e/06-offline.spec.ts` is the acceptance test and runs against a production build:

1. sign in online and cache the field data
2. go offline
3. create a stakeholder — the UI says *saved on this device*, not *saved*
4. create a grievance
5. confirm both are in the browser's own IndexedDB queue
6. reload the whole application
7. confirm the work is still queued
8. go back online
9. confirm it syncs
10. confirm the server has exactly one of each

Plus: a failed sync keeps the work and retries; the indicator never claims the server has
something it does not.
