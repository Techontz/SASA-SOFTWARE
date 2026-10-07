"use client";

import { MessageSquareWarning } from "lucide-react";
import Link from "next/link";
import { Suspense } from "react";
import { DataTable, type Column } from "@/components/ui/DataTable";
import { PageHeader } from "@/components/ui/DetailLayout";
import { FilterBar } from "@/components/ui/FilterBar";
import { PriorityBadge, StatusBadge } from "@/components/ui/StatusBadge";
import { EmptyState, ErrorState, TableSkeleton } from "@/components/ui/States";
import { useListState } from "@/hooks/useListState";
import { useConcerns, useGrievanceCategories } from "@/lib/api/hooks";
import { formatDate, truncate } from "@/lib/utils";
import type { Concern } from "@/types/api";

export default function ConcernsPage() {
  return (
    <Suspense fallback={<TableSkeleton />}>
      <ConcernList />
    </Suspense>
  );
}

function ConcernList() {
  const state = useListState({ sort: "-raised_on" });
  const { data, isLoading, isError, error, refetch } = useConcerns(state.query);
  const { data: categories } = useGrievanceCategories();

  const rows = data?.data ?? [];
  const meta = data?.meta;
  const summary = meta?.summary ?? {};

  const columns: Column<Concern>[] = [
    {
      key: "title",
      header: "Concern",
      width: "40%",
      cell: (row) => (
        <div className="min-w-0">
          <span className="font-mono text-xs text-ink-500">{row.reference}</span>
          <p className="mt-0.5 font-medium text-ink-900">{row.title}</p>
          <p className="mt-0.5 text-xs text-ink-500">{truncate(row.description, 110)}</p>
        </div>
      ),
    },
    {
      key: "raised_on",
      header: "Raised",
      sortable: true,
      cell: (row) => (
        <div className="whitespace-nowrap">
          <p className="text-ink-700">{formatDate(row.raised_on)}</p>
          {row.engagement ? (
            <Link href={`/engagements/${row.engagement.id}`} className="text-xs text-brand-700 hover:underline">
              {row.engagement.reference}
            </Link>
          ) : null}
        </div>
      ),
    },
    {
      key: "stakeholder",
      header: "Raised by",
      hideBelow: "lg",
      cell: (row) => (
        <span className="text-ink-700">{row.stakeholder?.name ?? row.raised_by ?? "Not recorded"}</span>
      ),
    },
    {
      key: "severity_hint",
      header: "Seriousness",
      hideBelow: "lg",
      cell: (row) => <PriorityBadge priority={row.severity_hint} size="sm" />,
    },
    { key: "status", header: "Status", sortable: true, cell: (row) => <StatusBadge status={row.status} size="sm" /> },
    {
      key: "grievance",
      header: "Escalated",
      cell: (row) =>
        row.grievance ? (
          <Link href={`/grievances/${row.grievance.id}`} className="font-mono text-xs text-danger-700 hover:underline">
            {row.grievance.reference}
          </Link>
        ) : (
          <span className="text-ink-400">—</span>
        ),
    },
  ];

  return (
    <div>
      <PageHeader
        eyebrow="Module 2 · Stakeholder management"
        title="Concerns"
        description="What people raised in engagement, before it became a formal case. A concern recorded here is the earliest chance the project has to fix something."
      />

      <dl className="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
        {[
          ["Total", summary.total],
          ["Still open", summary.open],
          ["Escalated to a case", summary.escalated],
          ["Conversion rate", summary.conversion_rate !== null && summary.conversion_rate !== undefined ? `${summary.conversion_rate}%` : "—"],
        ].map(([label, value]) => (
          <div key={String(label)} className="sasa-card px-4 py-3">
            <dt className="sasa-eyebrow">{label}</dt>
            <dd className="tabular mt-1 text-2xl font-semibold text-ink-900">{(value as string | number) ?? "—"}</dd>
          </div>
        ))}
      </dl>

      <div className="mb-4">
        <FilterBar
          search={state.search}
          onSearchChange={state.setSearch}
          searchPlaceholder="Search concerns"
          filters={[
            {
              key: "status",
              label: "Status",
              options: [
                { value: "open", label: "Open" },
                { value: "addressed", label: "Addressed" },
                { value: "escalated", label: "Escalated" },
                { value: "closed", label: "Closed" },
              ],
            },
            {
              key: "escalated",
              label: "Escalation",
              options: [
                { value: "1", label: "Became a grievance" },
                { value: "0", label: "Not escalated" },
              ],
            },
            {
              key: "severity_hint",
              label: "Seriousness",
              options: ["high", "medium", "low"].map((value) => ({ value, label: value })),
            },
            {
              key: "grievance_category_id",
              label: "Topic",
              options: (categories?.data ?? []).map((category) => ({ value: String(category.id), label: category.name })),
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
          href={(row) => `/concerns/${row.id}`}
          sort={state.sort}
          onSortChange={state.setSort}
          emptyState={
            <EmptyState
              icon={MessageSquareWarning}
              title="No concerns recorded"
              description="Concerns are captured while logging an engagement. Recording them is what lets the project act before they become grievances."
            />
          }
          mobileCard={(row) => (
            <div>
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <p className="font-mono text-xs text-ink-500">{row.reference}</p>
                  <p className="mt-0.5 font-medium leading-snug text-ink-900">{row.title}</p>
                </div>
                <StatusBadge status={row.status} size="sm" />
              </div>
              <p className="mt-2 text-sm text-ink-600">{truncate(row.description, 140)}</p>
              <p className="mt-2 text-sm text-ink-500">
                {formatDate(row.raised_on)} · {row.stakeholder?.name ?? row.raised_by ?? "Not recorded"}
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
