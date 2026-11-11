"use client";

import { Info } from "lucide-react";
import Link from "next/link";
import { KpiCardSkeleton } from "@/components/ui/KpiCard";
import { formatMoney, formatMoneyCompact } from "@/lib/pap/format";
import { formatNumber } from "@/lib/utils";
import type { PapKpi, PapSummary } from "@/types/pap";

/**
 * The PAP register's headline figures. Money is shown compact on the tile
 * ("TZS 12.1M") with the exact figure beneath, so a phone never scrolls
 * sideways; every tile carries its definition.
 */
export function PapKpis({ summary, loading }: { summary?: PapSummary; loading?: boolean }) {
  if (loading || !summary) {
    return (
      <div className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4">
        {Array.from({ length: 8 }, (_, index) => (
          <KpiCardSkeleton key={index} />
        ))}
      </div>
    );
  }

  return (
    <dl className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4">
      {summary.kpis.map((kpi) => (
        <PapKpiTile key={kpi.key} kpi={kpi} currency={summary.currency} />
      ))}
    </dl>
  );
}

function PapKpiTile({ kpi, currency }: { kpi: PapKpi; currency: string }) {
  const value =
    kpi.value === null
      ? "—"
      : kpi.unit === "currency"
        ? formatMoneyCompact(kpi.value, currency)
        : kpi.unit === "percent"
          ? `${kpi.value}%`
          : formatNumber(kpi.value);

  const body = (
    <div className="sasa-card flex h-full min-w-0 flex-col px-4 py-3 transition hover:border-brand-300">
      <div className="flex items-start justify-between gap-2">
        <dt className="sasa-eyebrow leading-tight">{kpi.label}</dt>
        <span
          className="group/def relative -m-1 shrink-0 rounded p-1 text-ink-400 transition hover:text-ink-700"
          tabIndex={0}
          role="note"
          aria-label={`How this is measured: ${kpi.definition}`}
        >
          <Info className="h-3.5 w-3.5" aria-hidden />
          <span className="pointer-events-none absolute right-0 top-full z-30 mt-2 hidden w-56 rounded-lg border border-chrome-line bg-chrome p-3 text-xs font-normal normal-case leading-relaxed tracking-normal text-chrome-fg shadow-[var(--shadow-overlay)] group-hover/def:block group-focus/def:block">
            {kpi.definition}
          </span>
        </span>
      </div>
      <dd className="mt-auto pt-3">
        <span className="tabular block break-words text-2xl font-semibold text-ink-900">{value}</span>
        {kpi.unit === "currency" && kpi.value !== null ? (
          <span className="tabular mt-0.5 block break-words text-xs text-ink-500">{formatMoney(kpi.value, currency)}</span>
        ) : null}
        {Object.keys(kpi.context).length > 0 ? (
          <span className="mt-0.5 block text-xs text-ink-500">
            {Object.entries(kpi.context)
              .map(([key, contextValue]) => `${key.replace(/_/g, " ")}: ${contextValue}`)
              .join(" · ")}
          </span>
        ) : null}
      </dd>
    </div>
  );

  return <div className="min-w-0">{kpi.drill ? <Link href={kpi.drill}>{body}</Link> : body}</div>;
}
