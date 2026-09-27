"use client";

import { useLiveQuery } from "dexie-react-hooks";
import { Camera, File, FileText, ImageIcon, Loader2, Music, Paperclip, Trash2, Upload, Video } from "lucide-react";
import { useRef, useState } from "react";
import { Button } from "@/components/ui/Button";
import { apiRequest } from "@/lib/api/client";
import { localStore } from "@/lib/offline/dexieStore";
import { syncEngine } from "@/lib/offline/syncEngine";
import type { LocalEntity } from "@/lib/offline/types";
import { cn, formatDate } from "@/lib/utils";
import { useSync } from "@/providers/SyncProvider";
import { useToast } from "@/providers/ToastProvider";
import type { Attachment } from "@/types/api";

const KIND_ICON: Record<string, typeof File> = {
  photo: ImageIcon,
  audio: Music,
  video: Video,
  document: FileText,
  minutes: FileText,
  attendance: FileText,
  consent: FileText,
  evidence: Paperclip,
};

/**
 * Files captured in the field are queued locally and uploaded when there is a
 * signal. The local copy is only released once the server confirms it.
 */
export function AttachmentPanel({
  entity,
  entityUuid,
  serverId,
  attachments,
  canUpload,
  onChanged,
}: {
  entity: LocalEntity;
  entityUuid: string | null;
  serverId: number;
  attachments: Attachment[];
  canUpload: boolean;
  onChanged?: () => void;
}) {
  const toast = useToast();
  const sync = useSync();
  const fileInput = useRef<HTMLInputElement>(null);
  const cameraInput = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState(false);

  const localUuid = entityUuid ?? `server-${entity}-${serverId}`;

  const pending = useLiveQuery(
    () => localStore().tables.attachments.where("attachableUuid").equals(localUuid).toArray(),
    [localUuid],
    [],
  );

  const handleFiles = async (files: FileList | null, kind?: string) => {
    if (!files?.length) return;
    setBusy(true);

    try {
      for (const file of Array.from(files)) {
        await syncEngine().queueAttachment({
          attachableEntity: entity,
          attachableUuid: localUuid,
          attachableServerId: serverId,
          file,
          kind,
        });
      }

      toast.success(
        sync.online ? "Uploading" : "Saved on this device",
        sync.online
          ? "The file is being uploaded now."
          : "The file stays on this device and uploads by itself when the signal returns.",
      );

      onChanged?.();
    } catch {
      toast.error("We could not attach that file");
    } finally {
      setBusy(false);
      if (fileInput.current) fileInput.current.value = "";
      if (cameraInput.current) cameraInput.current.value = "";
    }
  };

  const remove = async (id: number) => {
    try {
      await apiRequest(`/attachments/${id}`, { method: "DELETE" });
      toast.success("File archived", "It is removed from the record and kept in the audit trail.");
      onChanged?.();
    } catch {
      toast.error("We could not remove that file");
    }
  };

  return (
    <div>
      {canUpload ? (
        <div className="mb-4 flex flex-wrap gap-2">
          <input
            ref={cameraInput}
            type="file"
            accept="image/*"
            capture="environment"
            multiple
            className="sr-only"
            onChange={(event) => void handleFiles(event.target.files, "photo")}
          />
          <input
            ref={fileInput}
            type="file"
            multiple
            className="sr-only"
            onChange={(event) => void handleFiles(event.target.files)}
          />
          <Button
            variant="secondary"
            icon={<Camera className="h-4 w-4" />}
            onClick={() => cameraInput.current?.click()}
            loading={busy}
            className="sm:hidden"
          >
            Take a photo
          </Button>
          <Button variant="secondary" icon={<Upload className="h-4 w-4" />} onClick={() => fileInput.current?.click()} loading={busy}>
            Attach a file
          </Button>
        </div>
      ) : null}

      {pending.length > 0 ? (
        <ul className="mb-4 space-y-2">
          {pending.map((item) => (
            <li key={item.uuid} className="flex items-center gap-3 rounded-lg border border-warning-500/25 bg-warning-50 p-3">
              <Loader2 className={cn("h-4 w-4 shrink-0 text-warning-600", item.status === "uploading" && "animate-spin")} aria-hidden />
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium text-warning-900">{item.fileName}</p>
                <p className="text-xs text-warning-700">
                  {item.status === "uploading"
                    ? "Uploading…"
                    : item.status === "failed"
                      ? `Upload failed — kept on this device and will retry. ${item.lastError ?? ""}`
                      : "On this device, waiting for a connection"}
                </p>
              </div>
              <span className="tabular text-xs text-warning-700">{Math.round(item.sizeBytes / 1024)} KB</span>
            </li>
          ))}
        </ul>
      ) : null}

      {attachments.length === 0 && pending.length === 0 ? (
        <p className="rounded-lg bg-surface-sunken px-4 py-6 text-center text-sm text-ink-500">
          No files yet. Photographs, minutes, attendance sheets and consent forms all belong here.
        </p>
      ) : (
        <ul className="grid gap-3 sm:grid-cols-2">
          {attachments.map((attachment) => {
            const Icon = KIND_ICON[attachment.kind] ?? File;

            return (
              <li key={attachment.id} className="flex items-center gap-3 rounded-lg border border-hairline p-3">
                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700">
                  <Icon className="h-4 w-4" aria-hidden />
                </span>
                <div className="min-w-0 flex-1">
                  <a
                    href={attachment.download_url}
                    target="_blank"
                    rel="noreferrer"
                    className="block truncate text-sm font-medium text-ink-900 hover:text-brand-700"
                  >
                    {attachment.original_name}
                  </a>
                  <p className="text-xs text-ink-500">
                    {attachment.size_label} · {formatDate(attachment.created_at)}
                    {attachment.uploaded_by ? ` · ${attachment.uploaded_by}` : ""}
                  </p>
                </div>
                {canUpload ? (
                  <button
                    type="button"
                    onClick={() => void remove(attachment.id)}
                    className="-m-1 rounded p-1 text-ink-400 transition hover:bg-danger-50 hover:text-danger-600"
                    aria-label={`Remove ${attachment.original_name}`}
                  >
                    <Trash2 className="h-4 w-4" aria-hidden />
                  </button>
                ) : null}
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
