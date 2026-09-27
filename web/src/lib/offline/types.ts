/**
 * The local-storage contract.
 *
 * Application code talks to this interface only. It does not know, and must
 * not care, whether the records underneath are in IndexedDB, in SQLite/WASM
 * over OPFS, or in a native SQLite database in a future mobile shell.
 *
 * See docs/OFFLINE.md for why IndexedDB is the shipped driver.
 */

export type SyncStatus =
  | "pending"    // queued locally, not yet sent
  | "uploading"  // in flight
  | "synced"     // the server has it
  | "failed"     // the server refused it; still on the device, will retry
  | "conflict"   // the server moved too; a person must choose
  | "review";    // needs a human decision before it can be retried

export type LocalEntity =
  | "stakeholder"
  | "engagement_plan"
  | "engagement"
  | "concern"
  | "commitment"
  | "grievance"
  | "grievance_follow_up";

export type OperationType = "create" | "update";

/** One row in the outbound queue. */
export interface SyncOperation {
  /** Client-generated. Unique on the server too, which is what makes replay safe. */
  operationUuid: string;
  entity: LocalEntity;
  operation: OperationType;
  /** Identity of the record itself, stable across retries. */
  entityUuid: string;
  /** Set once the server has assigned an id. */
  serverId?: number;
  serverReference?: string;
  payload: Record<string, unknown>;
  /** What the device believed the server held, so conflicts can be detected. */
  baseValues?: Record<string, unknown>;
  baseUpdatedAt?: string;
  projectId: number;
  deviceId: string;
  createdAt: string;
  updatedAt: string;
  status: SyncStatus;
  retryCount: number;
  nextAttemptAt: string | null;
  lastError?: string | null;
  validationErrors?: Record<string, string[]> | null;
  conflictId?: number | null;
  conflicts?: Record<string, unknown> | null;
}

/** A record as the device holds it, whether or not the server has seen it. */
export interface LocalRecord {
  /** entityUuid — the same identity the queue uses. */
  uuid: string;
  entity: LocalEntity;
  projectId: number;
  serverId: number | null;
  reference: string | null;
  /** The record body, in API shape. */
  data: Record<string, unknown>;
  syncStatus: SyncStatus;
  capturedAt: string;
  updatedAt: string;
  syncedAt: string | null;
  /** Written by the field officer, not yet reviewed. */
  isLocalOnly: boolean;
}

/** A photo or document captured offline, kept until the server confirms it. */
export interface LocalAttachment {
  uuid: string;
  projectId: number;
  attachableEntity: LocalEntity;
  /** The local record's uuid; resolved to a server id at upload time. */
  attachableUuid: string;
  attachableServerId: number | null;
  kind: string;
  caption: string | null;
  fileName: string;
  mimeType: string;
  sizeBytes: number;
  blob: Blob;
  capturedAt: string;
  status: SyncStatus;
  retryCount: number;
  lastError?: string | null;
  progress?: number;
  serverId?: number | null;
}

/** Read-only reference data cached so the forms work with no signal. */
export interface CachedReference {
  key: string;
  projectId: number;
  payload: unknown;
  fetchedAt: string;
}

/** An in-progress form, autosaved so a dropped connection loses nothing. */
export interface FormDraft {
  key: string;
  projectId: number;
  entity: string;
  values: Record<string, unknown>;
  updatedAt: string;
}

export interface QueueSummary {
  pending: number;
  uploading: number;
  failed: number;
  conflict: number;
  attachmentsPending: number;
  oldestPendingAt: string | null;
}

export interface LocalStore {
  ready(): Promise<void>;

  // --- records ----------------------------------------------------------
  putRecord(record: LocalRecord): Promise<void>;
  putRecords(records: LocalRecord[]): Promise<void>;
  getRecord(entity: LocalEntity, uuid: string): Promise<LocalRecord | undefined>;
  getRecordByServerId(entity: LocalEntity, serverId: number): Promise<LocalRecord | undefined>;
  listRecords(entity: LocalEntity, projectId: number): Promise<LocalRecord[]>;
  deleteRecord(entity: LocalEntity, uuid: string): Promise<void>;

  // --- queue -------------------------------------------------------------
  enqueue(operation: SyncOperation): Promise<void>;
  updateOperation(operationUuid: string, patch: Partial<SyncOperation>): Promise<void>;
  claimDueOperations(projectId: number, limit: number): Promise<SyncOperation[]>;
  listOperations(projectId: number): Promise<SyncOperation[]>;
  removeOperation(operationUuid: string): Promise<void>;
  summary(projectId: number): Promise<QueueSummary>;

  // --- attachments --------------------------------------------------------
  putAttachment(attachment: LocalAttachment): Promise<void>;
  listPendingAttachments(projectId: number, limit: number): Promise<LocalAttachment[]>;
  updateAttachment(uuid: string, patch: Partial<LocalAttachment>): Promise<void>;
  removeAttachment(uuid: string): Promise<void>;
  listAttachmentsFor(attachableUuid: string): Promise<LocalAttachment[]>;

  // --- reference data and drafts -------------------------------------------
  putReference(reference: CachedReference): Promise<void>;
  getReference<T>(key: string, projectId: number): Promise<T | undefined>;
  putDraft(draft: FormDraft): Promise<void>;
  getDraft(key: string, projectId: number): Promise<FormDraft | undefined>;
  removeDraft(key: string, projectId: number): Promise<void>;

  // --- housekeeping ---------------------------------------------------------
  clearProject(projectId: number): Promise<void>;
  clearEverything(): Promise<void>;
  estimate(): Promise<{ usageBytes: number | null; quotaBytes: number | null }>;
}
