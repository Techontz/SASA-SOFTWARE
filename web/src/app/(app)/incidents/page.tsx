"use client";

import { Download, Plus, Settings2, Siren } from "lucide-react";
import Link from "next/link";
import { Suspense } from "react";
import { ChartCard, GroupedBarChart, HorizontalBarChart } from "@/components/charts/Charts";
import { IncidentStatusBadge, SeriousBadge } from "@/components/incidents/IncidentBadges";
import { Button } from "@/components/ui/Button";
import { DataTable, type Column } from "@/components/ui/DataTable";
import { PageHeader } from "@/components/ui/DetailLayout";
import { FilterBar } from "@/components/ui/FilterBar";
import { KpiCard, KpiCardSkeleton } from "@/components/ui/KpiCard";
import { EmptyState, ErrorState, PermissionDenied, TableSkeleton } from "@/components/ui/States";
import { useListState } from "@/hooks/useListState";
import { downloadFile } from "@/lib/api/client";
import { useIncidentOptions, useIncidents, useIncidentSummary } from "@/lib/api/incidents";
import { formatDateTime, truncate } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";
import { useToast } from "@/providers/ToastProvider";
import type { Kpi } from "@/types/api";
import type { Incident, IncidentSummary } from "@/types/incident";

export default function IncidentsPage() {
  return (
    <Suspense fallback={<TableSkeleton />}>
      <IncidentRegister />
    </Suspense>
  );
}

function kpi(key: string, label: string, value: number | null, definition: string, unit: Kpi["unit"] = "count"): Kpi {
  return { key, label, value, definition, unit, delta_percent: null, context: {}, drill: null };
}

function SummaryPanel({ summary }: { summary: IncidentSummary }) {
  const cards: Array<{ kpi: Kpi; href: string | null }> = [
    { kpi: kpi("total", "Incidents reported", summary.total, "Every incident notification on this project, archived records excluded."), href: null },
    { kpi: kpi("open", "Awaiting closure", summary.open, "Submitted or under review — not yet closed."), href: "/incidents?status=submitted" },
    { kpi: kpi("serious", "Serious", summary.serious, summary.definitions.serious), href: "/incidents?serious=1" },
    { kpi: kpi("last_30_days", "Last 30 days", summary.last_30_days, summary.definitions.last_30_days), href: null },
    {
      kpi: kpi("near_miss_ratio", "Near-miss ratio", summary.near_miss_ratio, summary.definitions.near_miss_ratio, "percent"),
      href: "/incidents?category=near_miss",
    },
  ];

  const consequences = summary.by_actual_consequence.map((entry) => ({
    level: `L${entry.level}`,
    actual: entry.count,
    potential: summary.by_potential_consequence.find((p) => p.level === entry.level)?.count ?? 0,
  }));

  return (
    <>
      <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-5">
        {cards.map((card) => (
          <KpiCard key={card.kpi.key} kpi={card.kpi} href={card.href} />
        ))}
      </div>

      {summary.serious_open > 0 ? (
        <div className="mb-4 flex flex-wrap items-center gap-3 rounded-xl border border-danger-500/25 bg-danger-50 p-4">
          <SeriousBadge />
          <p className="min-w-0 flex-1 text-sm text-danger-800">
            {summary.serious_open === 1
              ? "1 serious incident is still open."
              : `${summary.serious_open} serious incidents are still open.`}
          </p>
          <Link href="/incidents?serious=1" className="text-sm font-medium text-danger-800 underline underline-offset-2">
            Show them
          </Link>
        </div>
      ) : null}

      <div className="mb-6 grid gap-4 lg:grid-cols-2">
        <ChartCard
          title="By category"
          definition="Incidents reported in each category of the notification form."
          empty={summary.total === 0}
        >
          <HorizontalBarChart
            data={summary.by_category.map((entry) => ({ label: entry.label.replace("Incident – ", ""), count: entry.count }))}
            labelKey="label"
            valueKey="count"
            height={260}
          />
        </ChartCard>
        <ChartCard
          title="Actual and potential consequence"
          definition="Level 0 Near Miss to Level 5 Catastrophic. The gap between the two is what nearly happened."
          empty={summary.total === 0}
        >
          <GroupedBarChart
            data={consequences}
            labelKey="level"
            series={[
              { key: "actual", label: "Actual" },
              { key: "potential", label: "Potential" },
            ]}
            height={260}
          />
        </ChartCard>
      </div>
    </>
  );
}

