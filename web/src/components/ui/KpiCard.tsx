"use client";

import { ArrowDownRight, ArrowUpRight, Info, Minus } from "lucide-react";
import Link from "next/link";
import { cn, formatMetric, metricSuffix } from "@/lib/utils";
import type { Kpi } from "@/types/api";

/**
 * A KPI is large, readable and clickable. Every one carries its definition —
 * hover or focus the marker and the number explains itself, which is what
 * stops two people reading the same figure two different ways.
 */
export function KpiCard({
  kpi,
  href,
  emphasis = "normal",
  className,
}: {
  kpi: Kpi;
  href?: string | null;
  emphasis?: "normal" | "primary";
  className?: string;
}) {
  const delta = kpi.delta_percent;
  const suffix = metricSuffix(kpi.unit);

  const DeltaIcon = delta === null ? Minus : delta > 0 ? ArrowUpRight : delta < 0 ? ArrowDownRight : Minus;

  /* More grievances is not automatically worse — it often means the mechanism
     is trusted — so the delta is coloured neutrally and simply reported. */
  /*
   * The cards in a row are stretched to the tallest by the grid, so the number
   * is pushed to the bottom of whatever height that turns out to be. Every
   * figure in the row then sits on one line, instead of each floating at the
   * top of its own card above a pool of empty space.
   */
  const body = (
    <>
      <div className="flex items-start justify-between gap-2">
        <p className="sasa-eyebrow leading-tight">{kpi.label}</p>
        <span
          className="group/def relative -m-1 shrink-0 rounded p-1 text-ink-400 transition hover:text-ink-700"
          tabIndex={0}
          role="note"
          aria-label={`How this is measured: ${kpi.definition}`}
        >
          <Info className="h-3.5 w-3.5" aria-hidden />
          <span className="pointer-events-none absolute right-0 top-full z-30 mt-2 hidden w-64 rounded-lg border border-chrome-line bg-chrome p-3 text-xs leading-relaxed font-normal normal-case tracking-normal text-chrome-fg shadow-[var(--shadow-overlay)] group-hover/def:block group-focus/def:block">
            {kpi.definition}
          </span>
        </span>
      </div>

      <div className="mt-auto flex items-baseline gap-1.5 pt-4">
        <span
          className={cn(
            "tabular font-semibold tracking-tight text-ink-900",
            emphasis === "primary" ? "text-[2.5rem] leading-none" : "text-[2rem] leading-none",
            kpi.value === null && "text-ink-400",
          )}
        >
          {formatMetric(kpi.value, kpi.unit)}
        </span>
        {suffix && kpi.value !== null ? (
          <span className="text-sm font-medium text-ink-500">{suffix}</span>
        ) : null}
      </div>

      <div className="mt-2 flex min-h-[1.25rem] flex-wrap items-center gap-x-3 gap-y-1 pr-24 text-xs text-ink-500">
        {delta !== null ? (
          <span className="inline-flex items-center gap-1">
            <DeltaIcon className="h-3.5 w-3.5" aria-hidden />
            {Math.abs(delta)}% vs the period before
          </span>
        ) : null}
        {Object.entries(kpi.context ?? {}).map(([key, value]) => (
          <span key={key} className="tabular">
            {key.replace(/_/g, " ")}: {String(value)}
          </span>
        ))}
      </div>
    </>
  );

  const target = href ?? (kpi.drill ? `/${kpi.drill}` : null);

  if (!target) {
    return <div className={cn("sasa-card flex flex-col p-5", className)}>{body}</div>;
  }

  return (
    <Link
      href={target}
      className={cn(
        "sasa-card group relative flex flex-col p-5 transition-all duration-[var(--duration-base)] hover:-translate-y-0.5 hover:border-brand-300 hover:shadow-[var(--shadow-raised)]",
        className,
      )}
    >
      {body}
      {/* Absolute, so revealing it on hover cannot change the card's height —
          and cannot shift the row every card in it belongs to. */}
      <span className="pointer-events-none absolute bottom-4 right-5 inline-flex items-center gap-1 text-xs font-medium text-brand-700 opacity-0 transition group-hover:opacity-100 group-focus-visible:opacity-100">
        See the records
        <ArrowUpRight className="h-3.5 w-3.5" aria-hidden />
      </span>
    </Link>
  );
}

export function KpiCardSkeleton() {
  return (
    <div className="sasa-card p-5">
      <div className="sasa-skeleton h-3 w-24" />
      <div className="sasa-skeleton mt-4 h-9 w-20" />
      <div className="sasa-skeleton mt-3 h-3 w-32" />
    </div>
  );
}
