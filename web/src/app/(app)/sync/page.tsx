"use client";

import { useQueryClient } from "@tanstack/react-query";
import { useLiveQuery } from "dexie-react-hooks";
import {
  AlertOctagon,
  ArrowLeftRight,
  CheckCircle2,
  Database,
  HardDrive,
  RefreshCw,
  Smartphone,
  Trash2,
  WifiOff,
} from "lucide-react";
import { useEffect, useState } from "react";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { PageHeader } from "@/components/ui/DetailLayout";
import { ConfirmDialog } from "@/components/ui/Drawer";
import { StatusBadge } from "@/components/ui/StatusBadge";
import { EmptyState } from "@/components/ui/States";
import { apiRequest } from "@/lib/api/client";
import { useSyncConflicts } from "@/lib/api/hooks";
import { localStore } from "@/lib/offline/dexieStore";
import type { LocalAttachment, SyncOperation } from "@/lib/offline/types";
import { syncEngine } from "@/lib/offline/syncEngine";
import { cn, deviceId, formatDateTime, formatRelative, humanise, pluralise } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";
import { useSync } from "@/providers/SyncProvider";
import { useToast } from "@/providers/ToastProvider";

export default function SyncPage() {
  const sync = useSync();
  const toast = useToast();
  const queryClient = useQueryClient();
  const { can, project } = useSession();
  const { data: conflicts } = useSyncConflicts("open");

  const [storage, setStorage] = useState<{ usageBytes: number | null; quotaBytes: number | null }>({
    usageBytes: null,
    quotaBytes: null,
  });
  const [clearOpen, setClearOpen] = useState(false);
  const [resolving, setResolving] = useState<number | null>(null);

  const operations = useLiveQuery<SyncOperation[], SyncOperation[]>(
    () => (project ? localStore().listOperations(project.id) : Promise.resolve([] as SyncOperation[])),
    [project?.id],
    [] as SyncOperation[],
  );

  const attachments = useLiveQuery<LocalAttachment[], LocalAttachment[]>(
    () =>
      project
        ? localStore().tables.attachments.where("projectId").equals(project.id).toArray()
        : Promise.resolve([] as LocalAttachment[]),
    [project?.id],
    [] as LocalAttachment[],
  );

  useEffect(() => {
    void localStore().estimate().then(setStorage);
  }, [operations.length]);

  const resolveConflict = async (id: number, resolution: "keep_local" | "keep_server") => {
    setResolving(id);
    try {
      await apiRequest(`/sync/conflicts/${id}/resolve`, { method: "POST", body: { resolution } });
      await queryClient.invalidateQueries({ queryKey: ["sync-conflicts"] });
      toast.success(
        "Conflict resolved",
        resolution === "keep_local" ? "The version from the device has been applied." : "The server version has been kept.",
      );
    } catch (error) {
      toast.error("We could not resolve that", error instanceof Error ? error.message : undefined);
    } finally {
      setResolving(null);
    }
  };

  const clearLocal = async () => {
    if (!project) return;
    await localStore().clearProject(project.id);
    toast.success("Local copy cleared", "Anything already on the server is untouched.");
    setClearOpen(false);
  };

  const openConflicts = conflicts?.data.data ?? [];
  const queued = operations.filter((operation) => operation.status !== "synced");

  return (
    <div>
      <PageHeader
        eyebrow="Offline"
        title="Sync and offline"
        description="What is on this device, what has reached the server, and anything that needs a decision. You never lose a completed form because the connection dropped."
        actions={
          <>
            <Button
              variant="secondary"
              icon={<RefreshCw className="h-4 w-4" />}
              disabled={!sync.online}
              onClick={() => void sync.flush()}
            >
              Sync now
            </Button>
            {queued.some((operation) => operation.status === "failed" || operation.status === "review") ? (
              <Button variant="primary" onClick={() => void sync.retryFailed()}>
                Retry everything that failed
              </Button>
            ) : null}
          </>
        }
      />

      {/* -------------------------- status strip -------------------------- */}
      <div className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <StatusTile
          icon={sync.online ? CheckCircle2 : WifiOff}
          tone={sync.online ? "success" : "warning"}
          label="Connection"
          value={sync.online ? "Online" : "Offline"}
          caption={sync.online ? "Changes go straight to the server" : "Everything is being saved on this device"}
        />
        <StatusTile
          icon={Smartphone}
          tone={sync.summary.pending > 0 ? "info" : "neutral"}
          label="Waiting on this device"
          value={String(sync.summary.pending + sync.summary.failed)}
          caption={
            sync.summary.oldestPendingAt
              ? `Oldest since ${formatRelative(sync.summary.oldestPendingAt)}`
              : "Nothing queued"
          }
        />
        <StatusTile
          icon={ArrowLeftRight}
          tone={sync.summary.attachmentsPending > 0 ? "info" : "neutral"}
          label="Photos to upload"
          value={String(sync.summary.attachmentsPending)}
          caption="Kept locally until the server confirms them"
        />
        <StatusTile
          icon={AlertOctagon}
          tone={openConflicts.length > 0 ? "danger" : "neutral"}
          label="Need a decision"
          value={String(openConflicts.length)}
          caption={openConflicts.length > 0 ? "Someone must choose which version is right" : "No conflicts"}
        />
      </div>

      {/* -------------------------- conflicts -------------------------- */}
      {openConflicts.length > 0 ? (
        <Card className="mb-6 border-danger-500/25">
          <CardHeader
            title="Changes that clash"
            description="Somebody edited this record on the server while the device was offline. SASA did not overwrite either version — choose which one is right."
          />
          <CardBody className="space-y-4">
            {openConflicts.map((conflict) => (
              <div key={conflict.id} className="rounded-lg border border-hairline p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <div>
                    <p className="font-medium text-ink-900">
                      {conflict.entity_reference ?? humanise(conflict.entity)} · {humanise(conflict.entity)}
                    </p>
                    <p className="mt-0.5 text-sm text-ink-500">
                      From device {conflict.device_id ?? "unknown"} · {formatRelative(conflict.created_at)}
                    </p>
                  </div>
                  <div className="flex gap-2">
                    <Button
                      size="sm"
                      variant="secondary"
                      loading={resolving === conflict.id}
                      onClick={() => void resolveConflict(conflict.id, "keep_server")}
                      disabled={!can("sync.resolve_conflicts")}
                    >
                      Keep the server version
                    </Button>
                    <Button
                      size="sm"
                      variant="primary"
                      loading={resolving === conflict.id}
                      onClick={() => void resolveConflict(conflict.id, "keep_local")}
                      disabled={!can("sync.resolve_conflicts")}
                    >
                      Use the device version
                    </Button>
                  </div>
                </div>

                <div className="mt-4 space-y-3">
                  {Object.entries(conflict.conflicting_fields).map(([field, values]) => (
                    <div key={field} className="grid gap-3 sm:grid-cols-2">
                      <div className="rounded-lg bg-brand-50 p-3">
                        <p className="sasa-eyebrow mb-1">On this device — {humanise(field)}</p>
                        <p className="text-sm font-medium text-ink-900">{String(values.local ?? "—")}</p>
                      </div>
                      <div className="rounded-lg bg-ink-50 p-3">
                        <p className="sasa-eyebrow mb-1">On the server — {humanise(field)}</p>
                        <p className="text-sm font-medium text-ink-900">{String(values.server ?? "—")}</p>
                        <p className="mt-1 text-xs text-ink-500">
                          Changed by {values.server_changed_by ?? "someone"}{" "}
                          {values.server_changed_at ? formatRelative(String(values.server_changed_at)) : ""}
                        </p>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            ))}
          </CardBody>
        </Card>
      ) : null}

      <div className="grid min-w-0 gap-4 lg:grid-cols-[1.4fr_1fr]">
        <Card>
          <CardHeader
            title="The queue on this device"
            description="Every change made offline, in the order it was made. Nothing leaves this list until the server has it."
          />
          <CardBody className="p-0 sm:p-0">
            {queued.length === 0 ? (
              <EmptyState
                icon={CheckCircle2}
                title="Everything is on the server"
                description="No changes are waiting. You can go offline and keep working — anything you enter will be queued here."
              />
            ) : (
              <ul className="divide-y divide-hairline">
                {queued.map((operation) => (
                  <li key={operation.operationUuid} className="flex flex-wrap items-center gap-3 px-5 py-3.5">
                    <StatusBadge status={operation.status} size="sm" />
                    <div className="min-w-0 flex-1">
                      <p className="font-medium text-ink-900">
                        {humanise(operation.operation)} {humanise(operation.entity)}
                        {operation.serverReference ? ` · ${operation.serverReference}` : ""}
                      </p>
                      <p className="mt-0.5 text-xs text-ink-500">
                        Captured {formatRelative(operation.createdAt)}
                        {operation.retryCount > 0 ? ` · ${operation.retryCount} ${pluralise(operation.retryCount, "attempt")}` : ""}
                      </p>
                      {operation.lastError ? (
                        <p className="mt-1 text-xs text-danger-700">{operation.lastError}</p>
                      ) : null}
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </CardBody>
        </Card>

        <div className="min-w-0 space-y-4">
          {attachments.length > 0 ? (
            <Card>
              <CardHeader title="Files waiting to upload" description="Kept on the device until the server confirms them." />
              <CardBody className="p-0 sm:p-0">
                <ul className="divide-y divide-hairline">
                  {attachments.map((attachment) => (
                    <li key={attachment.uuid} className="flex items-center gap-3 px-5 py-3">
                      <StatusBadge status={attachment.status} size="sm" />
                      <span className="min-w-0 flex-1 truncate text-sm text-ink-800">{attachment.fileName}</span>
                      <span className="tabular shrink-0 text-xs text-ink-500">
                        {Math.round(attachment.sizeBytes / 1024)} KB
                      </span>
                    </li>
                  ))}
                </ul>
              </CardBody>
            </Card>
          ) : null}

          <Card>
            <CardHeader title="This device" description="How SASA stores your work when there is no signal." />
            <CardBody className="space-y-4">
              <dl className="space-y-3 text-sm">
                <div className="flex items-start justify-between gap-3">
                  <dt className="text-ink-600">Device id</dt>
                  <dd className="max-w-[60%] truncate font-mono text-xs text-ink-800">{deviceId()}</dd>
                </div>
                <div className="flex items-start justify-between gap-3">
                  <dt className="text-ink-600">Local database</dt>
                  <dd className="font-medium text-ink-800">IndexedDB</dd>
                </div>
                <div className="flex items-start justify-between gap-3">
                  <dt className="text-ink-600">Space used</dt>
                  <dd className="tabular font-medium text-ink-800">
                    {storage.usageBytes !== null ? `${(storage.usageBytes / 1_048_576).toFixed(1)} MB` : "—"}
                    {storage.quotaBytes ? (
                      <span className="ml-1 font-normal text-ink-500">
                        of {(storage.quotaBytes / 1_073_741_824).toFixed(1)} GB
                      </span>
                    ) : null}
                  </dd>
                </div>
                <div className="flex items-start justify-between gap-3">
                  <dt className="text-ink-600">Last synced</dt>
                  <dd className="font-medium text-ink-800">
                    {sync.lastSyncAt ? formatDateTime(sync.lastSyncAt) : "Not yet"}
                  </dd>
                </div>
              </dl>

              <div className="rounded-lg bg-surface-sunken p-3 text-sm leading-relaxed text-ink-600">
                <p className="flex items-start gap-2">
                  <Database className="mt-0.5 h-4 w-4 shrink-0 text-ink-500" aria-hidden />
                  Your work is written to a real local database on this device, not to a browser cookie. It
                  survives closing the tab, restarting the browser and restarting the phone.
                </p>
              </div>

              <Button
                variant="ghost"
                fullWidth
                icon={<Trash2 className="h-4 w-4" />}
                onClick={() => setClearOpen(true)}
                disabled={queued.length > 0 || attachments.length > 0}
              >
                Clear the local copy
              </Button>
              {queued.length > 0 || attachments.length > 0 ? (
                <p className="text-xs text-ink-500">
                  You cannot clear the local copy while work is still waiting to sync — that is the point of it.
                </p>
              ) : null}
            </CardBody>
          </Card>

          <Card>
            <CardHeader title="Cache field data" description="Pull down what this device will need to work with no signal." />
            <CardBody>
              <Button
                variant="secondary"
                fullWidth
                icon={<HardDrive className="h-4 w-4" />}
                disabled={!sync.online}
                onClick={async () => {
                  await syncEngine().cacheReferenceData();
                  const cursor = await syncEngine().pullSince(null);
                  toast.success("Field data cached", `Records up to ${formatDateTime(cursor)} are on this device.`);
                }}
              >
                Download for offline use
              </Button>
              <p className="mt-3 text-xs leading-relaxed text-ink-500">
                Categories, locations, project members and recent records are stored locally. Confidential
                complainant details are never cached on a device.
              </p>
            </CardBody>
          </Card>
        </div>
      </div>

      <ConfirmDialog
        open={clearOpen}
        onCancel={() => setClearOpen(false)}
        onConfirm={() => void clearLocal()}
        tone="danger"
        title="Clear this device's local copy?"
        description="Everything on the server is untouched. Only the cached copy on this device is removed, and it will be downloaded again next time you use SASA online."
        confirmLabel="Clear the local copy"
      />
    </div>
  );
}

function StatusTile({
  icon: Icon,
  tone,
  label,
  value,
  caption,
}: {
  icon: typeof CheckCircle2;
  tone: "success" | "info" | "warning" | "danger" | "neutral";
  label: string;
  value: string;
  caption: string;
}) {
  const toneClass = {
    success: "bg-success-50 text-success-700",
    info: "bg-info-50 text-info-700",
    warning: "bg-warning-50 text-warning-700",
    danger: "bg-danger-50 text-danger-700",
    neutral: "bg-ink-100 text-ink-600",
  }[tone];

  return (
    <div className="sasa-card p-4">
      <div className="flex items-center gap-3">
        <span className={cn("flex h-9 w-9 shrink-0 items-center justify-center rounded-lg", toneClass)}>
          <Icon className="h-4 w-4" aria-hidden />
        </span>
        <div className="min-w-0">
          <p className="sasa-eyebrow">{label}</p>
          <p className="tabular mt-0.5 text-lg font-semibold text-ink-900">{value}</p>
        </div>
      </div>
      <p className="mt-2 text-xs leading-relaxed text-ink-500">{caption}</p>
    </div>
  );
}
