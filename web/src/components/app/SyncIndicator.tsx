"use client";

import {
  AlertTriangle,
  Check,
  CloudUpload,
  RefreshCw,
  WifiOff,
} from "lucide-react";
import Link from "next/link";
import { useState } from "react";
import { useSync } from "@/providers/SyncProvider";
import { cn, formatRelative, pluralise } from "@/lib/utils";

/**
 * The user must always know where their work is. This never says "saved" when
 * a record has only reached the device.
 */
export function SyncIndicator({ compact = false }: { compact?: boolean }) {
  const sync = useSync();
  const [open, setOpen] = useState(false);

  const queued = sync.summary.pending + sync.summary.failed;
  const attention = sync.summary.conflict + sync.summary.failed;

  const state = !sync.online
    ? {
        tone: "warning" as const,
        icon: WifiOff,
        label: "Offline",
        detail: queued > 0
          ? `${queued} ${pluralise(queued, "change")} waiting on this device`
          : "Your work is being saved on this device",
      }
    : attention > 0
      ? {
          tone: "danger" as const,
          icon: AlertTriangle,
          label: "Needs attention",
          detail: `${attention} ${pluralise(attention, "record")} could not sync`,
        }
      : sync.connection === "syncing" || sync.summary.uploading > 0
        ? {
            tone: "info" as const,
            icon: CloudUpload,
            label: "Syncing",
            detail: `${queued + sync.summary.attachmentsPending} to go`,
          }
        : queued > 0
          ? {
              tone: "info" as const,
              icon: CloudUpload,
              label: "Waiting to sync",
              detail: `${queued} ${pluralise(queued, "change")} queued`,
            }
          : {
              tone: "success" as const,
              icon: Check,
              label: "All synced",
              detail: sync.lastSyncAt ? `Last synced ${formatRelative(sync.lastSyncAt)}` : "Everything is on the server",
            };

  const Icon = state.icon;

  const toneClasses = {
    success: "text-success-700 bg-success-50 ring-success-100",
    info: "text-info-700 bg-info-50 ring-info-100",
    warning: "text-warning-700 bg-warning-50 ring-warning-100",
    danger: "text-danger-700 bg-danger-50 ring-danger-100",
  }[state.tone];

  return (
    <div className="relative">
      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        aria-expanded={open}
        aria-label={`Sync status: ${state.label}. ${state.detail}`}
        className={cn(
          "inline-flex items-center gap-2 rounded-full py-1.5 pl-2.5 pr-3 text-xs font-medium ring-1 ring-inset transition",
          toneClasses,
          compact && "px-2",
        )}
      >
        <Icon className={cn("h-3.5 w-3.5", state.tone === "info" && "animate-pulse")} aria-hidden />
        {!compact ? state.label : null}
        {queued > 0 ? <span className="tabular rounded-full bg-white/70 px-1.5">{queued}</span> : null}
      </button>

      {open ? (
        <>
          <div className="fixed inset-0 z-40" onClick={() => setOpen(false)} aria-hidden />
          <div className="animate-fade-up absolute right-0 z-50 mt-2 w-80 rounded-xl border border-hairline bg-surface p-4 shadow-[var(--shadow-overlay)]">
            <div className="flex items-start gap-3">
              <span className={cn("flex h-9 w-9 items-center justify-center rounded-full ring-1 ring-inset", toneClasses)}>
                <Icon className="h-4 w-4" aria-hidden />
              </span>
              <div className="min-w-0">
                <p className="font-semibold text-ink-900">{state.label}</p>
                <p className="mt-0.5 text-sm text-ink-600">{state.detail}</p>
              </div>
            </div>

            <dl className="mt-4 grid grid-cols-2 gap-3 text-sm">
              <div className="rounded-lg bg-surface-sunken p-3">
                <dt className="text-xs text-ink-500">On this device</dt>
                <dd className="tabular mt-0.5 text-lg font-semibold text-ink-900">{sync.summary.pending}</dd>
              </div>
              <div className="rounded-lg bg-surface-sunken p-3">
                <dt className="text-xs text-ink-500">Photos to upload</dt>
                <dd className="tabular mt-0.5 text-lg font-semibold text-ink-900">{sync.summary.attachmentsPending}</dd>
              </div>
              {sync.summary.failed > 0 ? (
                <div className="rounded-lg bg-danger-50 p-3">
                  <dt className="text-xs text-danger-700">Failed</dt>
                  <dd className="tabular mt-0.5 text-lg font-semibold text-danger-700">{sync.summary.failed}</dd>
                </div>
              ) : null}
              {sync.summary.conflict > 0 ? (
                <div className="rounded-lg bg-danger-50 p-3">
                  <dt className="text-xs text-danger-700">Need a decision</dt>
                  <dd className="tabular mt-0.5 text-lg font-semibold text-danger-700">{sync.summary.conflict}</dd>
                </div>
              ) : null}
            </dl>

            {sync.summary.oldestPendingAt ? (
              <p className="mt-3 text-xs text-ink-500">
                Oldest change waiting since {formatRelative(sync.summary.oldestPendingAt)}.
              </p>
            ) : null}

            <div className="mt-4 flex items-center gap-2">
              <button
                type="button"
                onClick={() => void sync.flush()}
                disabled={!sync.online}
                className="inline-flex h-9 flex-1 items-center justify-center gap-1.5 rounded-md bg-primary px-3 text-sm font-medium text-on-primary transition hover:bg-primary-hover disabled:bg-ink-200 disabled:text-ink-400"
              >
                <RefreshCw className="h-3.5 w-3.5" aria-hidden />
                Sync now
              </button>
              <Link
                href="/sync"
                onClick={() => setOpen(false)}
                className="inline-flex h-9 items-center justify-center rounded-md border border-ink-300 px-3 text-sm font-medium text-ink-800 transition hover:bg-ink-50"
              >
                Details
              </Link>
            </div>

            {!sync.online ? (
              <p className="mt-3 rounded-lg bg-warning-50 p-3 text-xs leading-relaxed text-warning-700">
                You can keep working. Everything you enter is stored on this device and sent to the server by
                itself once there is a signal.
              </p>
            ) : null}
          </div>
        </>
      ) : null}
    </div>
  );
}

/** A persistent bar so nobody can be unaware they are working offline. */
export function OfflineBanner() {
  const sync = useSync();

  if (sync.online) return null;

  return (
    <div className="flex items-center justify-center gap-2 bg-warning-solid px-4 py-2 text-center text-sm font-medium text-white">
      <WifiOff className="h-4 w-4 shrink-0" aria-hidden />
      <span>
        You are offline. Your work is saved on this device and will sync when the connection returns.
      </span>
    </div>
  );
}
