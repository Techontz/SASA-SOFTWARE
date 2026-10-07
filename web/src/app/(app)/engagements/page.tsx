"use client";

import { CalendarClock, Download, Plus } from "lucide-react";
import Link from "next/link";
import { Suspense } from "react";
import { EngagementNav } from "@/components/app/EngagementNav";
import { SavedViewBar } from "@/components/app/SavedViewBar";
import { Button } from "@/components/ui/Button";
import { DataTable, type Column } from "@/components/ui/DataTable";
import { PageHeader } from "@/components/ui/DetailLayout";
import { FilterBar } from "@/components/ui/FilterBar";
import { StatusBadge } from "@/components/ui/StatusBadge";
import { EmptyState, ErrorState, TableSkeleton } from "@/components/ui/States";
import { useListState } from "@/hooks/useListState";
import { downloadFile } from "@/lib/api/client";
import { useConfiguration, useEngagements } from "@/lib/api/hooks";
import { formatDate, formatNumber, humanise } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";
import { useToast } from "@/providers/ToastProvider";
import type { Engagement } from "@/types/api";

export default function EngagementsPage() {
  return (
    <Suspense fallback={<TableSkeleton />}>
      <EngagementList />
    </Suspense>
  );
}

function EngagementList() {
  const { can } = useSession();
  const toast = useToast();
  const state = useListState({ sort: "-held_at" });
  const { data, isLoading, isError, error, refetch } = useEngagements(state.query);
  const { data: configuration } = useConfiguration();

  const rows = data?.data ?? [];
  const meta = data?.meta;
  const summary = meta?.summary ?? {};

  const columns: Column<Engagement>[] = [
    {
      key: "topic",
      header: "Engagement",
      sortable: true,
      width: "34%",
      cell: (row) => (
        <div className="min-w-0">
          <span className="font-mono text-xs text-ink-500">{row.reference}</span>
          <p className="mt-0.5 truncate font-medium text-ink-900">{row.topic}</p>
          <p className="mt-0.5 truncate text-xs text-ink-500">
            {humanise(row.method)} · {row.location.path ?? row.location.text ?? "Location not recorded"}
          </p>
        </div>
      ),
    },
    {
      key: "held_at",
      header: "Held",
      sortable: true,
      cell: (row) => <span className="whitespace-nowrap text-ink-700">{formatDate(row.held_at)}</span>,
    },
    {
      key: "planned_vs_actual",
      header: "Against the plan",
      cell: (row) => (
        <span className="inline-flex flex-wrap items-center gap-1.5">
          <StatusBadge status={row.planned_vs_actual} label={row.planned_vs_actual_label} size="sm" />
          {row.variance_days ? (
            <span className="text-xs text-ink-500">
              {row.variance_days > 0 ? `${row.variance_days}d late` : `${Math.abs(row.variance_days)}d early`}
            </span>
          ) : null}
        </span>
      ),
    },
    {
      key: "attendance_total",
      header: "Attended",
      sortable: true,
      align: "right",
      hideBelow: "lg",
      cell: (row) => (
        <span className="tabular text-ink-700">
          {formatNumber(row.attendance.total)}
          {row.attendance.female ? (
            <span className="ml-1 text-xs text-ink-500">({row.attendance.female} f)</span>
          ) : null}
        </span>
      ),
    },
    {
      key: "concerns",
      header: "Raised",
      hideBelow: "lg",
      cell: (row) => (
        <span className="flex flex-wrap gap-1.5 text-xs">
          {row.counts?.concerns ? (
            <span className="rounded-full bg-warning-50 px-2 py-0.5 text-warning-700">
              {row.counts.concerns} {row.counts.concerns === 1 ? "concern" : "concerns"}
            </span>
          ) : null}
          {row.counts?.commitments ? (
            <span className="rounded-full bg-brand-50 px-2 py-0.5 text-brand-700">
              {row.counts.commitments} {row.counts.commitments === 1 ? "commitment" : "commitments"}
            </span>
          ) : null}
          {!row.counts?.concerns && !row.counts?.commitments ? <span className="text-ink-400">—</span> : null}
        </span>
      ),
    },
  ];

  const methods = (configuration?.data.lists.engagement_methods ?? []).map((value) => ({
    value,
    label: humanise(value),
  }));

  return (
    <div>
      <PageHeader
        eyebrow="Module 2 · Stakeholder management"
        title="Engagements"
        description="Every consultation, meeting and disclosure, lodged as it happens — with a planned-versus-actual flag on each one."
        actions={
          <>
            <Button
              variant="secondary"
              icon={<Download className="h-4 w-4" />}
              onClick={async () => {
                try {
                  await downloadFile("/engagements/export", "sasa-engagements.xlsx", { ...state.query, format: "xlsx" });
                  toast.success("Export ready");
                } catch {
                  toast.error("We could not prepare that export");
                }
              }}
            >
              Export
            </Button>
            {can("engagement.log") ? (
              <Link href="/engagements/log">
                <Button variant="accent" icon={<Plus className="h-4 w-4" />}>
                  Log an engagement
                </Button>
              </Link>
            ) : null}
          </>
        }
      />

      <EngagementNav />

      <dl className="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-5">
        {[
          ["Logged", summary.total],
          ["On plan", summary.on_plan],
          ["Late", summary.late],
          ["Unplanned", summary.unplanned],
          ["People reached", summary.total_attendance],
        ].map(([label, value]) => (
          <div key={String(label)} className="sasa-card px-4 py-3">
            <dt className="sasa-eyebrow">{label}</dt>
            <dd className="tabular mt-1 text-2xl font-semibold text-ink-900">{formatNumber(value as number)}</dd>
          </div>
        ))}
      </dl>

      <SavedViewBar entity="engagements" filters={state.filterValues} onApply={state.applyView} />

      <div className="mb-4">
        <FilterBar
          search={state.search}
          onSearchChange={state.setSearch}
          searchPlaceholder="Search by topic, reference or place"
          filters={[
            { key: "method", label: "Method", options: methods },
            {
              key: "planned_vs_actual",
              label: "Against the plan",
              options: [
                { value: "on_plan", label: "On plan" },
                { value: "late", label: "Late" },
                { value: "early", label: "Early" },
                { value: "unplanned", label: "Unplanned" },
              ],
            },
            {
              key: "project_phase",
              label: "Phase",
              options: (configuration?.data.lists.project_phases ?? []).map((value) => ({ value, label: humanise(value) })),
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
          href={(row) => `/engagements/${row.id}`}
          sort={state.sort}
          onSortChange={state.setSort}
          emptyState={
            <EmptyState
              icon={CalendarClock}
              title="Nothing logged yet"
              description="When a meeting, focus group or household visit happens, record it here — with who attended, what was said, and what was promised."
              action={
                can("engagement.log") ? (
                  <Link href="/engagements/log">
                    <Button variant="accent" icon={<Plus className="h-4 w-4" />}>
                      Log the first engagement
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
                  <p className="mt-0.5 font-medium leading-snug text-ink-900">{row.topic}</p>
                </div>
                <StatusBadge status={row.planned_vs_actual} label={row.planned_vs_actual_label} size="sm" />
              </div>
              <p className="mt-2 text-sm text-ink-600">
                {formatDate(row.held_at)} · {humanise(row.method)} · {formatNumber(row.attendance.total)} attended
              </p>
              <p className="mt-1 text-sm text-ink-500">{row.location.path ?? row.location.text}</p>
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
