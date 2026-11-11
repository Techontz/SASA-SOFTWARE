"use client";

import { Download, MessagesSquare, Plus, UsersRound } from "lucide-react";
import Link from "next/link";
import { Suspense } from "react";
import { PapKpis } from "@/components/pap/PapKpis";
import { Button } from "@/components/ui/Button";
import { DataTable, type Column } from "@/components/ui/DataTable";
import { PageHeader } from "@/components/ui/DetailLayout";
import { FilterBar } from "@/components/ui/FilterBar";
import { StatusBadge } from "@/components/ui/StatusBadge";
import { EmptyState, ErrorState, PermissionDenied, TableSkeleton } from "@/components/ui/States";
import { useListState } from "@/hooks/useListState";
import { downloadFile } from "@/lib/api/client";
import { usePapOptions, usePaps, usePapSummary } from "@/lib/api/pap";
import { formatMoney } from "@/lib/pap/format";
import { useSession } from "@/providers/SessionProvider";
import { useToast } from "@/providers/ToastProvider";
import type { Pap } from "@/types/pap";

const LIST_DEFAULTS = { sort: "reference" };

export default function PapsPage() {
  return (
    <Suspense fallback={<TableSkeleton />}>
      <PapList />
    </Suspense>
  );
}

function PapList() {
  const { can } = useSession();
  const toast = useToast();
  const state = useListState(LIST_DEFAULTS);
  const { data, isLoading, isError, error, refetch } = usePaps(state.query);
  const { data: summary, isLoading: summaryLoading } = usePapSummary();
  const { data: optionData } = usePapOptions();

  if (!can("pap.view")) return <PermissionDenied what="the PAP register" />;

  const rows = data?.data ?? [];
  const meta = data?.meta;
  const lists = optionData?.data.lists ?? {};
  const currency = optionData?.data.currency ?? "TZS";

  const columns: Column<Pap>[] = [
    {
      key: "full_name",
      header: "PAP",
      sortable: true,
      width: "30%",
      cell: (row) => (
        <div className="min-w-0">
          <span className="font-mono text-xs text-ink-500">{row.reference}</span>
          <p className="mt-0.5 font-medium text-ink-900">{row.full_name}</p>
          <p className="text-xs text-ink-500">
            {[row.gender_label, row.age !== null ? `${row.age} yrs` : null].filter(Boolean).join(" · ") || "—"}
          </p>
        </div>
      ),
    },
    { key: "household_id", header: "Household", sortable: true, hideBelow: "lg", cell: (row) => <span className="font-mono text-sm text-ink-700">{row.household_id ?? "—"}</span> },
    {
      key: "village",
      header: "Village",
      sortable: true,
      cell: (row) => (
        <div>
          <p className="text-ink-800">{row.location.village ?? "—"}</p>
          {row.location.kitongoji ? <p className="text-xs text-ink-500">{row.location.kitongoji}</p> : null}
        </div>
      ),
    },
    {
      key: "vulnerability",
      header: "Vulnerability",
      hideBelow: "lg",
      cell: (row) =>
        row.is_vulnerable ? <StatusBadge status="at_risk" label={row.vulnerability_label ?? "Vulnerable"} size="sm" showIcon={false} /> : <span className="text-ink-500">None</span>,
    },
    {
      key: "money",
      header: "Paid / outstanding",
      hideBelow: "xl",
      align: "right",
      cell: (row) => (
        <div className="tabular whitespace-nowrap text-right">
          <p className="text-ink-800">{formatMoney(row.money?.amount_paid ?? 0, currency)}</p>
          <p className={(row.money?.outstanding ?? 0) > 0 ? "text-xs text-warning-700" : "text-xs text-ink-500"}>
            {formatMoney(row.money?.outstanding ?? 0, currency)} due
          </p>
        </div>
      ),
    },
  ];

  return (
    <div>
      <PageHeader
        eyebrow="Safeguards"
        title="PAP master register"
        description="Every Project Affected Person, what they lost, what they are owed and what has been paid. Each PAP is kept in step with the stakeholder register automatically."
        actions={
          <>
            <Link href="/paps/consultations">
              <Button variant="secondary" icon={<MessagesSquare className="h-4 w-4" />}>
                Consultations
              </Button>
            </Link>
            {can("pap.export") ? (
              <Button
                variant="secondary"
                icon={<Download className="h-4 w-4" />}
                onClick={async () => {
                  try {
                    await downloadFile("/paps/export", "sasa-paps.xlsx", { ...state.query, format: "xlsx" });
                    toast.success("Export ready");
                  } catch {
                    toast.error("We could not prepare that export");
                  }
                }}
              >
                Export
              </Button>
            ) : null}
            {can("pap.manage") ? (
              <Link href="/paps/new">
                <Button variant="accent" icon={<Plus className="h-4 w-4" />}>
                  Add a PAP
                </Button>
              </Link>
            ) : null}
          </>
        }
      />

      <PapKpis summary={summary?.data} loading={summaryLoading} />

      <div className="mb-4">
        <FilterBar
          search={state.search}
          onSearchChange={state.setSearch}
          searchPlaceholder="Search name, PAP ID, household, phone or village"
          filters={[
            { key: "gender", label: "Gender", options: lists.genders ?? [] },
            { key: "vulnerability_category", label: "Vulnerability", options: lists.vulnerability_categories ?? [] },
            { key: "vulnerable", label: "Vulnerable only", options: [{ value: "1", label: "Any vulnerability" }] },
            { key: "payment_status", label: "Payment status", options: lists.payment_statuses ?? [] },
            { key: "outstanding", label: "Money outstanding", options: [{ value: "1", label: "Has an outstanding balance" }] },
            { key: "relocated", label: "Relocation", options: [{ value: "1", label: "Relocated" }] },
            { key: "include_archived", label: "Archived", options: [{ value: "1", label: "Include archived" }] },
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
          href={(row) => `/paps/${row.id}`}
          sort={state.sort}
          onSortChange={state.setSort}
          emptyState={
            <EmptyState
              icon={UsersRound}
              title="No PAPs match"
              description="Clear the filters, or add the first Project Affected Person from the census."
              action={
                can("pap.manage") ? (
                  <Link href="/paps/new">
                    <Button variant="accent" icon={<Plus className="h-4 w-4" />}>
                      Add a PAP
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
                  <p className="font-mono text-xs text-ink-500">
                    {row.reference} · {row.household_id ?? "—"}
                  </p>
                  <p className="mt-0.5 font-medium text-ink-900">{row.full_name}</p>
                </div>
                {row.archived_at ? <StatusBadge status="archived" size="sm" /> : null}
              </div>
              <p className="mt-1 text-sm text-ink-600">{row.location.display || "Location not recorded"}</p>
              <div className="mt-2 flex flex-wrap items-center gap-2">
                {row.is_vulnerable ? <StatusBadge status="at_risk" label={row.vulnerability_label ?? "Vulnerable"} size="sm" showIcon={false} /> : null}
                <span className="tabular text-sm text-ink-600">{formatMoney(row.money?.outstanding ?? 0, currency)} outstanding</span>
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
