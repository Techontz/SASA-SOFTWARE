import Dexie, { type Table } from "dexie";
import type {
  CachedReference,
  FormDraft,
  LocalAttachment,
  LocalEntity,
  LocalRecord,
  LocalStore,
  QueueSummary,
  SyncOperation,
} from "./types";

/**
 * The IndexedDB driver behind the LocalStore interface.
 *
 * Why IndexedDB rather than SQLite/WASM: SQLite over OPFS needs
 * cross-origin isolation (COOP/COEP) for its synchronous access handles, which
 * would break the signed attachment URLs and any embedded map tiles this
 * product needs. IndexedDB is a real transactional database with structured
 * indexes and blob storage, it is available in every browser a field officer
 * will have, and — unlike localStorage — it is not a 5 MB string bucket.
 *
 * Everything above this file talks to `LocalStore`, so a SQLite driver can be
 * added later without touching a single component.
 */
class SasaDatabase extends Dexie {
  records!: Table<LocalRecord, string>;
  operations!: Table<SyncOperation, string>;
  attachments!: Table<LocalAttachment, string>;
  references!: Table<CachedReference, [string, number]>;
  drafts!: Table<FormDraft, [string, number]>;

  constructor() {
    super("sasa");

    this.version(1).stores({
      records: "uuid, entity, projectId, serverId, syncStatus, [entity+projectId], [entity+serverId], updatedAt",
      operations: "operationUuid, projectId, entity, status, nextAttemptAt, [projectId+status], createdAt",
      attachments: "uuid, projectId, attachableUuid, status, [projectId+status]",
      references: "[key+projectId], projectId",
      drafts: "[key+projectId], projectId, updatedAt",
    });
  }
}

export class DexieLocalStore implements LocalStore {
  private db = new SasaDatabase();

  async ready(): Promise<void> {
    if (!this.db.isOpen()) {
      await this.db.open();
    }
  }

  // ------------------------------------------------------------- records

  async putRecord(record: LocalRecord): Promise<void> {
    await this.db.records.put(record);
  }

  async putRecords(records: LocalRecord[]): Promise<void> {
    if (records.length === 0) return;
    await this.db.records.bulkPut(records);
  }

  async getRecord(entity: LocalEntity, uuid: string): Promise<LocalRecord | undefined> {
    const record = await this.db.records.get(uuid);
    return record?.entity === entity ? record : undefined;
  }

  async getRecordByServerId(entity: LocalEntity, serverId: number): Promise<LocalRecord | undefined> {
    return this.db.records.where("[entity+serverId]").equals([entity, serverId]).first();
  }

  async listRecords(entity: LocalEntity, projectId: number): Promise<LocalRecord[]> {
    return this.db.records.where("[entity+projectId]").equals([entity, projectId]).toArray();
  }

  async deleteRecord(entity: LocalEntity, uuid: string): Promise<void> {
    await this.db.records.delete(uuid);
  }

  // --------------------------------------------------------------- queue

  async enqueue(operation: SyncOperation): Promise<void> {
    await this.db.operations.put(operation);
  }

  async updateOperation(operationUuid: string, patch: Partial<SyncOperation>): Promise<void> {
    await this.db.operations.update(operationUuid, {
      ...patch,
      updatedAt: new Date().toISOString(),
    });
  }

  /**
   * Operations that are due to be attempted, oldest first, so the queue drains
   * in the order the officer created things — a stakeholder before the
   * engagement that references it.
   */
  async claimDueOperations(projectId: number, limit: number): Promise<SyncOperation[]> {
    const now = Date.now();

    const due = await this.db.operations
      .where("projectId")
      .equals(projectId)
      .filter((operation) => {
        if (operation.status === "synced" || operation.status === "conflict" || operation.status === "review") {
          return false;
        }
        if (!operation.nextAttemptAt) return true;
        return new Date(operation.nextAttemptAt).getTime() <= now;
      })
      .toArray();

    return due
      .sort((a, b) => a.createdAt.localeCompare(b.createdAt))
      .slice(0, limit);
  }

  async listOperations(projectId: number): Promise<SyncOperation[]> {
    const operations = await this.db.operations.where("projectId").equals(projectId).toArray();
    return operations.sort((a, b) => b.createdAt.localeCompare(a.createdAt));
  }