function IncidentRegister() {
  const { can } = useSession();
  const toast = useToast();
  const state = useListState({ sort: "-occurred_at" });
  const allowed = can("incident.view");
  const { data, isLoading, isError, error, refetch } = useIncidents(state.query, { enabled: allowed });
  const summary = useIncidentSummary();
  const { data: options } = useIncidentOptions();

  if (!allowed) return <PermissionDenied what="incident notifications" />;

  const rows = data?.data ?? [];
  const meta = data?.meta;

  const columns: Column<Incident>[] = [
    {
      key: "summary",
      header: "Incident",
      width: "42%",
      cell: (row) => (
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <span className="font-mono text-xs text-ink-500">{row.reference}</span>
            {row.is_serious ? <SeriousBadge size="sm" /> : null}
          </div>
          <p className="mt-0.5 font-medium text-ink-900">{row.incident_type_label}</p>
          <p className="mt-0.5 text-sm text-ink-600">{truncate(row.summary, 110)}</p>
        </div>
      ),
    },
    {
      key: "occurred_at",
      header: "When",
      sortable: true,
      cell: (row) => <span className="whitespace-nowrap text-ink-700">{formatDateTime(row.occurred_at)}</span>,
    },
    {
      key: "potential_consequence",
      header: "Potential",
      sortable: true,
      hideBelow: "lg",
      cell: (row) => <span className="text-ink-700">{row.potential_consequence_label}</span>,
    },
    { key: "status", header: "Status", sortable: true, cell: (row) => <IncidentStatusBadge status={row.status} size="sm" /> },
    {
      key: "company",
      header: "Company",
      hideBelow: "xl",
      cell: (row) => <span className="text-ink-700">{row.company ?? "—"}</span>,
    },
  ];

  return (
    <div>
      <PageHeader
        eyebrow="Safeguards"
        title="Incidents"
        description="Incident notification reports from the field — what happened, how bad it was, how bad it could have been — and their review to closure."
        actions={
          <>
            {can("configuration.manage") ? (
              <Link href="/incidents/settings">
                <Button variant="ghost" icon={<Settings2 className="h-4 w-4" />}>
                  Lists
                </Button>
              </Link>
            ) : null}
            <Button
              variant="secondary"
              icon={<Download className="h-4 w-4" />}
              onClick={async () => {
                try {
                  await downloadFile("/incidents/export", "sasa-incidents.xlsx", { ...state.query, format: "xlsx" });
                  toast.success("Export ready");
                } catch {
                  toast.error("We could not prepare that export");
                }
              }}
            >
              Export
            </Button>
            {can("incident.report") ? (
              <Link href="/incidents/new">
                <Button variant="accent" icon={<Plus className="h-4 w-4" />}>
                  Report an incident
                </Button>
              </Link>
            ) : null}
          </>
        }
      />

      {summary.data ? (
        <SummaryPanel summary={summary.data.data} />
      ) : summary.isLoading ? (
        <div className="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-5">
          {Array.from({ length: 5 }).map((_, index) => (
            <KpiCardSkeleton key={index} />
          ))}
        </div>
      ) : null}

      <div className="mb-4">
        <FilterBar
          search={state.search}
          onSearchChange={state.setSearch}
          searchPlaceholder="Search incidents"
          filters={[
            {
              key: "status",
              label: "Status",
              options: [
                { value: "submitted", label: "Submitted" },
                { value: "under_review", label: "Under review" },
                { value: "closed", label: "Closed" },
              ],
            },
            {
              key: "category",
              label: "Category",
              options: (options?.data.categories ?? []).map((category) => ({ value: category.key, label: category.label })),
            },
            {
              key: "potential_consequence",
              label: "Potential consequence",
              options: (options?.data.consequence_levels ?? []).map((level) => ({ value: String(level.level), label: level.label })),
            },
            { key: "serious", label: "Serious only", options: [{ value: "1", label: "Serious" }] },
            {
              key: "company",
              label: "Company",
              options: (options?.data.companies ?? []).map((company) => ({ value: company, label: company })),
            },
          ]}
          values={state.filterValues}
          onChange={state.setFilter}
          onClear={state.clear}
        />
      </div>

      {isError ? (
        <div className="sasa-card">
          <ErrorState error={error} onRetry={() => void refetch()} />
        </div>
      ) : isLoading ? (
        <div className="sasa-card">
          <TableSkeleton columns={5} />
        </div>
      ) : (
        <DataTable
          rows={rows}
          columns={columns}
          rowKey={(row) => row.id}
          href={(row) => `/incidents/${row.id}`}
          sort={state.sort}
          onSortChange={state.setSort}
          emptyState={
            <EmptyState
              icon={Siren}
              title="No incidents match"
              description="Incident notifications appear here as soon as they are submitted from the field."
              action={
                can("incident.report") ? (
                  <Link href="/incidents/new">
                    <Button variant="accent" icon={<Plus className="h-4 w-4" />}>
                      Report an incident
                    </Button>
                  </Link>
                ) : undefined
              }
            />
          }
          mobileCard={(row) => (
            <div>
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <p className="font-mono text-xs text-ink-500">{row.reference}</p>
                  <p className="mt-0.5 font-medium leading-snug text-ink-900">{row.incident_type_label}</p>
                </div>
                <IncidentStatusBadge status={row.status} size="sm" />
              </div>
              <p className="mt-1 text-sm text-ink-600">{truncate(row.summary, 100)}</p>
              <div className="mt-2 flex flex-wrap items-center gap-2">
                {row.is_serious ? <SeriousBadge size="sm" /> : null}
                <span className="text-sm text-ink-600">{formatDateTime(row.occurred_at)}</span>
              </div>
            </div>
          )}
          pagination={
            meta
              ? {
                  currentPage: meta.current_page,
                  lastPage: meta.last_page,
                  total: meta.total,
                  from: meta.from,
                  to: meta.to,
                  onPageChange: state.setPage,
                }
              : undefined
          }
        />
      )}
    </div>
  );
}
