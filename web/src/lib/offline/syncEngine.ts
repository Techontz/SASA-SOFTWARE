import { ApiRequestError, apiRequest } from "@/lib/api/client";
import { deviceId, uuid } from "@/lib/utils";
import { localStore } from "./dexieStore";
import type {
  LocalAttachment,
  LocalEntity,
  LocalRecord,
  OperationType,
  QueueSummary,
  SyncOperation,
  SyncStatus,
} from "./types";

export type ConnectionState = "online" | "offline" | "syncing" | "synced" | "attention";

export interface SyncState {
  connection: ConnectionState;
  online: boolean;
  summary: QueueSummary;
  lastSyncAt: string | null;
  lastError: string | null;
}

interface PushResult {
  operation_uuid: string;
  entity: string;
  status: "applied" | "duplicate" | "conflict" | "rejected";
  server_id?: number;
  reference?: string;
  error?: string;
  retryable?: boolean;
  conflicts?: Record<string, unknown>;
  conflict_id?: number;
  validation_errors?: Record<string, string[]>;
  record?: Record<string, unknown>;
}

interface PushResponse {
  data: {
    device_id: string;
    server_time: string;
    results: PushResult[];
    summary: { applied: number; duplicate: number; conflict: number; rejected: number };
  };
}

const BATCH_SIZE = 25;
const ATTACHMENT_BATCH = 3;
const MAX_RETRIES = 8;

/**
 * The device half of the offline contract.
 *
 *   write locally  ->  queue  ->  connection returns  ->  push  ->  synced
 *
 * Nothing here deletes local work until the server has confirmed it. A failed
 * operation goes back on the queue with an exponential backoff; a conflicting
 * one is parked for a person to decide. The user is never told a record
 * reached the server when it only reached this device.
 */
class SyncEngine {
  private store = localStore();
  private projectId: number | null = null;
  private running = false;
  private timer: ReturnType<typeof setInterval> | null = null;
  private listeners = new Set<(state: SyncState) => void>();

  private state: SyncState = {
    connection: typeof navigator !== "undefined" && !navigator.onLine ? "offline" : "online",
    online: typeof navigator === "undefined" ? true : navigator.onLine,
    summary: { pending: 0, uploading: 0, failed: 0, conflict: 0, attachmentsPending: 0, oldestPendingAt: null },
    lastSyncAt: null,
    lastError: null,
  };

  // ------------------------------------------------------------ lifecycle

  async start(projectId: number): Promise<void> {
    this.projectId = projectId;
    await this.store.ready();

    // Re-read connectivity on every start: the device may have gone offline
    // while the tab was closed, and no event fires for that.
    if (typeof navigator !== "undefined") {
      this.patchState({ online: navigator.onLine, connection: navigator.onLine ? "online" : "offline" });
    }

    await this.refreshSummary();

    if (typeof window === "undefined") return;

    window.addEventListener("online", this.handleOnline);
    window.addEventListener("offline", this.handleOffline);
    document.addEventListener("visibilitychange", this.handleVisibility);

    this.timer ??= setInterval(() => {
      void this.flush();
    }, 30_000);

    if (navigator.onLine) {
      void this.flush();
    }
  }

  stop(): void {
    if (typeof window === "undefined") return;

    window.removeEventListener("online", this.handleOnline);
    window.removeEventListener("offline", this.handleOffline);
    document.removeEventListener("visibilitychange", this.handleVisibility);

    if (this.timer) {
      clearInterval(this.timer);
      this.timer = null;
    }
  }

  private handleOnline = () => {
    this.patchState({ online: true, connection: "online" });
    void this.flush();
  };

  private handleOffline = () => {
    this.patchState({ online: false, connection: "offline" });
  };

  private handleVisibility = () => {
    if (document.visibilityState === "visible" && navigator.onLine) {
      void this.flush();
    }
  };

  subscribe(listener: (state: SyncState) => void): () => void {
    this.listeners.add(listener);
    listener(this.state);
    return () => this.listeners.delete(listener);
  }

  getState(): SyncState {
    return this.state;
  }

