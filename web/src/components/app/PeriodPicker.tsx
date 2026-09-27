"use client";

import { CalendarRange, ChevronDown } from "lucide-react";
import { useState } from "react";
import { cn, formatDate } from "@/lib/utils";

export interface Period {
  from: string;
  to: string;
  label: string;
}

function isoDate(date: Date): string {
  return date.toISOString().slice(0, 10);
}

export function presetPeriods(): Period[] {
  const today = new Date();

  const make = (label: string, from: Date, to: Date = today): Period => ({
    label,
    from: isoDate(from),
    to: isoDate(to),
  });

  const startOfQuarter = new Date(today.getFullYear(), Math.floor(today.getMonth() / 3) * 3, 1);

  return [
    make("Last 30 days", new Date(today.getTime() - 29 * 86_400_000)),
    make("Last 90 days", new Date(today.getTime() - 89 * 86_400_000)),
    make("This quarter", startOfQuarter),
    make("This year", new Date(today.getFullYear(), 0, 1)),
    make("Last 12 months", new Date(today.getFullYear() - 1, today.getMonth(), today.getDate())),
  ];
}

/** The reporting period, present in the header of every dashboard. */
export function PeriodPicker({
  period,
  onChange,
  className,
}: {
  period: Period;
  onChange: (period: Period) => void;
  className?: string;
}) {
  const [open, setOpen] = useState(false);
  const presets = presetPeriods();

  return (
    <div className={cn("relative", className)}>
      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        aria-expanded={open}
        className="inline-flex h-11 w-full items-center gap-2 rounded-md border border-ink-300 bg-surface px-3 text-sm font-medium text-ink-800 transition hover:border-ink-400 sm:w-auto"
      >
        <CalendarRange className="h-4 w-4 shrink-0 text-ink-500" aria-hidden />
        <span className="truncate">{period.label}</span>
        <ChevronDown className={cn("h-4 w-4 shrink-0 text-ink-500 transition", open && "rotate-180")} aria-hidden />
      </button>

      {open ? (
        <>
          <div className="fixed inset-0 z-40" onClick={() => setOpen(false)} aria-hidden />
          <div className="animate-fade-up absolute right-0 z-50 mt-2 w-72 rounded-xl border border-hairline bg-surface p-2 shadow-[var(--shadow-overlay)]">
            {presets.map((preset) => (
              <button
                key={preset.label}
                type="button"
                onClick={() => {
                  onChange(preset);
                  setOpen(false);
                }}
                className={cn(
                  "flex w-full items-center justify-between rounded-md px-3 py-2 text-left text-sm transition hover:bg-brand-50",
                  preset.label === period.label && "bg-brand-50 font-medium text-brand-800",
                )}
              >
                {preset.label}
                <span className="text-xs text-ink-500">{formatDate(preset.from)}</span>
              </button>
            ))}

            <div className="mt-2 border-t border-hairline p-2">
              <p className="sasa-eyebrow mb-2">Custom range</p>
              <div className="grid grid-cols-2 gap-2">
                <label className="block">
                  <span className="mb-1 block text-xs text-ink-500">From</span>
                  <input
                    type="date"
                    value={period.from}
                    max={period.to}
                    onChange={(event) =>
                      onChange({ ...period, from: event.target.value, label: "Custom range" })
                    }
                    className="sasa-field px-2 py-1.5 text-sm"
                  />
                </label>
                <label className="block">
                  <span className="mb-1 block text-xs text-ink-500">To</span>
                  <input
                    type="date"
                    value={period.to}
                    min={period.from}
                    onChange={(event) => onChange({ ...period, to: event.target.value, label: "Custom range" })}
                    className="sasa-field px-2 py-1.5 text-sm"
                  />
                </label>
              </div>
            </div>
          </div>
        </>
      ) : null}
    </div>
  );
}
