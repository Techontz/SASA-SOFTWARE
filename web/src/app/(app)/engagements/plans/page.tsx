"use client";

import { CalendarClock, Plus } from "lucide-react";
import Link from "next/link";
import { Suspense } from "react";
import { EngagementNav } from "@/components/app/EngagementNav";
import { Button } from "@/components/ui/Button";
import { DataTable, type Column } from "@/components/ui/DataTable";
import { PageHeader } from "@/components/ui/DetailLayout";
import { FilterBar } from "@/components/ui/FilterBar";
import { PriorityBadge, StatusBadge } from "@/components/ui/StatusBadge";
import { EmptyState, ErrorState, TableSkeleton } from "@/components/ui/States";
import { useListState } from "@/hooks/useListState";
import { useConfiguration, useEngagementPlans } from "@/lib/api/hooks";
import { daysBetween, formatDate, humanise } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";
import type { EngagementPlan } from "@/types/api";

export default function EngagementPlansPage() {
  return (
    <Suspense fallback={<TableSkeleton />}>
      <PlanList />
    </Suspense>
  );
}

function PlanList() {
  const { can } = useSession();
  const state = useListState({ sort: "target_date" });
  const { data, isLoading, isError, refetch } = useEngagementPlans(state.query);
  const { data: configuration } = useConfiguration();

  const rows = data?.data ?? [];
  const meta = data?.meta;
  const summary = meta?.summary ?? {};

  const columns: Column<EngagementPlan>[] = [
    {
      key: "title",
      header: "Planned engagement",
      sortable: true,
      width: "34%",
      cell: (row) => (
        <div className="min-w-0">
          <span className="font-mono text-xs text-ink-500">{row.reference}</span>
          <p className="mt-0.5 truncate font-medium text-ink-900">{row.title}</p>
          <p className="mt-0.5 truncate text-xs text-ink-500">
            {row.stakeholder?.name ?? row.stakeholder_group ?? "No stakeholder set"} · {humanise(row.method)}
          </p>
        </div>
      ),
    },
    {
      key: "target_date",
      header: "Planned for",
      sortable: true,
      cell: (row) => {
        const days = daysBetween(row.target_date);

        return (
          <div className="whitespace-nowrap">
            <p className="text-ink-800">{formatDate(row.target_date)}</p>
            {row.status === "planned" && days !== null ? (
              <p className={days < 0 ? "text-xs text-danger-600" : "text-xs text-ink-500"}>
                {days < 0 ? `${Math.abs(days)}d overdue` : days === 0 ? "Today" : `in ${days}d`}
              </p>
            ) : null}
          </div>
        );
      },
    },
    { key: "status", header: "Status", sortable: true, cell: (row) => <StatusBadge status={row.status} label={row.status_label} size="sm" /> },
    { key: "priority", header: "Priority", sortable: true, hideBelow: "lg", cell: (row) => <PriorityBadge priority={row.priority} size="sm" /> },
    {
      key: "owner",
      header: "Owner",
      hideBelow: "lg",
      cell: (row) => <span className="text-ink-700">{row.owner?.name ?? "Unassigned"}</span>,
    },
    {
      key: "accommodation",
      header: "Inclusion",
      hideBelow: "xl",
      cell: (row) => (
        <div className="flex flex-wrap gap-1 text-xs">
          {row.vulnerable_group_accommodation ? (
            <span className="rounded-full bg-success-50 px-2 py-0.5 text-success-700">Accommodated</span>
          ) : null}
          {row.fpic_required ? <span className="rounded-full bg-brand-50 px-2 py-0.5 text-brand-700">FPIC</span> : null}
          {row.grievance_channel_available ? (
            <span className="rounded-full bg-ink-100 px-2 py-0.5 text-ink-600">Grievance channel</span>
          ) : null}
        </div>
      ),
    },
  ];

  return (
    <div>
      <PageHeader
        eyebrow="Module 2 · Stakeholder management"
        title="Engagement plan"
        description="The schedule the log is measured against. Every plan is built from the register, using the communication frequency the priority score implies."
        actions={
          can("engagement.plan") ? (
            <Link href="/engagements/plans/new">
              <Button variant="accent" icon={<Plus className="h-4 w-4" />}>
                Plan an engagement
              </Button>
            </Link>
          ) : null
        }
      />

      <EngagementNav />

      <dl className="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-5">
        {[
          ["Total", summary.total],
          ["Still planned", summary.planned],
          ["Completed", summary.completed],
          ["Missed", summary.missed],
          ["Next 30 days", summary.upcoming_30_days],
        ].map(([label, value]) => (
          <div key={String(label)} className="sasa-card px-4 py-3">
            <dt className="sasa-eyebrow">{label}</dt>
            <dd className="tabular mt-1 text-2xl font-semibold text-ink-900">{(value as number) ?? "—"}</dd>
          </div>
        ))}
      </dl>

      <div className="mb-4">
        <FilterBar
          search={state.search}
          onSearchChange={state.setSearch}
          searchPlaceholder="Search planned engagements"
          filters={[
            {
              key: "status",
              label: "Status",
              options: ["planned", "completed", "rescheduled", "postponed", "cancelled", "missed"].map((value) => ({
                value,
                label: humanise(value),
              })),
            },
            {
              key: "priority",
              label: "Priority",
              options: ["high", "medium", "low"].map((value) => ({ value, label: humanise(value) })),
            },
            {
              key: "method",
              label: "Method",
              options: (configuration?.data.lists.engagement_methods ?? []).map((value) => ({ value, label: humanise(value) })),
            },
          ]}
          values={state.filterValues}
          onChange={state.setFilter}
          onClear={state.clear}
        />
      </div>

      {isError ? (
        <div className="sasa-card">
          <ErrorState onRetry={() => void refetch()} />
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
          href={(row) => `/engagements/plans/${row.id}`}
          sort={state.sort}
          onSortChange={state.setSort}
          emptyState={
            <EmptyState
              icon={CalendarClock}
              title="Nothing planned yet"
              description="Plan the engagements the register implies, then log what actually happens against them. That gap is what the dashboards report."
              action={
                can("engagement.plan") ? (
                  <Link href="/engagements/plans/new">
                    <Button variant="accent" icon={<Plus className="h-4 w-4" />}>
                      Plan an engagement
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
                  <p className="mt-0.5 font-medium leading-snug text-ink-900">{row.title}</p>
                </div>
                <StatusBadge status={row.status} label={row.status_label} size="sm" />
              </div>
              <p className="mt-2 text-sm text-ink-600">
                {formatDate(row.target_date)} · {humanise(row.method)}
              </p>
              <p className="mt-1 text-sm text-ink-500">
                {row.stakeholder?.name ?? row.stakeholder_group} · {row.owner?.name ?? "Unassigned"}
              </p>
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