  private patchState(patch: Partial<SyncState>) {
    this.state = { ...this.state, ...patch };
    this.listeners.forEach((listener) => listener(this.state));
  }

  private async refreshSummary(): Promise<void> {
    if (!this.projectId) return;

    const summary = await this.store.summary(this.projectId);

    const connection: ConnectionState = !this.state.online
      ? "offline"
      : summary.conflict > 0 || summary.failed > 0
        ? "attention"
        : summary.pending > 0 || summary.attachmentsPending > 0
          ? "syncing"
          : this.state.lastSyncAt
            ? "synced"
            : "online";

    this.patchState({ summary, connection });
  }

  // -------------------------------------------------------------- writing

  /**
   * Save a record. Online, this is a plain API call; offline (or when the call
   * fails for a network reason) the record is written locally and queued, and
   * the caller is told which of the two happened so the UI can say so honestly.
   */
  async save<T extends { id?: number }>(args: {
    entity: LocalEntity;
    operation: OperationType;
    path: string;
    payload: Record<string, unknown>;
    entityUuid?: string;
    serverId?: number;
    baseValues?: Record<string, unknown>;
    baseUpdatedAt?: string;
  }): Promise<{ saved: "server" | "device"; record?: T; entityUuid: string }> {
    const entityUuid = args.entityUuid ?? uuid();
    const payload = { ...args.payload, client_uuid: entityUuid };

    if (!this.projectId) {
      throw new Error("The sync engine has not been started for a project.");
    }

    if (this.state.online) {
      try {
        const response = await apiRequest<{ data: T }>(args.path, {
          method: args.operation === "create" ? "POST" : "PATCH",
          body: payload,
        });

        await this.cacheRecord(args.entity, entityUuid, response.data as Record<string, unknown>, "synced");

        return { saved: "server", record: response.data, entityUuid };
      } catch (error) {
        // Only a network-level failure falls through to the queue. A validation
        // error is the user's to fix now, not something to hide on the device.
        if (!(error instanceof ApiRequestError) || !error.isRetryable) {
          throw error;
        }
      }
    }

    await this.queue({
      entity: args.entity,
      operation: args.operation,
      entityUuid,
      serverId: args.serverId,
      payload,
      baseValues: args.baseValues,
      baseUpdatedAt: args.baseUpdatedAt,
    });

    return { saved: "device", entityUuid };
  }

  async queue(args: {
    entity: LocalEntity;
    operation: OperationType;
    entityUuid: string;
    serverId?: number;
    payload: Record<string, unknown>;
    baseValues?: Record<string, unknown>;
    baseUpdatedAt?: string;
  }): Promise<void> {
    if (!this.projectId) throw new Error("No project context for the sync queue.");

    const now = new Date().toISOString();

    const operation: SyncOperation = {
      operationUuid: uuid(),
      entity: args.entity,
      operation: args.operation,
      entityUuid: args.entityUuid,
      serverId: args.serverId,
      payload: args.payload,
      baseValues: args.baseValues,
      baseUpdatedAt: args.baseUpdatedAt,
      projectId: this.projectId,
      deviceId: deviceId(),
      createdAt: now,
      updatedAt: now,
      status: "pending",
      retryCount: 0,
      nextAttemptAt: null,
    };

    await this.store.enqueue(operation);
    await this.cacheRecord(args.entity, args.entityUuid, args.payload, "pending", args.serverId ?? null);
    await this.refreshSummary();

    if (this.state.online) {
      void this.flush();
    }
  }

