"use client";

import { X } from "lucide-react";
import { useEffect, type ReactNode } from "react";
import { cn } from "@/lib/utils";

/**
 * A drawer on desktop, a sheet from the bottom on a phone — the same component,
 * because a field officer reaching with one thumb should not get a dialog
 * pinned to the top of the screen.
 */
export function Drawer({
  open,
  onClose,
  title,
  description,
  children,
  footer,
  width = "md",
}: {
  open: boolean;
  onClose: () => void;
  title: string;
  description?: string;
  children: ReactNode;
  footer?: ReactNode;
  width?: "sm" | "md" | "lg";
}) {
  useEffect(() => {
    if (!open) return;

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") onClose();
    };

    document.addEventListener("keydown", onKeyDown);
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";

    return () => {
      document.removeEventListener("keydown", onKeyDown);
      document.body.style.overflow = previousOverflow;
    };
  }, [onClose, open]);

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center sm:items-stretch sm:justify-end">
      <div
        className="sasa-scrim animate-fade-in absolute inset-0"
        onClick={onClose}
        aria-hidden
      />
      <div
        role="dialog"
        aria-modal="true"
        aria-label={title}
        className={cn(
          "animate-slide-up relative flex max-h-[92vh] w-full flex-col rounded-t-2xl bg-surface shadow-[var(--shadow-overlay)]",
          "sm:animate-slide-in-right sm:max-h-none sm:rounded-none sm:rounded-l-2xl",
          width === "sm" && "sm:w-[26rem]",
          width === "md" && "sm:w-[34rem]",
          width === "lg" && "sm:w-[46rem]",
        )}
      >
        <div className="mx-auto mt-3 h-1 w-10 shrink-0 rounded-full bg-ink-300 sm:hidden" aria-hidden />
        <header className="flex items-start justify-between gap-4 px-5 py-4 sm:px-6 sm:py-5">
          <div className="min-w-0">
            <h2 className="text-lg font-semibold text-ink-900">{title}</h2>
            {description ? <p className="mt-1 text-sm text-ink-600">{description}</p> : null}
          </div>
          <button
            type="button"
            onClick={onClose}
            className="-m-2 rounded-md p-2 text-ink-500 transition hover:bg-ink-100 hover:text-ink-800"
            aria-label="Close"
          >
            <X className="h-5 w-5" aria-hidden />
          </button>
        </header>
        <div className="flex-1 overflow-y-auto border-t border-hairline px-5 py-5 sm:px-6">{children}</div>
        {footer ? (
          <footer className="flex flex-col-reverse gap-2 border-t border-hairline bg-surface-sunken px-5 py-4 sm:flex-row sm:justify-end sm:px-6">
            {footer}
          </footer>
        ) : null}
      </div>
    </div>
  );
}

export function ConfirmDialog({
  open,
  onCancel,
  onConfirm,
  title,
  description,
  confirmLabel = "Confirm",
  cancelLabel = "Cancel",
  tone = "primary",
  loading,
  children,
}: {
  open: boolean;
  onCancel: () => void;
  onConfirm: () => void;
  title: string;
  description?: string;
  confirmLabel?: string;
  cancelLabel?: string;
  tone?: "primary" | "danger";
  loading?: boolean;
  children?: ReactNode;
}) {
  useEffect(() => {
    if (!open) return;
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") onCancel();
    };
    document.addEventListener("keydown", onKeyDown);
    return () => document.removeEventListener("keydown", onKeyDown);
  }, [onCancel, open]);

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center p-0 sm:items-center sm:p-6">
      <div className="sasa-scrim animate-fade-in absolute inset-0" onClick={onCancel} aria-hidden />
      <div
        role="alertdialog"
        aria-modal="true"
        aria-label={title}
        className="animate-slide-up relative w-full max-w-lg rounded-t-2xl bg-surface p-6 shadow-[var(--shadow-overlay)] sm:animate-fade-up sm:rounded-xl"
      >
        <h2 className="text-lg font-semibold text-ink-900">{title}</h2>
        {description ? <p className="mt-2 text-sm leading-relaxed text-ink-600">{description}</p> : null}
        {children ? <div className="mt-4">{children}</div> : null}
        <div className="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button
            type="button"
            onClick={onCancel}
            className="h-11 rounded-md border border-ink-300 px-4 font-medium text-ink-800 transition hover:bg-ink-50"
          >
            {cancelLabel}
          </button>
          <button
            type="button"
            onClick={onConfirm}
            disabled={loading}
            className={cn(
              "h-11 rounded-md px-4 font-medium text-white transition disabled:opacity-60",
              tone === "danger" ? "bg-danger-solid hover:bg-danger-solid-hover" : "bg-primary hover:bg-primary-hover",
            )}
          >
            {loading ? "Working…" : confirmLabel}
          </button>
        </div>
      </div>
    </div>
  );
}