  async removeOperation(operationUuid: string): Promise<void> {
    await this.db.operations.delete(operationUuid);
  }

  async summary(projectId: number): Promise<QueueSummary> {
    const [operations, attachments] = await Promise.all([
      this.db.operations.where("projectId").equals(projectId).toArray(),
      this.db.attachments.where("projectId").equals(projectId).toArray(),
    ]);

    const byStatus = (status: string) => operations.filter((o) => o.status === status).length;

    const pendingOperations = operations
      .filter((o) => o.status === "pending" || o.status === "failed")
      .sort((a, b) => a.createdAt.localeCompare(b.createdAt));

    return {
      pending: byStatus("pending"),
      uploading: byStatus("uploading"),
      failed: byStatus("failed"),
      conflict: byStatus("conflict") + byStatus("review"),
      attachmentsPending: attachments.filter((a) => a.status !== "synced").length,
      oldestPendingAt: pendingOperations[0]?.createdAt ?? null,
    };
  }

  // --------------------------------------------------------- attachments

  async putAttachment(attachment: LocalAttachment): Promise<void> {
    await this.db.attachments.put(attachment);
  }

  async listPendingAttachments(projectId: number, limit: number): Promise<LocalAttachment[]> {
    const attachments = await this.db.attachments
      .where("projectId")
      .equals(projectId)
      .filter((attachment) => attachment.status !== "synced")
      .toArray();

    return attachments.sort((a, b) => a.capturedAt.localeCompare(b.capturedAt)).slice(0, limit);
  }

  async updateAttachment(uuid: string, patch: Partial<LocalAttachment>): Promise<void> {
    await this.db.attachments.update(uuid, patch);
  }

  async removeAttachment(uuid: string): Promise<void> {
    await this.db.attachments.delete(uuid);
  }

  async listAttachmentsFor(attachableUuid: string): Promise<LocalAttachment[]> {
    return this.db.attachments.where("attachableUuid").equals(attachableUuid).toArray();
  }

  // ------------------------------------------------- reference and drafts

  async putReference(reference: CachedReference): Promise<void> {
    await this.db.references.put(reference);
  }

  async getReference<T>(key: string, projectId: number): Promise<T | undefined> {
    const row = await this.db.references.get([key, projectId]);
    return row?.payload as T | undefined;
  }

  async putDraft(draft: FormDraft): Promise<void> {
    await this.db.drafts.put(draft);
  }

  async getDraft(key: string, projectId: number): Promise<FormDraft | undefined> {
    return this.db.drafts.get([key, projectId]);
  }

  async removeDraft(key: string, projectId: number): Promise<void> {
    await this.db.drafts.delete([key, projectId]);
  }

  // -------------------------------------------------------- housekeeping

  async clearProject(projectId: number): Promise<void> {
    await this.db.transaction("rw", this.db.records, this.db.operations, this.db.attachments, this.db.references, this.db.drafts, async () => {
      await this.db.records.where("projectId").equals(projectId).delete();
      await this.db.operations.where("projectId").equals(projectId).delete();
      await this.db.attachments.where("projectId").equals(projectId).delete();
      await this.db.references.where("projectId").equals(projectId).delete();
      await this.db.drafts.where("projectId").equals(projectId).delete();
    });
  }

  async clearEverything(): Promise<void> {
    await this.db.delete();
    this.db = new SasaDatabase();
    await this.db.open();
  }

  async estimate(): Promise<{ usageBytes: number | null; quotaBytes: number | null }> {
    if (typeof navigator === "undefined" || !navigator.storage?.estimate) {
      return { usageBytes: null, quotaBytes: null };
    }

    const estimate = await navigator.storage.estimate();
    return { usageBytes: estimate.usage ?? null, quotaBytes: estimate.quota ?? null };
  }

  /** Escape hatch for the live-query hooks, which need the Dexie tables. */
  get tables() {
    return {
      records: this.db.records,
      operations: this.db.operations,
      attachments: this.db.attachments,
      drafts: this.db.drafts,
    };
  }
}

let instance: DexieLocalStore | null = null;

/** The single local store for this browser tab. */
export function localStore(): DexieLocalStore {
  if (!instance) {
    instance = new DexieLocalStore();
  }
  return instance;
}