  /** A photo taken in the field is kept locally until the server confirms it. */
  async queueAttachment(args: {
    attachableEntity: LocalEntity;
    attachableUuid: string;
    attachableServerId: number | null;
    file: File;
    kind?: string;
    caption?: string | null;
  }): Promise<LocalAttachment> {
    if (!this.projectId) throw new Error("No project context for the attachment queue.");

    const blob = await compressImage(args.file);

    const attachment: LocalAttachment = {
      uuid: uuid(),
      projectId: this.projectId,
      attachableEntity: args.attachableEntity,
      attachableUuid: args.attachableUuid,
      attachableServerId: args.attachableServerId,
      kind: args.kind ?? inferKind(args.file),
      caption: args.caption ?? null,
      fileName: args.file.name,
      mimeType: blob.type || args.file.type,
      sizeBytes: blob.size,
      blob,
      capturedAt: new Date().toISOString(),
      status: "pending",
      retryCount: 0,
      progress: 0,
    };

    await this.store.putAttachment(attachment);
    await this.refreshSummary();

    if (this.state.online) void this.flush();

    return attachment;
  }

  private async cacheRecord(
    entity: LocalEntity,
    entityUuid: string,
    data: Record<string, unknown>,
    syncStatus: SyncStatus,
    serverId: number | null = null,
  ): Promise<void> {
    if (!this.projectId) return;

    const existing = await this.store.getRecord(entity, entityUuid);
    const now = new Date().toISOString();

    const record: LocalRecord = {
      uuid: entityUuid,
      entity,
      projectId: this.projectId,
      serverId: (data.id as number | undefined) ?? serverId ?? existing?.serverId ?? null,
      reference: (data.reference as string | undefined) ?? existing?.reference ?? null,
      data: { ...(existing?.data ?? {}), ...data },
      syncStatus,
      capturedAt: existing?.capturedAt ?? now,
      updatedAt: now,
      syncedAt: syncStatus === "synced" ? now : (existing?.syncedAt ?? null),
      isLocalOnly: syncStatus !== "synced",
    };

    await this.store.putRecord(record);
  }

  // -------------------------------------------------------------- flushing

  async flush(): Promise<void> {
    if (this.running || !this.projectId || !this.state.online) return;

    this.running = true;
    this.patchState({ connection: "syncing", lastError: null });

    try {
      await this.pushOperations();
      await this.pushAttachments();

      this.patchState({ lastSyncAt: new Date().toISOString() });
    } catch (error) {
      const message = error instanceof Error ? error.message : "Sync failed.";

      if (error instanceof ApiRequestError && error.isOffline) {
        this.patchState({ online: false, connection: "offline" });
      } else {
        this.patchState({ lastError: message });
      }
    } finally {
      this.running = false;
      await this.refreshSummary();
    }
  }

  private async pushOperations(): Promise<void> {
    if (!this.projectId) return;

    for (;;) {
      const due = await this.store.claimDueOperations(this.projectId, BATCH_SIZE);
      if (due.length === 0) return;

      await Promise.all(
        due.map((operation) => this.store.updateOperation(operation.operationUuid, { status: "uploading" })),
      );
      await this.refreshSummary();

      let response: PushResponse;

      try {
        response = await apiRequest<PushResponse>("/sync/push", {
          method: "POST",
          body: {
            device_id: deviceId(),
            operations: due.map((operation) => ({
              operation_uuid: operation.operationUuid,
              entity: operation.entity,
              operation: operation.operation,
              entity_uuid: operation.entityUuid,
              server_id: operation.serverId,
              payload: operation.payload,
              base_values: operation.baseValues,
              base_updated_at: operation.baseUpdatedAt,
              client_created_at: operation.createdAt,
              retry_count: operation.retryCount,
            })),
          },
        });
      } catch (error) {
        // The batch did not reach the server. Everything goes back on the
        // queue with a backoff — nothing is lost, nothing is duplicated.
        await Promise.all(due.map((operation) => this.backoff(operation, error)));
        throw error;
      }

      for (const result of response.data.results) {
        const operation = due.find((o) => o.operationUuid === result.operation_uuid);
        if (!operation) continue;

        await this.applyResult(operation, result);
      }

      await this.refreshSummary();

      if (due.length < BATCH_SIZE) return;
    }
  }

