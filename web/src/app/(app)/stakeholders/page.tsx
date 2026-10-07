"use client";

import { Download, Plus, Users } from "lucide-react";
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
import { useStakeholders } from "@/lib/api/hooks";
import { formatDate, humanise } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";
import { useToast } from "@/providers/ToastProvider";
import type { Stakeholder } from "@/types/api";

const TYPE_OPTIONS = [
  "individual", "household", "community_group", "cso", "government",
  "traditional_leader", "contractor", "project_staff", "business", "vulnerable_group",
].map((value) => ({ value, label: humanise(value) }));

export default function StakeholdersPage() {
  return (
    <Suspense fallback={<TableSkeleton />}>
      <StakeholderRegister />
    </Suspense>
  );
}

function StakeholderRegister() {
  const { can } = useSession();
  const toast = useToast();
  const state = useListState({ sort: "-created_at" });
  const { data, isLoading, isError, error, refetch } = useStakeholders(state.query);

  const rows = data?.data ?? [];
  const meta = data?.meta;
  const summary = meta?.summary ?? {};

  const columns: Column<Stakeholder>[] = [
    {
      key: "name",
      header: "Stakeholder",
      sortable: true,
      cell: (row) => (
        <div className="min-w-0">
          <p className="truncate font-medium text-ink-900">{row.name}</p>
          <p className="mt-0.5 font-mono text-xs text-ink-500">{row.reference}</p>
        </div>
      ),
    },
    { key: "type", header: "Type", sortable: true, cell: (row) => <span className="text-ink-700">{row.type_label}</span> },
    {
      key: "location",
      header: "Location",
      hideBelow: "lg",
      cell: (row) => <span className="text-ink-600">{row.location.display}</span>,
    },
    {
      key: "priority",
      header: "Priority",
      sortable: true,
      cell: (row) => (
        <div className="flex items-center gap-2">
          <PriorityBadge priority={row.assessment.stored_priority} size="sm" />
          {row.assessment.is_overridden ? (
            <span className="text-[0.6875rem] text-ink-500" title="Set by a person, not by the score">
              overridden
            </span>
          ) : null}
        </div>
      ),
    },
    {
      key: "priority_score",
      header: "Score",
      sortable: true,
      align: "right",
      hideBelow: "xl",
      cell: (row) => <span className="tabular text-ink-700">{row.assessment.score ?? "—"}</span>,
    },
    {
      key: "flags",
      header: "Flags",
      hideBelow: "lg",
      cell: (row) => (
        <div className="flex flex-wrap gap-1">
          {row.is_vulnerable ? <StatusBadge status="warning" label="Vulnerable" size="sm" showIcon={false} /> : null}
          {row.consent_status === "granted" ? null : (
            <span className="rounded-full bg-ink-100 px-2 py-0.5 text-[0.6875rem] text-ink-600">No consent</span>
          )}
          {row.review_due ? (
            <span className="rounded-full bg-warning-50 px-2 py-0.5 text-[0.6875rem] text-warning-700">Review due</span>
          ) : null}
        </div>
      ),
    },
    { key: "status", header: "Status", cell: (row) => <StatusBadge status={row.status} size="sm" /> },
    {
      key: "review_date",
      header: "Review",
      sortable: true,
      hideBelow: "xl",
      cell: (row) => <span className="text-ink-600">{formatDate(row.review_date)}</span>,
    },
  ];

  const exportRegister = async () => {
    try {
      await downloadFile("/stakeholders/export", `sasa-stakeholders-${new Date().toISOString().slice(0, 10)}.xlsx`, {
        ...state.query,
        format: "xlsx",
      });
      toast.success("Export ready", "The file has been downloaded and the export is recorded in the audit trail.");
    } catch {
      toast.error("We could not prepare that export", "Please try again in a moment.");
    }
  };

  return (
    <div>
      <PageHeader
        eyebrow="Module 1 · Register"
        title="Stakeholders"
        description="Every individual, household, group, organisation and authority tied to this project — with a transparent priority score and a review date on each."
        actions={
          <>
            {can("stakeholder.export") ? (
              <Button variant="secondary" icon={<Download className="h-4 w-4" />} onClick={() => void exportRegister()}>
                Export
              </Button>
            ) : null}
            {can("stakeholder.create") ? (
              <Link href="/stakeholders/new">
                <Button variant="accent" icon={<Plus className="h-4 w-4" />}>
                  Add stakeholder
                </Button>
              </Link>
            ) : null}
          </>
        }
      />

      {/* Register-level context, not narrowed by the filters it breaks down. */}
      <dl className="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-5">
        {[
          ["Total", summary.total],
          ["Active", summary.active],
          ["High priority", summary.high_priority],
          ["Vulnerable", summary.vulnerable],
          ["Due for review", summary.due_review],
        ].map(([label, value]) => (
          <div key={String(label)} className="sasa-card px-4 py-3">
            <dt className="sasa-eyebrow">{label}</dt>
            <dd className="tabular mt-1 text-2xl font-semibold text-ink-900">{value ?? "—"}</dd>
          </div>
        ))}
      </dl>

      <SavedViewBar entity="stakeholders" filters={state.filterValues} onApply={state.applyView} />

      <div className="mb-4">
        <FilterBar
          search={state.search}
          onSearchChange={state.setSearch}
          searchPlaceholder="Search by name, ID, phone or village"
          filters={[
            { key: "type", label: "Type", options: TYPE_OPTIONS },
            {
              key: "priority",
              label: "Priority",
              options: [
                { value: "high", label: "High" },
                { value: "medium", label: "Medium" },
                { value: "low", label: "Low" },
              ],
            },
            {
              key: "status",
              label: "Status",
              options: [
                { value: "active", label: "Active" },
                { value: "inactive", label: "Inactive" },
                { value: "merged", label: "Merged" },
              ],
            },
            {
              key: "is_vulnerable",
              label: "Vulnerability",
              options: [
                { value: "1", label: "Vulnerable only" },
                { value: "0", label: "Not flagged" },
              ],
            },
            {
              key: "consent_status",
              label: "Consent",
              options: [
                { value: "granted", label: "Granted" },
                { value: "refused", label: "Refused" },
                { value: "not_recorded", label: "Not recorded" },
              ],
            },
            {
              key: "review",
              label: "Review",
              options: [{ value: "due", label: "Due or overdue" }],
            },
            {
              key: "priority_overridden",
              label: "Priority set by",
              options: [
                { value: "1", label: "Overridden by a person" },
                { value: "0", label: "Calculated" },
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
          <TableSkeleton columns={6} />
        </div>
      ) : (
        <DataTable
          rows={rows}
          columns={columns}
          rowKey={(row) => row.id}
          href={(row) => `/stakeholders/${row.id}`}
          sort={state.sort}
          onSortChange={state.setSort}
          emptyState={
            <EmptyState
              icon={Users}
              title={Object.values(state.filterValues).some(Boolean) || state.search ? "No stakeholders match these filters" : "The register is empty"}
              description={
                Object.values(state.filterValues).some(Boolean) || state.search
                  ? "Try removing a filter, or search for a different name."
                  : "Add the first stakeholder, or import a spreadsheet your team already keeps."
              }
              action={
                can("stakeholder.create") ? (
                  <div className="flex flex-wrap justify-center gap-2">
                    <Link href="/stakeholders/new">
                      <Button variant="accent" icon={<Plus className="h-4 w-4" />}>Add a stakeholder</Button>
                    </Link>
                    <Link href="/configuration/import">
                      <Button variant="secondary">Import a spreadsheet</Button>
                    </Link>
                  </div>
                ) : undefined
              }
            />
          }
          mobileCard={(row) => (
            <div>
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <p className="truncate font-medium text-ink-900">{row.name}</p>
                  <p className="mt-0.5 text-sm text-ink-600">{row.type_label}</p>
                </div>
                <PriorityBadge priority={row.assessment.stored_priority} size="sm" />
              </div>
              <p className="mt-2 text-sm text-ink-600">{row.location.display}</p>
              <div className="mt-2.5 flex flex-wrap items-center gap-2">
                <span className="font-mono text-xs text-ink-500">{row.reference}</span>
                <StatusBadge status={row.status} size="sm" />
                {row.is_vulnerable ? (
                  <span className="rounded-full bg-warning-50 px-2 py-0.5 text-[0.6875rem] text-warning-700">Vulnerable</span>
                ) : null}
                {row.review_due ? (
                  <span className="rounded-full bg-warning-50 px-2 py-0.5 text-[0.6875rem] text-warning-700">Review due</span>
                ) : null}
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
