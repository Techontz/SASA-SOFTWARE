"use client";

import { AlertCircle, Camera, File, FileText, ImageIcon, Music, Trash2, Upload, Video } from "lucide-react";
import { useRef } from "react";
import { Button } from "@/components/ui/Button";
import { syncEngine } from "@/lib/offline/syncEngine";
import type { LocalEntity } from "@/lib/offline/types";

/** Mirrors `sasa.files.allowed_mimes` on the server, so a refused file is refused here first. */
const ACCEPT = [
  "image/*",
  "application/pdf",
  ".doc",
  ".docx",
  ".xls",
  ".xlsx",
  ".csv",
  ".txt",
  "audio/*",
  "video/mp4",
  "video/webm",
  "video/quicktime",
].join(",");

function iconFor(file: File) {
  if (file.type.startsWith("image/")) return ImageIcon;
  if (file.type.startsWith("audio/")) return Music;
  if (file.type.startsWith("video/")) return Video;
  if (file.type === "application/pdf" || file.type.includes("word")) return FileText;
  return File;
}

function sizeLabel(bytes: number): string {
  if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/**
 * Collects files on a create form before the record exists. Nothing is queued
 * until the record is saved; then `queueAttachments` hands every file to the
 * sync engine against the record's client UUID, so it uploads whether the
 * record reached the server or is still on the device.
 */
export function AttachmentPicker({
  files,
  onChange,
  error,
  hint,
}: {
  files: File[];
  onChange: (files: File[]) => void;
  error?: string;
  hint: string;
}) {
  const fileInput = useRef<HTMLInputElement>(null);
  const cameraInput = useRef<HTMLInputElement>(null);

  const add = (list: FileList | null) => {
    if (!list?.length) return;
    onChange([...files, ...Array.from(list)]);
    if (fileInput.current) fileInput.current.value = "";
    if (cameraInput.current) cameraInput.current.value = "";
  };

  return (
    <div>
      <p className="mb-3 text-sm text-ink-600">{hint}</p>

      <div className="mb-3 flex flex-wrap gap-2">
        <input
          ref={cameraInput}
          type="file"
          accept="image/*"
          capture="environment"
          multiple
          className="sr-only"
          tabIndex={-1}
          aria-hidden
          onChange={(event) => add(event.target.files)}
        />
        <input
          ref={fileInput}
          id="attachments"
          type="file"
          accept={ACCEPT}
          multiple
          className="sr-only"
          onChange={(event) => add(event.target.files)}
        />
        <Button
          variant="secondary"
          icon={<Camera className="h-4 w-4" />}
          onClick={() => cameraInput.current?.click()}
          className="sm:hidden"
        >
          Take a photo
        </Button>
        <Button variant="secondary" icon={<Upload className="h-4 w-4" />} onClick={() => fileInput.current?.click()}>
          Attach files
        </Button>
      </div>

      {files.length > 0 ? (
        <ul className="grid gap-2 sm:grid-cols-2">
          {files.map((file, index) => {
            const Icon = iconFor(file);
            return (
              <li key={`${file.name}-${index}`} className="flex items-center gap-3 rounded-lg border border-hairline p-3">
                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700">
                  <Icon className="h-4 w-4" aria-hidden />
                </span>
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium text-ink-900">{file.name}</p>
                  <p className="text-xs text-ink-500">{sizeLabel(file.size)}</p>
                </div>
                <button
                  type="button"
                  onClick={() => onChange(files.filter((_, i) => i !== index))}
                  className="-m-1 rounded p-1 text-ink-400 transition hover:bg-danger-50 hover:text-danger-600"
                  aria-label={`Remove ${file.name}`}
                >
                  <Trash2 className="h-4 w-4" aria-hidden />
                </button>
              </li>
            );
          })}
        </ul>
      ) : (
        <p className="rounded-lg bg-surface-sunken px-4 py-5 text-center text-sm text-ink-500">No files attached yet.</p>
      )}

      {error ? (
        <p className="mt-2 flex items-center gap-1.5 text-sm text-danger-700" role="alert">
          <AlertCircle className="h-4 w-4 shrink-0" aria-hidden />
          {error}
        </p>
      ) : null}
    </div>
  );
}

/** Queues each file against a just-saved record. */
export async function queueAttachments(args: {
  entity: LocalEntity;
  entityUuid: string;
  serverId: number | null;
  files: File[];
}): Promise<void> {
  for (const file of args.files) {
    await syncEngine().queueAttachment({
      attachableEntity: args.entity,
      attachableUuid: args.entityUuid,
      attachableServerId: args.serverId,
      file,
    });
  }
}