  private async applyResult(operation: SyncOperation, result: PushResult): Promise<void> {
    switch (result.status) {
      case "applied":
      case "duplicate": {
        await this.store.updateOperation(operation.operationUuid, {
          status: "synced",
          serverId: result.server_id,
          serverReference: result.reference,
        });
        await this.store.removeOperation(operation.operationUuid);

        const existing = await this.store.getRecord(operation.entity, operation.entityUuid);
        if (existing) {
          await this.store.putRecord({
            ...existing,
            serverId: result.server_id ?? existing.serverId,
            reference: result.reference ?? existing.reference,
            data: { ...existing.data, ...(result.record ?? {}) },
            syncStatus: "synced",
            syncedAt: new Date().toISOString(),
            isLocalOnly: false,
          });
        }

        // Attachments waiting on this record now have a server id to attach to.
        const attachments = await this.store.listAttachmentsFor(operation.entityUuid);
        await Promise.all(
          attachments
            .filter((attachment) => attachment.attachableServerId === null)
            .map((attachment) =>
              this.store.updateAttachment(attachment.uuid, {
                attachableServerId: result.server_id ?? null,
              }),
            ),
        );
        break;
      }

      case "conflict": {
        await this.store.updateOperation(operation.operationUuid, {
          status: "conflict",
          conflictId: result.conflict_id ?? null,
          conflicts: result.conflicts ?? null,
          lastError: "This record was also changed on the server. Someone needs to choose which version is right.",
        });

        const record = await this.store.getRecord(operation.entity, operation.entityUuid);
        if (record) {
          await this.store.putRecord({ ...record, syncStatus: "conflict" });
        }
        break;
      }

      case "rejected": {
        if (result.retryable && operation.retryCount < MAX_RETRIES) {
          await this.backoff(operation, new Error(result.error ?? "Rejected"));
          break;
        }

        // Not retryable: keep it on the device, flag it, and let a person fix it.
        await this.store.updateOperation(operation.operationUuid, {
          status: "review",
          lastError: result.error ?? "The server could not accept this record.",
          validationErrors: result.validation_errors ?? null,
        });

        const record = await this.store.getRecord(operation.entity, operation.entityUuid);
        if (record) {
          await this.store.putRecord({ ...record, syncStatus: "review" });
        }
        break;
      }
    }
  }

  private async backoff(operation: SyncOperation, error: unknown): Promise<void> {
    const retryCount = operation.retryCount + 1;
    // 5s, 10s, 20s, 40s ... capped at 10 minutes.
    const delayMs = Math.min(600_000, 5_000 * 2 ** Math.min(retryCount, 7));

    await this.store.updateOperation(operation.operationUuid, {
      status: retryCount >= MAX_RETRIES ? "failed" : "pending",
      retryCount,
      nextAttemptAt: new Date(Date.now() + delayMs).toISOString(),
      lastError: error instanceof Error ? error.message : "Could not reach the server.",
    });
  }

  private async pushAttachments(): Promise<void> {
    if (!this.projectId) return;

    const pending = await this.store.listPendingAttachments(this.projectId, ATTACHMENT_BATCH);

    for (const attachment of pending) {
      // The parent record must exist on the server before its photo can.
      if (!attachment.attachableServerId) {
        const record = await this.store.getRecord(attachment.attachableEntity, attachment.attachableUuid);

        if (!record?.serverId) continue;

        await this.store.updateAttachment(attachment.uuid, { attachableServerId: record.serverId });
        attachment.attachableServerId = record.serverId;
      }

      await this.store.updateAttachment(attachment.uuid, { status: "uploading", progress: 10 });

      const form = new FormData();
      form.append("attachable_type", attachment.attachableEntity);
      form.append("attachable_id", String(attachment.attachableServerId));
      form.append("file", attachment.blob, attachment.fileName);
      form.append("kind", attachment.kind);
      form.append("client_uuid", attachment.uuid);
      form.append("captured_at", attachment.capturedAt);
      if (attachment.caption) form.append("caption", attachment.caption);

      try {
        const response = await apiRequest<{ data: { id: number } }>("/attachments", {
          method: "POST",
          formData: form,
        });

        // Only now is it safe to release the local copy.
        await this.store.updateAttachment(attachment.uuid, {
          status: "synced",
          progress: 100,
          serverId: response.data.id,
        });
        await this.store.removeAttachment(attachment.uuid);
      } catch (error) {
        const retryCount = attachment.retryCount + 1;

        await this.store.updateAttachment(attachment.uuid, {
          status: retryCount >= MAX_RETRIES ? "failed" : "pending",
          retryCount,
          progress: 0,
          lastError: error instanceof Error ? error.message : "Upload failed.",
        });

        if (error instanceof ApiRequestError && error.isOffline) {
          return;
        }
      }
    }

    await this.refreshSummary();
  }

