"use client";

import { Download, HardHat, Plus, Settings2 } from "lucide-react";
import Link from "next/link";
import { Suspense } from "react";
import { EshsOverdueBadge, EshsRiskBadge, EshsStageBadge } from "@/components/eshs/EshsBadges";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { DataTable, type Column } from "@/components/ui/DataTable";
import { PageHeader } from "@/components/ui/DetailLayout";
import { FilterBar } from "@/components/ui/FilterBar";
import { KpiCard, KpiCardSkeleton } from "@/components/ui/KpiCard";
import { EmptyState, ErrorState, PermissionDenied, TableSkeleton } from "@/components/ui/States";
import { useListState } from "@/hooks/useListState";
import { downloadFile } from "@/lib/api/client";
import { useEshsAnomalies, useEshsOptions, useEshsSummary } from "@/lib/api/eshs";
import { formatRate, targetLabel } from "@/lib/eshs";
import { formatDate, truncate } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";
import { useToast } from "@/providers/ToastProvider";
import type { EshsAnomaly, EshsSummary } from "@/types/eshs";

export default function EshsPage() {
  return (
    <Suspense fallback={<TableSkeleton />}>
      <EshsRegister />
    </Suspense>
  );
}

function EshsRegister() {
  const { can } = useSession();
  const toast = useToast();
  const state = useListState({ sort: "-raised_on" });
  const { data, isLoading, isError, error, refetch } = useEshsAnomalies(state.query, { enabled: can("eshs.view") });
  const { data: options } = useEshsOptions();
  const summaryQuery = { ...state.query, page: undefined, sort: undefined, stage: undefined, overdue: undefined, status: undefined };
  const summary = useEshsSummary(summaryQuery, can("eshs.view"));

  if (!can("eshs.view")) return <PermissionDenied what="ESHS contractor assurance" />;

  const rows = data?.data ?? [];
  const meta = data?.meta;
  const lists = options?.data;

  const columns: Column<EshsAnomaly>[] = [
    {
      key: "reference",
      header: "Anomaly",
      width: "38%",
      sortable: true,
      cell: (row) => (
        <div className="min-w-0">
          <span className="font-mono text-xs text-ink-500">{row.reference}</span>
          <p className="mt-0.5 text-ink-900">{truncate(row.description, 120)}</p>
          <p className="mt-0.5 text-xs text-ink-500">
            {row.category_label} · {row.subcategory} · {row.anomaly_type}
          </p>
        </div>
      ),
    },
    {
      key: "contractor",
      header: "Contractor",
      sortable: true,
      hideBelow: "lg",
      cell: (row) => (
        <div className="min-w-0">
          <p className="text-ink-800">{row.contractor}</p>
          {row.subcontractor ? <p className="text-xs text-ink-500">via {row.subcontractor}</p> : null}
        </div>
      ),
    },
    { key: "risk_rating", header: "Risk", sortable: true, cell: (row) => <EshsRiskBadge risk={row.risk_rating} label={row.risk_label} size="sm" /> },
    {
      key: "status",
      header: "Status",
      sortable: true,
      cell: (row) => (
        <div className="flex flex-wrap gap-1">
          <EshsStageBadge stage={row.stage} label={row.stage_label} size="sm" />
          {row.is_overdue ? <EshsOverdueBadge size="sm" /> : null}
        </div>
      ),
    },
    {
      key: "target_date",
      header: "Target",
      sortable: true,
      hideBelow: "xl",
      cell: (row) => (
        <div className="whitespace-nowrap">
          <p className={row.is_overdue ? "font-medium text-danger-700" : "text-ink-700"}>{formatDate(row.target_date)}</p>
          {targetLabel(row.days_until_target, row.status === "closed") ? (
            <p className={row.is_overdue ? "text-xs text-danger-700" : "text-xs text-ink-500"}>
              {targetLabel(row.days_until_target, row.status === "closed")}
            </p>
          ) : null}
        </div>
      ),
    },
    {
      key: "raised_on",
      header: "Raised",
      sortable: true,
      hideBelow: "xl",
      cell: (row) => <span className="whitespace-nowrap text-ink-700">{formatDate(row.raised_on)}</span>,
    },
  ];

  return (
    <div>
      <PageHeader
        eyebrow="Safeguards"
        title="ESHS contractor assurance"
        description="Environmental, safety, health and social anomalies raised by the client and supervision team. The contractor uploads closure evidence; only the supervision team verifies and closes."
        actions={
          <>
            {can("configuration.manage") ? (
              <Link href="/eshs/settings">
                <Button variant="secondary" icon={<Settings2 className="h-4 w-4" />}>
                  Contractor lists
                </Button>
              </Link>
            ) : null}
            <Button
              variant="secondary"
              icon={<Download className="h-4 w-4" />}
              onClick={async () => {
                try {
                  await downloadFile("/eshs/export", "sasa-eshs-anomalies.xlsx", { ...state.query, format: "xlsx" });
                  toast.success("Export ready");
                } catch {
                  toast.error("We could not prepare that export");
                }
              }}
            >
              Export
            </Button>
            {can("eshs.raise") ? (
              <Link href="/eshs/new">
                <Button variant="accent" icon={<Plus className="h-4 w-4" />}>
                  Raise an anomaly
                </Button>
              </Link>
            ) : null}
          </>
        }
      />

      <Dashboard summary={summary.data?.data} loading={summary.isLoading} />

      <div className="mb-4">
        <FilterBar
          search={state.search}
          onSearchChange={state.setSearch}
          searchPlaceholder="Search anomalies"
          filters={[
            {
              key: "stage",
              label: "Status",
              options: [
                { value: "not_closed", label: "Not closed" },
                { value: "open", label: "Open" },
                { value: "in_progress", label: "In progress" },
                { value: "awaiting_verification", label: "Awaiting verification" },
                { value: "closed", label: "Closed" },
              ],
            },
            { key: "overdue", label: "Overdue only", options: [{ value: "1", label: "Overdue" }] },
            {
              key: "risk_rating",
              label: "Risk",
              options: (lists?.risk_ratings ?? []).map((risk) => ({ value: risk.key, label: risk.label })),
            },
            {
              key: "category",
              label: "Category",
              options: (lists?.categories ?? []).map((category) => ({ value: category.key, label: category.label })),
            },
            {
              key: "contractor",
              label: "Contractor",
              options: (lists?.contractors ?? []).map((contractor) => ({ value: contractor, label: contractor })),
            },
            {
              key: "anomaly_type",
              label: "Type",
              options: (lists?.anomaly_types ?? []).map((type) => ({ value: type, label: type })),
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
          href={(row) => `/eshs/${row.id}`}
          sort={state.sort}
          onSortChange={state.setSort}
          emptyState={
            <EmptyState
              icon={HardHat}
              title="No anomalies match"
              description="Anomalies raised by the client or the supervision team appear here, with the contractor's response and the closure evidence."
              action={
                can("eshs.raise") ? (
                  <Link href="/eshs/new">
                    <Button variant="accent" icon={<Plus className="h-4 w-4" />}>
                      Raise an anomaly
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
                  <p className="mt-0.5 leading-snug text-ink-900">{truncate(row.description, 110)}</p>
                </div>
              </div>
              <div className="mt-2 flex flex-wrap items-center gap-1.5">
                <EshsStageBadge stage={row.stage} label={row.stage_label} size="sm" />
                <EshsRiskBadge risk={row.risk_rating} label={row.risk_label} size="sm" />
                {row.is_overdue ? <EshsOverdueBadge size="sm" /> : null}
              </div>
              <p className="mt-1.5 text-sm text-ink-600">
                {row.contractor} · {row.subcategory}
              </p>
              {row.target_date && row.status !== "closed" ? (
                <p className={row.is_overdue ? "text-sm font-medium text-danger-700" : "text-sm text-ink-500"}>
                  Target {formatDate(row.target_date)}
                </p>
              ) : null}
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

function Dashboard({ summary, loading }: { summary: EshsSummary | undefined; loading: boolean }) {
  if (loading || !summary) {
    return (
      <div className="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-5">
        {Array.from({ length: 5 }).map((_, index) => (
          <KpiCardSkeleton key={index} />
        ))}
      </div>
    );
  }

  const maxCategory = Math.max(1, ...summary.by_category.map((row) => row.total));

  return (
    <div className="mb-6 space-y-4">
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
        {summary.kpis.map((kpi) => (
          <KpiCard key={kpi.key} kpi={kpi} className="min-w-0" />
        ))}
      </div>

      <div className="grid gap-4 lg:grid-cols-[1fr_2fr]">
        <Card className="min-w-0">
          <CardHeader title="Anomalies by category" description="Raised, and how many are still open." />
          <CardBody>
            <ul className="space-y-3">
              {summary.by_category.map((row) => (
                <li key={row.category}>
                  <div className="flex items-baseline justify-between gap-3 text-sm">
                    <span className="font-medium text-ink-800">{row.label}</span>
                    <span className="tabular text-ink-600">
                      {row.total} raised · {row.open} open
                    </span>
                  </div>
                  <div className="mt-1.5 h-2 overflow-hidden rounded-full bg-ink-100" aria-hidden>
                    <div className="h-full rounded-full bg-brand-500" style={{ width: `${(row.total / maxCategory) * 100}%` }} />
                  </div>
                </li>
              ))}
            </ul>
          </CardBody>
        </Card>

        <Card className="min-w-0">
          <CardHeader
            title="Contractor performance"
            description="Closure rate is closed ÷ raised. Average days to close counts calendar days from raised to closed."
          />
          <CardBody className="p-0 sm:p-0">
            {summary.contractor_performance.length === 0 ? (
              <p className="px-5 py-6 text-sm text-ink-500">No anomalies raised yet.</p>
            ) : (
              <div className="sasa-scroll-x overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-hairline bg-surface-sunken">
                      {["Contractor", "Raised", "Open", "Overdue", "Closed", "Closure rate", "Avg days to close"].map((heading) => (
                        <th
                          key={heading}
                          scope="col"
                          className="whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-500"
                        >
                          {heading}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-hairline">
                    {summary.contractor_performance.map((row) => (
                      <tr key={row.contractor}>
                        <th scope="row" className="px-4 py-3 text-left font-medium text-ink-900">
                          <Link href={`/eshs?contractor=${encodeURIComponent(row.contractor)}`} className="hover:text-brand-700 hover:underline">
                            {row.contractor}
                          </Link>
                          {row.critical_open > 0 ? (
                            <span className="ml-2 inline-block">
                              <EshsRiskBadge risk="critical" label={`${row.critical_open} critical open`} withRiskWord={false} size="sm" />
                            </span>
                          ) : null}
                        </th>
                        <td className="tabular px-4 py-3 text-ink-700">{row.raised}</td>
                        <td className="tabular px-4 py-3 text-ink-700">{row.open}</td>
                        <td className={row.overdue > 0 ? "tabular px-4 py-3 font-medium text-danger-700" : "tabular px-4 py-3 text-ink-700"}>
                          {row.overdue}
                        </td>
                        <td className="tabular px-4 py-3 text-ink-700">{row.closed}</td>
                        <td className="tabular px-4 py-3 text-ink-700">{formatRate(row.closure_rate)}</td>
                        <td className="tabular px-4 py-3 text-ink-700">{row.avg_days_to_close ?? "—"}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </CardBody>
        </Card>
      </div>
    </div>
  );
}
