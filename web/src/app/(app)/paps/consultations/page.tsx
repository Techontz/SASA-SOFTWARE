"use client";

import { MessagesSquare, Plus } from "lucide-react";
import Link from "next/link";
import { Suspense } from "react";
import { Button } from "@/components/ui/Button";
import { DataTable, type Column } from "@/components/ui/DataTable";
import { PageHeader } from "@/components/ui/DetailLayout";
import { FilterBar } from "@/components/ui/FilterBar";
import { EmptyState, ErrorState, PermissionDenied, TableSkeleton } from "@/components/ui/States";
import { useListState } from "@/hooks/useListState";
import { usePapConsultations, usePapOptions } from "@/lib/api/pap";
import { formatDate, formatNumber, truncate } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";
import type { PapConsultation } from "@/types/pap";

const LIST_DEFAULTS = { sort: "-consultation_date" };

export default function PapConsultationsPage() {
  return (
    <Suspense fallback={<TableSkeleton />}>
      <ConsultationList />
    </Suspense>
  );
}

function ConsultationList() {
  const { can } = useSession();
  const state = useListState(LIST_DEFAULTS);
  const { data, isLoading, isError, error, refetch } = usePapConsultations(state.query);
  const { data: optionData } = usePapOptions();

  if (!can("pap.view")) return <PermissionDenied what="the consultation register" />;

  const rows = data?.data ?? [];
  const meta = data?.meta;
  const summary = meta?.summary ?? {};

  const columns: Column<PapConsultation>[] = [
    {
      key: "consultation_date",
      header: "Date",
      sortable: true,
      cell: (row) => (
        <div className="whitespace-nowrap">
          <p className="text-ink-800">{formatDate(row.consultation_date)}</p>
          <p className="font-mono text-xs text-ink-500">{row.reference}</p>
        </div>
      ),
    },
    {
      key: "topic_discussed",
      header: "Topic",
      width: "40%",
      cell: (row) => (
        <div className="min-w-0">
          <p className="text-ink-900">{truncate(row.topic_discussed, 120)}</p>
          <p className="text-xs text-ink-500">{row.meeting_type_label ?? "Meeting"}</p>
        </div>
      ),
    },
    { key: "village", header: "Village", sortable: true, cell: (row) => <span className="text-ink-700">{row.village ?? "—"}</span> },
    {
      key: "attendance",
      header: "Male / female / vulnerable",
      hideBelow: "lg",
      cell: (row) => (
        <span className="tabular text-ink-700">
          {row.number_male} / {row.number_female} / {row.number_vulnerable}
        </span>
      ),
    },
  ];

  return (
    <div>
      <PageHeader
        backHref="/paps"
        eyebrow="PAP master register · Part L"
        title="Consultation register"
        description="Resettlement consultations held with affected villages: who came, what they raised and what was agreed."
        actions={
          can("pap.manage") ? (
            <Link href="/paps/consultations/new">
              <Button variant="accent" icon={<Plus className="h-4 w-4" />}>
                Record a consultation
              </Button>
            </Link>
          ) : null
        }
      />

      <dl className="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        {[
          ["Meetings", summary.meetings],
          ["Men attending", summary.male],
          ["Women attending", summary.female],
          ["Vulnerable attending", summary.vulnerable],
        ].map(([label, value]) => (
          <div key={String(label)} className="sasa-card px-4 py-3">
            <dt className="sasa-eyebrow">{label}</dt>
            <dd className="tabular mt-1 text-2xl font-semibold text-ink-900">{formatNumber(value as number | null | undefined)}</dd>
          </div>
        ))}
      </dl>

      <div className="mb-4">
        <FilterBar
          search={state.search}
          onSearchChange={state.setSearch}
          searchPlaceholder="Search topic, village or facilitator"
          filters={[{ key: "meeting_type", label: "Meeting type", options: optionData?.data.lists.meeting_types ?? [] }]}
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
          <TableSkeleton columns={4} />
        </div>
      ) : (
        <DataTable
          rows={rows}
          columns={columns}
          rowKey={(row) => row.id}
          sort={state.sort}
          onSortChange={state.setSort}
          emptyState={
            <EmptyState
              icon={MessagesSquare}
              title="No consultations recorded"
              description="Record each village assembly, focus group and disclosure meeting held on resettlement."
            />
          }
          mobileCard={(row) => (
            <div>
              <p className="font-mono text-xs text-ink-500">
                {row.reference} · {formatDate(row.consultation_date)}
              </p>
              <p className="mt-0.5 text-ink-900">{truncate(row.topic_discussed, 110)}</p>
              <p className="mt-1 text-sm text-ink-600">
                {row.village ?? "—"} · {row.number_male} men, {row.number_female} women, {row.number_vulnerable} vulnerable
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

      {rows.some((row) => row.key_concerns_raised || row.actions_agreed) ? (
        <section className="mt-6 space-y-3">
          <h2 className="text-base font-semibold text-ink-900">Concerns raised and actions agreed</h2>
          {rows.map((row) =>
            row.key_concerns_raised || row.actions_agreed ? (
              <div key={row.id} className="sasa-card p-4">
                <p className="font-mono text-xs text-ink-500">
                  {row.reference} · {row.village ?? "—"} · {row.facilitator ?? "Facilitator not recorded"}
                </p>
                {row.key_concerns_raised ? (
                  <p className="mt-2 text-sm text-ink-800">
                    <span className="font-medium">Concerns: </span>
                    {row.key_concerns_raised}
                  </p>
                ) : null}
                {row.actions_agreed ? (
                  <p className="mt-1 text-sm text-ink-800">
                    <span className="font-medium">Agreed: </span>
                    {row.actions_agreed}
                  </p>
                ) : null}
                {row.attendance_sheet_reference ? (
                  <p className="mt-1 text-xs text-ink-500">Attendance sheet {row.attendance_sheet_reference}</p>
                ) : null}
              </div>
            ) : null,
          )}
        </section>
      ) : null}
    </div>
  );
}
