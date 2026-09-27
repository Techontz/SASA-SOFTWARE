"use client";

import { CheckCircle2, Plus, Smartphone } from "lucide-react";
import Link from "next/link";
import { Button } from "@/components/ui/Button";

/**
 * Shown in place of a redirect when a record is saved with no connection.
 *
 * Navigating away offline would mean a full page reload — and a page reload is
 * exactly when a user starts to doubt whether their work survived. So the app
 * stays put, says plainly where the record is, and offers the next entry: a
 * field officer is usually recording several in a row.
 */
export function SavedOnDeviceNotice({
  what,
  reference,
  onAddAnother,
  listHref,
  listLabel,
}: {
  what: string;
  reference?: string | null;
  onAddAnother: () => void;
  listHref: string;
  listLabel: string;
}) {
  return (
    <div className="mb-5 rounded-xl border border-warning-500/25 bg-warning-50 p-5">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="flex gap-3">
          <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-warning-100 text-warning-700">
            <Smartphone className="h-5 w-5" aria-hidden />
          </span>
          <div>
            <p className="font-semibold text-warning-900">
              {what} saved on this device{reference ? ` as ${reference}` : ""}
            </p>
            <p className="mt-1 text-sm leading-relaxed text-warning-800">
              It has <strong>not</strong> reached the server yet — there is no connection. SASA will send it by
              itself as soon as there is a signal, and nothing you entered can be lost in the meantime.
            </p>
          </div>
        </div>

        <div className="flex shrink-0 flex-wrap gap-2">
          <Link href={listHref}>
            <Button variant="secondary">{listLabel}</Button>
          </Link>
          <Button variant="primary" icon={<Plus className="h-4 w-4" />} onClick={onAddAnother}>
            Add another
          </Button>
        </div>
      </div>
    </div>
  );
}

/** The same idea for a record that did reach the server. */
export function SavedOnServerNotice({
  what,
  reference,
  href,
  onAddAnother,
}: {
  what: string;
  reference: string;
  href: string;
  onAddAnother: () => void;
}) {
  return (
    <div className="mb-5 rounded-xl border border-success-500/25 bg-success-50 p-5">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-center gap-3">
          <CheckCircle2 className="h-5 w-5 shrink-0 text-success-600" aria-hidden />
          <p className="font-medium text-success-900">
            {what} saved as {reference}
          </p>
        </div>
        <div className="flex shrink-0 flex-wrap gap-2">
          <Link href={href}>
            <Button variant="secondary">Open it</Button>
          </Link>
          <Button variant="primary" icon={<Plus className="h-4 w-4" />} onClick={onAddAnother}>
            Add another
          </Button>
        </div>
      </div>
    </div>
  );
}
