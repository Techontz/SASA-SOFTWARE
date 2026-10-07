"use client";

import { Download, Handshake, Plus } from "lucide-react";
import Link from "next/link";
import { Suspense } from "react";
import { SavedViewBar } from "@/components/app/SavedViewBar";
import { Button } from "@/components/ui/Button";
import { DataTable, type Column } from "@/components/ui/DataTable";
import { PageHeader } from "@/components/ui/DetailLayout";
import { FilterBar } from "@/components/ui/FilterBar";
import { PriorityBadge, StatusBadge } from "@/components/ui/StatusBadge";
import { EmptyState, ErrorState, TableSkeleton } from "@/components/ui/States";
import { useListState } from "@/hooks/useListState";
import { downloadFile } from "@/lib/api/client";
import { useCommitments } from "@/lib/api/hooks";
import { formatDate, truncate } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";
import { useToast } from "@/providers/ToastProvider";
import type { Commitment } from "@/types/api";

export default function CommitmentsPage() {
  return (
    <Suspense fallback={<TableSkeleton />}>
      <CommitmentList />
    </Suspense>
  );
}

function CommitmentList() {
  const { can } = useSession();
  const toast = useToast();
  const state = useListState({ sort: "due_date" });
  const { data, isLoading, isError, error, refetch } = useCommitments(state.query);

  const rows = data?.data ?? [];
  const meta = data?.meta;
  const summary = meta?.summary ?? {};

  const columns: Column<Commitment>[] = [
    {
      key: "commitment_text",
      header: "Commitment",
      width: "40%",
      cell: (row) => (
        <div className="min-w-0">
          <span className="font-mono text-xs text-ink-500">{row.reference}</span>
          <p className="mt-0.5 text-ink-900">{truncate(row.commitment_text, 130)}</p>
          {row.engagement ? (
            <p className="mt-0.5 text-xs text-ink-500">
              Promised at {row.engagement.reference} · {formatDate(row.engagement.held_at)}
            </p>
          ) : null}
        </div>
      ),
    },
    {
      key: "due_date",
      header: "Due",
      sortable: true,
      cell: (row) => (
        <div className="whitespace-nowrap">
          <p className={row.is_overdue ? "font-medium text-danger-700" : "text-ink-700"}>{formatDate(row.due_date)}</p>
          {row.days_until_due !== null ? (
            <p className={row.is_overdue ? "text-xs text-danger-600" : "text-xs text-ink-500"}>
              {row.days_until_due < 0 ? `${Math.abs(row.days_until_due)}d over` : `in ${row.days_until_due}d`}
            </p>
          ) : null}
        </div>
      ),
    },
    { key: "status", header: "Status", sortable: true, cell: (row) => <StatusBadge status={row.status} label={row.status_label} size="sm" /> },
    { key: "risk_level", header: "Risk", sortable: true, hideBelow: "lg", cell: (row) => <PriorityBadge priority={row.risk_level} size="sm" /> },
    {
      key: "verification_status",
      header: "Verified",
      hideBelow: "lg",
      cell: (row) => <StatusBadge status={row.verification_status} size="sm" />,
    },
    {
      key: "owner",
      header: "Owner",
      hideBelow: "xl",
      cell: (row) => <span className="text-ink-700">{row.owner?.name ?? "Unassigned"}</span>,
    },
  ];

  return (
    <div>
      <PageHeader
        eyebrow="Module 2 · Stakeholder management"
        title="Commitments"
        description="Every promise the project made, with an owner, a due date and evidence of delivery. A commitment made at a village meeting in March has to be traceable in October."
        actions={
          <>
            <Button
              variant="secondary"
              icon={<Download className="h-4 w-4" />}
              onClick={async () => {
                try {
                  await downloadFile("/commitments/export", "sasa-commitments.xlsx", { ...state.query, format: "xlsx" });
                  toast.success("Export ready");
                } catch {
                  toast.error("We could not prepare that export");
                }
              }}
            >
              Export
            </Button>
            {can("commitment.manage") ? (
              <Link href="/commitments/new">
                <Button variant="accent" icon={<Plus className="h-4 w-4" />}>
                  Add a commitment
                </Button>
              </Link>
            ) : null}
          </>
        }
      />

      <dl className="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        {[
          ["Total", summary.total, null],
          ["Open", summary.open, "/commitments?status=open"],
          ["Overdue", summary.overdue, "/commitments?overdue=1"],
          ["Fulfilled", summary.fulfilled, "/commitments?status=fulfilled"],
          ["High risk", summary.high_risk, "/commitments?risk_level=high"],
          ["Not verified", summary.unverified, "/commitments?unverified=1"],
        ].map(([label, value, href]) => {
          const tile = (
            <div className="sasa-card h-full px-4 py-3 transition hover:border-brand-300">
              <dt className="sasa-eyebrow">{label}</dt>
              <dd className="tabular mt-1 text-2xl font-semibold text-ink-900">{(value as number) ?? "—"}</dd>
            </div>
          );

          return <div key={String(label)}>{href ? <Link href={href as string}>{tile}</Link> : tile}</div>;
        })}
      </dl>

      <SavedViewBar entity="commitments" filters={state.filterValues} onApply={state.applyView} />

      <div className="mb-4">
        <FilterBar
          search={state.search}
          onSearchChange={state.setSearch}
          searchPlaceholder="Search commitments"
          filters={[
            {
              key: "status",
              label: "Status",
              options: ["open", "in_progress", "overdue", "fulfilled", "cancelled"].map((value) => ({
                value,
                label: value.replace(/_/g, " "),
              })),
            },
            { key: "risk_level", label: "Risk", options: ["high", "medium", "low"].map((value) => ({ value, label: value })) },
            {
              key: "verification_status",
              label: "Verification",
              options: [
                { value: "verified", label: "Verified" },
                { value: "unverified", label: "Not verified" },
                { value: "disputed", label: "Disputed" },
              ],
            },
            { key: "overdue", label: "Overdue only", options: [{ value: "1", label: "Overdue" }] },
            {
              key: "source_type",
              label: "Came from",
              options: [
                { value: "engagement", label: "An engagement" },
                { value: "grievance", label: "A grievance" },
                { value: "manual", label: "Added directly" },
              ],
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
          href={(row) => `/commitments/${row.id}`}
          sort={state.sort}
          onSortChange={state.setSort}
          emptyState={
            <EmptyState
              icon={Handshake}
              title="No commitments recorded"
              description="Commitments are usually captured while logging an engagement. You can also add one directly."
              action={
                can("commitment.manage") ? (
                  <Link href="/commitments/new">
                    <Button variant="accent" icon={<Plus className="h-4 w-4" />}>
                      Add a commitment
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
                  <p className="mt-0.5 leading-snug text-ink-900">{truncate(row.commitment_text, 120)}</p>
                </div>
                <StatusBadge status={row.status} label={row.status_label} size="sm" />
              </div>
              <div className="mt-2 flex flex-wrap items-center gap-2">
                <PriorityBadge priority={row.risk_level} prefix="Risk" size="sm" />
                <span className={row.is_overdue ? "text-sm font-medium text-danger-700" : "text-sm text-ink-600"}>
                  {row.due_date ? `Due ${formatDate(row.due_date)}` : "No due date"}
                </span>
              </div>
              <p className="mt-1 text-sm text-ink-500">{row.owner?.name ?? "Unassigned"}</p>
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
