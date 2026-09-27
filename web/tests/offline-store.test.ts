import { beforeEach, describe, expect, it } from "vitest";
import { DexieLocalStore } from "@/lib/offline/dexieStore";
import type { LocalAttachment, LocalRecord, SyncOperation } from "@/lib/offline/types";

/**
 * The local store is the promise SASA makes to a field officer: what you typed
 * is on this device, it survives a reload, and it does not leave the queue
 * until the server has it.
 */
describe("the local store", () => {
  let store: DexieLocalStore;

  const operation = (overrides: Partial<SyncOperation> = {}): SyncOperation => ({
    operationUuid: crypto.randomUUID(),
    entity: "stakeholder",
    operation: "create",
    entityUuid: crypto.randomUUID(),
    payload: { name: "Recorded with no signal" },
    projectId: 1,
    deviceId: "test-device",
    createdAt: new Date().toISOString(),
    updatedAt: new Date().toISOString(),
    status: "pending",
    retryCount: 0,
    nextAttemptAt: null,
    ...overrides,
  });

  const record = (overrides: Partial<LocalRecord> = {}): LocalRecord => ({
    uuid: crypto.randomUUID(),
    entity: "stakeholder",
    projectId: 1,
    serverId: null,
    reference: null,
    data: { name: "Recorded with no signal" },
    syncStatus: "pending",
    capturedAt: new Date().toISOString(),
    updatedAt: new Date().toISOString(),
    syncedAt: null,
    isLocalOnly: true,
    ...overrides,
  });

  beforeEach(async () => {
    store = new DexieLocalStore();
    await store.ready();
    await store.clearProject(1);
    await store.clearProject(2);
  });

  it("keeps a record written offline and gives it back", async () => {
    const entry = record();
    await store.putRecord(entry);

    const found = await store.getRecord("stakeholder", entry.uuid);

    expect(found?.data.name).toBe("Recorded with no signal");
    expect(found?.isLocalOnly).toBe(true);
  });

  it("lists what is on the device for a project, and only that project", async () => {
    await store.putRecord(record());
    await store.putRecord(record({ projectId: 2 }));

    const mine = await store.listRecords("stakeholder", 1);

    expect(mine).toHaveLength(1);
  });

  it("queues an operation and reports it as pending", async () => {
    await store.enqueue(operation());

    const summary = await store.summary(1);

    expect(summary.pending).toBe(1);
    expect(summary.oldestPendingAt).not.toBeNull();
  });

  it("hands out operations oldest first, so a stakeholder syncs before its engagement", async () => {
    const older = operation({ createdAt: "2026-01-01T08:00:00.000Z" });
    const newer = operation({ createdAt: "2026-01-01T09:00:00.000Z", entity: "engagement" });

    await store.enqueue(newer);
    await store.enqueue(older);

    const due = await store.claimDueOperations(1, 10);

    expect(due[0].operationUuid).toBe(older.operationUuid);
  });

  it("does not hand out an operation that is backing off", async () => {
    await store.enqueue(
      operation({ status: "pending", nextAttemptAt: new Date(Date.now() + 60_000).toISOString() }),
    );

    expect(await store.claimDueOperations(1, 10)).toHaveLength(0);
  });

  it("does not hand out an operation waiting on a human decision", async () => {
    await store.enqueue(operation({ status: "conflict" }));
    await store.enqueue(operation({ status: "review" }));

    expect(await store.claimDueOperations(1, 10)).toHaveLength(0);

    const summary = await store.summary(1);
    expect(summary.conflict).toBe(2);
  });

  it("counts a failed operation as still on the device, never as lost", async () => {
    await store.enqueue(operation({ status: "failed", retryCount: 3, lastError: "Server unreachable" }));

    const summary = await store.summary(1);
    expect(summary.failed).toBe(1);

    // And it is offered again once its backoff has passed.
    expect(await store.claimDueOperations(1, 10)).toHaveLength(1);
  });

  it("keeps a photo until it has been uploaded", async () => {
    const attachment: LocalAttachment = {
      uuid: crypto.randomUUID(),
      projectId: 1,
      attachableEntity: "grievance",
      attachableUuid: "grievance-uuid",
      attachableServerId: null,
      kind: "photo",
      caption: null,
      fileName: "evidence.jpg",
      mimeType: "image/jpeg",
      sizeBytes: 2048,
      blob: new Blob(["x"], { type: "image/jpeg" }),
      capturedAt: new Date().toISOString(),
      status: "pending",
      retryCount: 0,
    };

    await store.putAttachment(attachment);

    expect(await store.listPendingAttachments(1, 10)).toHaveLength(1);
    expect((await store.summary(1)).attachmentsPending).toBe(1);

    await store.updateAttachment(attachment.uuid, { status: "synced" });
    expect(await store.listPendingAttachments(1, 10)).toHaveLength(0);
  });

  it("autosaves and restores a half-finished form", async () => {
    await store.putDraft({
      key: "grievance:new",
      projectId: 1,
      entity: "grievance",
      values: { description: "Half-typed when the signal went" },
      updatedAt: new Date().toISOString(),
    });

    const draft = await store.getDraft("grievance:new", 1);
    expect(draft?.values.description).toBe("Half-typed when the signal went");

    await store.removeDraft("grievance:new", 1);
    expect(await store.getDraft("grievance:new", 1)).toBeUndefined();
  });

  it("caches the reference data a device needs to work with no signal", async () => {
    await store.putReference({
      key: "bootstrap",
      projectId: 1,
      payload: { categories: [{ id: 1, name: "Environmental, Health & Safety" }] },
      fetchedAt: new Date().toISOString(),
    });

    const cached = await store.getReference<{ categories: Array<{ name: string }> }>("bootstrap", 1);
    expect(cached?.categories[0].name).toBe("Environmental, Health & Safety");
  });

  it("clears one project without touching another", async () => {
    await store.putRecord(record());
    await store.putRecord(record({ projectId: 2 }));

    await store.clearProject(1);

    expect(await store.listRecords("stakeholder", 1)).toHaveLength(0);
    expect(await store.listRecords("stakeholder", 2)).toHaveLength(1);
  });
});
