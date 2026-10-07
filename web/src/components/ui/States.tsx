"use client";

import {
  AlertTriangle,
  FileQuestion,
  Lock,
  RefreshCw,
  WifiOff,
  type LucideIcon,
} from "lucide-react";
import type { ReactNode } from "react";
import { ApiRequestError } from "@/lib/api/client";
import { cn } from "@/lib/utils";
import { Button } from "./Button";

/** Loading, empty, error, offline and permission-denied, in one place. */

export function EmptyState({
  icon: Icon = FileQuestion,
  title,
  description,
  action,
  className,
}: {
  icon?: LucideIcon;
  title: string;
  description?: string;
  action?: ReactNode;
  className?: string;
}) {
  return (
    <div className={cn("flex flex-col items-center justify-center px-6 py-14 text-center", className)}>
      <span className="flex h-14 w-14 items-center justify-center rounded-full bg-brand-50 text-brand-600">
        <Icon className="h-6 w-6" aria-hidden />
      </span>
      <h3 className="mt-4 text-base font-semibold text-ink-900">{title}</h3>
      {description ? <p className="mt-1.5 max-w-md text-sm text-ink-600">{description}</p> : null}
      {action ? <div className="mt-5">{action}</div> : null}
    </div>
  );
}

export function ErrorState({
  title = "We could not load this",
  description,
  onRetry,
  className,
  error,
}: {
  title?: string;
  description?: string;
  onRetry?: () => void;
  className?: string;
  /**
   * The failure itself, when the caller has it. The API's own message is
   * written for the person reading it, so it is better than anything generic
   * this component could say — and the request id is what support asks for
   * first when someone reports that a screen would not load.
   */
  error?: unknown;
}) {
  const failure = error instanceof ApiRequestError ? error : null;

  return (
    <div className={cn("flex flex-col items-center justify-center px-6 py-14 text-center", className)}>
      <span className="flex h-14 w-14 items-center justify-center rounded-full bg-danger-50 text-danger-600">
        <AlertTriangle className="h-6 w-6" aria-hidden />
      </span>
      <h3 className="mt-4 text-base font-semibold text-ink-900">{title}</h3>
      <p className="mt-1.5 max-w-md text-sm text-ink-600">
        {description ??
          failure?.message ??
          "Something went wrong on our side. Nothing you entered has been lost."}
      </p>
      {onRetry ? (
        <Button className="mt-5" variant="secondary" icon={<RefreshCw className="h-4 w-4" />} onClick={onRetry}>
          Try again
        </Button>
      ) : null}
      {failure?.requestId ? (
        <p className="mt-4 font-mono text-[0.6875rem] text-ink-400">
          Reference {failure.requestId}
        </p>
      ) : null}
    </div>
  );
}

export function OfflineState({ className }: { className?: string }) {
  return (
    <div className={cn("flex flex-col items-center justify-center px-6 py-14 text-center", className)}>
      <span className="flex h-14 w-14 items-center justify-center rounded-full bg-warning-50 text-warning-600">
        <WifiOff className="h-6 w-6" aria-hidden />
      </span>
      <h3 className="mt-4 text-base font-semibold text-ink-900">You are offline</h3>
      <p className="mt-1.5 max-w-md text-sm text-ink-600">
        This screen needs a connection. Anything you have already saved is on this device and will sync
        by itself when the signal returns.
      </p>
    </div>
  );
}

export function PermissionDenied({
  what = "this",
  className,
}: {
  what?: string;
  className?: string;
}) {
  return (
    <div className={cn("flex flex-col items-center justify-center px-6 py-14 text-center", className)}>
      <span className="flex h-14 w-14 items-center justify-center rounded-full bg-ink-100 text-ink-600">
        <Lock className="h-6 w-6" aria-hidden />
      </span>
      <h3 className="mt-4 text-base font-semibold text-ink-900">You do not have access to {what}</h3>
      <p className="mt-1.5 max-w-md text-sm text-ink-600">
        Your role on this project does not include it. If you need it, ask your project administrator.
      </p>
    </div>
  );
}

/**
 * The scanning ring: SASA's mark for "work is happening, wait a moment".
 *
 * It is for waits the user is actually watching — a search running, a file
 * being read, a report being built, the app opening. A wait that has a shape
 * already, like a list or a card, gets a skeleton instead, because a skeleton
 * says what is coming and a spinner does not.
 *
 * The label is read out to screen readers; the ring hides it visually.
 */
export function Scanner({
  label = "Loading",
  size = 40,
  on = "surface",
  className,
}: {
  label?: string;
  /** Diameter in px. The ring is drawn from font-size, at 5em across. */
  size?: number;
  /** What is behind it, so the groove takes the right colour. */
  on?: "surface" | "canvas" | "chrome";
  className?: string;
}) {
  return (
    <span
      role="status"
      className={cn(
        "sasa-scanner",
        on === "canvas" && "sasa-scanner-on-canvas",
        on === "chrome" && "sasa-scanner-on-chrome",
        className,
      )}
      style={{ fontSize: size / 5 }}
    >
      {label}
    </span>
  );
}

export function LoadingState({
  label = "Loading",
  className,
  on = "surface",
}: {
  label?: string;
  className?: string;
  on?: "surface" | "canvas" | "chrome";
}) {
  return (
    <div className={cn("flex flex-col items-center justify-center gap-4 px-6 py-14", className)}>
      <Scanner label={label} on={on} />
      <span className="text-sm text-ink-500">{label}…</span>
    </div>
  );
}

export function TableSkeleton({ rows = 6, columns = 5 }: { rows?: number; columns?: number }) {
  return (
    <div className="divide-y divide-hairline" aria-hidden>
      {Array.from({ length: rows }).map((_, rowIndex) => (
        <div key={rowIndex} className="flex items-center gap-4 px-5 py-4 sm:px-6">
          {Array.from({ length: columns }).map((__, columnIndex) => (
            <div
              key={columnIndex}
              className="sasa-skeleton h-4"
              style={{ width: columnIndex === 0 ? "22%" : `${Math.max(10, 70 / columns)}%` }}
            />
          ))}
        </div>
      ))}
    </div>
  );
}

export function CardSkeleton({ className }: { className?: string }) {
  return (
    <div className={cn("sasa-card p-6", className)} aria-hidden>
      <div className="sasa-skeleton h-4 w-40" />
      <div className="sasa-skeleton mt-4 h-3 w-full" />
      <div className="sasa-skeleton mt-2 h-3 w-5/6" />
      <div className="sasa-skeleton mt-2 h-3 w-2/3" />
    </div>
  );
}
