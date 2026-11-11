"use client";

import { Camera, File, FileText, ImageIcon, Upload, Video } from "lucide-react";
import { useRef, useState } from "react";
import { Button } from "@/components/ui/Button";
import { uploadEshsFile } from "@/lib/api/eshs";
import { formatDate } from "@/lib/utils";
import { useSync } from "@/providers/SyncProvider";
import { useToast } from "@/providers/ToastProvider";
import type { Attachment } from "@/types/api";

function iconFor(attachment: Attachment) {
  if (attachment.is_image) return ImageIcon;
  if (attachment.mime_type.startsWith("video/")) return Video;
  if (attachment.mime_type === "application/pdf" || attachment.mime_type.includes("word")) return FileText;
  return File;
}

/**
 * Evidence on an ESHS anomaly. Uploads go straight to the server — this module
 * is online-only — so a file listed here is on the server, and an upload that
 * cannot happen says so instead of pretending to queue.
 */
export function EshsEvidence({
  anomalyId,
  kind,
  attachments,
  canUpload,
  empty,
  onChanged,
}: {
  anomalyId: number;
  kind: "evidence" | "closure_evidence";
  attachments: Attachment[];
  canUpload: boolean;
  empty: string;
  onChanged: () => void;
}) {
  const toast = useToast();
  const sync = useSync();
  const fileInput = useRef<HTMLInputElement>(null);
  const cameraInput = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState(false);

  const upload = async (list: FileList | null) => {
    if (!list?.length) return;
    setBusy(true);
    const failed: string[] = [];

    for (const file of Array.from(list)) {
      try {
        await uploadEshsFile(anomalyId, file, kind);
      } catch (error) {
        failed.push(`${file.name}${error instanceof Error ? ` (${error.message})` : ""}`);
      }
    }

    setBusy(false);
    if (fileInput.current) fileInput.current.value = "";
    if (cameraInput.current) cameraInput.current.value = "";

    if (failed.length > 0) {
      toast.error("Not uploaded", failed.join("; "));
    } else {
      toast.success("Uploaded", "The file is saved on the server.");
    }
    onChanged();
  };

  return (
    <div>
      {canUpload ? (
        <div className="mb-4">
          <div className="flex flex-wrap gap-2">
            <input
              ref={cameraInput}
              type="file"
              accept="image/*"
              capture="environment"
              multiple
              className="sr-only"
              tabIndex={-1}
              aria-hidden
              onChange={(event) => void upload(event.target.files)}
            />
            <input
              ref={fileInput}
              id={`eshs-${kind}-file`}
              type="file"
              multiple
              className="sr-only"
              onChange={(event) => void upload(event.target.files)}
            />
            <Button
              variant="secondary"
              icon={<Camera className="h-4 w-4" />}
              onClick={() => cameraInput.current?.click()}
              loading={busy}
              disabled={!sync.online}
              className="sm:hidden"
            >
              Take a photo
            </Button>
            <Button
              variant="secondary"
              icon={<Upload className="h-4 w-4" />}
              onClick={() => fileInput.current?.click()}
              loading={busy}
              disabled={!sync.online}
            >
              Upload a file
            </Button>
          </div>
          {!sync.online ? (
            <p className="mt-2 text-sm text-warning-700">Uploading needs a connection. Nothing is stored on this device.</p>
          ) : null}
        </div>
      ) : null}

      {attachments.length === 0 ? (
        <p className="rounded-lg bg-surface-sunken px-4 py-6 text-center text-sm text-ink-500">{empty}</p>
      ) : (
        <ul className="grid gap-3 sm:grid-cols-2">
          {attachments.map((attachment) => {
            const Icon = iconFor(attachment);
            return (
              <li key={attachment.id} className="flex min-w-0 items-center gap-3 rounded-lg border border-hairline p-3">
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
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