  /** Retry everything the user has parked, at their request. */
  async retryFailed(): Promise<void> {
    if (!this.projectId) return;

    const operations = await this.store.listOperations(this.projectId);

    await Promise.all(
      operations
        .filter((operation) => operation.status === "failed" || operation.status === "review")
        .map((operation) =>
          this.store.updateOperation(operation.operationUuid, {
            status: "pending",
            retryCount: 0,
            nextAttemptAt: null,
            lastError: null,
          }),
        ),
    );

    await this.flush();
  }

  /** Cache the reference data a device needs to work with no signal. */
  async cacheReferenceData(): Promise<void> {
    if (!this.projectId || !this.state.online) return;

    try {
      const response = await apiRequest<{ data: Record<string, unknown> }>("/sync/bootstrap");

      await this.store.putReference({
        key: "bootstrap",
        projectId: this.projectId,
        payload: response.data,
        fetchedAt: new Date().toISOString(),
      });
    } catch {
      // Working from the last cached copy is the correct fallback here.
    }
  }

  async pullSince(since: string | null): Promise<string | null> {
    if (!this.projectId || !this.state.online) return since;

    const response = await apiRequest<{
      data: { cursor: string; data: Record<string, Array<Record<string, unknown>>> };
    }>("/sync/pull", { query: since ? { since, limit: 500 } : { limit: 500 } });

    const now = new Date().toISOString();

    for (const [entity, rows] of Object.entries(response.data.data)) {
      const records: LocalRecord[] = rows.map((row) => ({
        uuid: (row.client_uuid as string | null) ?? `server-${entity}-${row.id}`,
        entity: entity as LocalEntity,
        projectId: this.projectId as number,
        serverId: row.id as number,
        reference: (row.reference as string | null) ?? null,
        data: row,
        syncStatus: "synced",
        capturedAt: (row.captured_at as string | null) ?? now,
        updatedAt: (row.updated_at as string | null) ?? now,
        syncedAt: now,
        isLocalOnly: false,
      }));

      await this.store.putRecords(records);
    }

    return response.data.cursor;
  }
}

function inferKind(file: File): string {
  if (file.type.startsWith("image/")) return "photo";
  if (file.type.startsWith("audio/")) return "audio";
  if (file.type.startsWith("video/")) return "video";
  return "document";
}

/**
 * Photos from a phone camera are 4–8 MB. On a rural connection that is the
 * difference between a sync that completes and one that never does.
 */
async function compressImage(file: File, maxDimension = 1920, quality = 0.82): Promise<Blob> {
  if (!file.type.startsWith("image/") || typeof document === "undefined") {
    return file;
  }

  try {
    const bitmap = await createImageBitmap(file);
    const scale = Math.min(1, maxDimension / Math.max(bitmap.width, bitmap.height));

    if (scale === 1 && file.size < 1_200_000) {
      bitmap.close();
      return file;
    }

    const canvas = document.createElement("canvas");
    canvas.width = Math.round(bitmap.width * scale);
    canvas.height = Math.round(bitmap.height * scale);

    const context = canvas.getContext("2d");
    if (!context) return file;

    context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    bitmap.close();

    const blob = await new Promise<Blob | null>((resolve) =>
      canvas.toBlob(resolve, "image/jpeg", quality),
    );

    return blob && blob.size < file.size ? blob : file;
  } catch {
    return file;
  }
}

let engine: SyncEngine | null = null;

export function syncEngine(): SyncEngine {
  if (!engine) engine = new SyncEngine();
  return engine;
}
