"use client";

import { ClipboardCheck, Download, Plus } from "lucide-react";
import Link from "next/link";
import { Suspense } from "react";
import { SavedViewBar } from "@/components/app/SavedViewBar";
import { Button } from "@/components/ui/Button";
import { DataTable, type Column } from "@/components/ui/DataTable";
import { PageHeader } from "@/components/ui/DetailLayout";
import { FilterBar } from "@/components/ui/FilterBar";
import { SeverityBadge, StatusBadge } from "@/components/ui/StatusBadge";
import { EmptyState, ErrorState, TableSkeleton } from "@/components/ui/States";
import { useListState } from "@/hooks/useListState";
import { downloadFile } from "@/lib/api/client";
import { useGrievanceCategories, useGrievances } from "@/lib/api/hooks";
import { daysBetween, formatDate, humanise } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";
import { useToast } from "@/providers/ToastProvider";
import type { Grievance } from "@/types/api";

const CHANNELS = ["voice", "whatsapp", "sms", "web", "in_person", "email", "leader", "suggestion_box"];

export default function GrievancesPage() {
  return (
    <Suspense fallback={<TableSkeleton />}>
      <GrievanceList />
    </Suspense>
  );
}

function GrievanceList() {
  const { can } = useSession();
  const toast = useToast();
  const state = useListState({ sort: "-received_at" });
  const { data, isLoading, isError, refetch } = useGrievances(state.query);
  const { data: categories } = useGrievanceCategories();

  const rows = data?.data ?? [];
  const meta = data?.meta;
  const summary = meta?.summary ?? {};

  const columns: Column<Grievance>[] = [
    {
      key: "reference",
      header: "Case",
      sortable: true,
      cell: (row) => (
        <div className="min-w-0">
          <div className="flex items-center gap-2">
            <span className="font-mono text-xs text-ink-500">{row.reference}</span>
            {row.confidentiality !== "normal" ? (
              <StatusBadge status={row.confidentiality} size="sm" />
            ) : null}
          </div>
          <p className="mt-0.5 truncate font-medium text-ink-900">{row.title}</p>
          <p className="mt-0.5 truncate text-xs text-ink-500">
            {row.category?.name ?? "Not classified"} · {row.channel_label}
          </p>
        </div>
      ),
      width: "34%",
    },
    {
      key: "severity",
      header: "Severity",
      sortable: true,
      cell: (row) => <SeverityBadge severity={row.severity} label={row.severity_label} size="sm" />,
    },
    { key: "status", header: "Status", sortable: true, cell: (row) => <StatusBadge status={row.status} size="sm" /> },
    {
      key: "sla",
      header: "Resolution SLA",
      cell: (row) => <SlaCell state={row.sla.resolution_state} dueAt={row.sla.resolution_due_at} />,
    },
    {
      key: "assigned_to_id",
      header: "Owner",
      hideBelow: "lg",
      cell: (row) => (
        <span className={row.assigned_to ? "text-ink-700" : "text-warning-700"}>
          {row.assigned_to?.name ?? "Unassigned"}
        </span>
      ),
    },
    {
      key: "received_at",
      header: "Received",
      sortable: true,
      hideBelow: "lg",
      cell: (row) => (
        <span className="whitespace-nowrap text-ink-600">{formatDate(row.received_at)}</span>
      ),
    },
  ];

  const exportCases = async () => {
    try {
      await downloadFile("/grievances/export", `sasa-grievances-${new Date().toISOString().slice(0, 10)}.xlsx`, {
        ...state.query,
        format: "xlsx",
      });
      toast.success(
        "Export ready",
        "Complainant identity is included only if you are in the handling group. The export is recorded in the audit trail.",
      );
    } catch {
      toast.error("We could not prepare that export");
    }
  };

  const categoryOptions = (categories?.data ?? []).map((category) => ({
    value: String(category.id),
    label: category.name,
  }));

  return (
    <div>
      <PageHeader
        eyebrow="Module 3 · Grievances"
        title="Grievance cases"
        description="Every channel produces one case, with one clock and one audit trail. The channel is recorded, but it changes nothing about how the case is worked."
        actions={
          <>
            {can("grievance.export") ? (
              <Button variant="secondary" icon={<Download className="h-4 w-4" />} onClick={() => void exportCases()}>
                Export
              </Button>
            ) : null}
            {can("grievance.create") ? (
              <Link href="/grievances/new">
                <Button variant="accent" icon={<Plus className="h-4 w-4" />}>
                  Log a grievance
                </Button>
              </Link>
            ) : null}
          </>
        }
      />

      <dl className="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        {[
          ["Total", summary.total, null],
          ["Open", summary.open, "/grievances?status=open"],
          ["Closed", summary.closed, "/grievances?status=closed"],
          ["Unassigned", summary.unassigned, "/grievances?assignee=none&status=open"],
          ["Level 4–5 open", summary.high_severity, "/grievances?severity_min=4&status=open"],
          ["SLA breached", summary.breached, "/grievances?sla=breached"],
        ].map(([label, value, href]) => {
          const tile = (
            <div className="sasa-card h-full px-4 py-3 transition hover:border-brand-300">
              <dt className="sasa-eyebrow">{label}</dt>
              <dd className="tabular mt-1 text-2xl font-semibold text-ink-900">{(value as number) ?? "—"}</dd>
            </div>
          );

          return (
            <div key={String(label)}>
              {href ? <Link href={href as string}>{tile}</Link> : tile}
            </div>
          );
        })}
      </dl>

      <SavedViewBar entity="grievances" filters={state.filterValues} onApply={state.applyView} />

      <div className="mb-4">
        <FilterBar
          search={state.search}
          onSearchChange={state.setSearch}
          searchPlaceholder="Search by case ID, description, phone number or place"
          filters={[
            {
              key: "status",
              label: "Status",
              options: [
                { value: "open", label: "Open (any stage)" },
                { value: "closed", label: "Closed" },
                { value: "new", label: "New" },
                { value: "assigned", label: "Assigned" },
                { value: "acknowledged", label: "Acknowledged" },
                { value: "under_investigation", label: "Under investigation" },
                { value: "awaiting_confirmation", label: "Awaiting complainant" },
                { value: "reopened", label: "Reopened" },
              ],
            },
            {
              key: "severity",
              label: "Severity",
              options: [1, 2, 3, 4, 5].map((level) => ({ value: String(level), label: `Level ${level}` })),
            },
            { key: "category_id", label: "Category", options: categoryOptions },
            { key: "channel", label: "Received via", options: CHANNELS.map((value) => ({ value, label: humanise(value) })) },
            {
              key: "sla",
              label: "SLA",
              options: [
                { value: "breached", label: "Breached" },
                { value: "at_risk", label: "At risk" },
                { value: "paused", label: "Paused" },
              ],
            },
            {
              key: "assignee",
              label: "Owner",
              options: [
                { value: "me", label: "Assigned to me" },
                { value: "none", label: "Unassigned" },
              ],
            },
            {
              key: "confidentiality",
              label: "Confidentiality",
              options: [
                { value: "normal", label: "Standard" },
                { value: "confidential", label: "Confidential" },
                { value: "anonymous", label: "Anonymous" },
              ],
            },
            {
              key: "acknowledged",
              label: "Acknowledged",
              options: [
                { value: "1", label: "Yes" },
                { value: "0", label: "Not yet" },
              ],
            },
            { key: "reopened", label: "Reopened", options: [{ value: "1", label: "Has been reopened" }] },
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
          <TableSkeleton columns={7} />
        </div>
      ) : (
        <DataTable
          rows={rows}
          columns={columns}
          rowKey={(row) => row.id}
          href={(row) => `/grievances/${row.id}`}
          sort={state.sort}
          onSortChange={state.setSort}
          emptyState={
            <EmptyState
              icon={ClipboardCheck}
              title={state.search || Object.values(state.filterValues).some(Boolean) ? "No cases match these filters" : "No grievances yet"}
              description={
                state.search || Object.values(state.filterValues).some(Boolean)
                  ? "Try removing a filter, or search for a different case ID."
                  : "When a concern arrives by phone, WhatsApp, SMS, the web form or in person, it becomes a case here."
              }
              action={
                can("grievance.create") ? (
                  <Link href="/grievances/new">
                    <Button variant="accent" icon={<Plus className="h-4 w-4" />}>
                      Log the first grievance
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
                <SeverityBadge severity={row.severity} size="sm" />
              </div>
              <div className="mt-2.5 flex flex-wrap items-center gap-2">
                <StatusBadge status={row.status} size="sm" />
                {row.confidentiality !== "normal" ? <StatusBadge status={row.confidentiality} size="sm" /> : null}
                <SlaCell state={row.sla.resolution_state} dueAt={row.sla.resolution_due_at} compact />
              </div>
              <p className="mt-2 text-sm text-ink-600">
                {row.channel_label} · {formatDate(row.received_at)} ·{" "}
                {row.assigned_to?.name ?? <span className="text-warning-700">Unassigned</span>}
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

/** SLA state plus how long is left, because "breached" alone is not enough. */
export function SlaCell({
  state,
  dueAt,
  compact,
}: {
  state: string | null;
  dueAt: string | null;
  compact?: boolean;
}) {
  if (!state) return <span className="text-ink-400">—</span>;

  const days = daysBetween(dueAt);

  const caption =
    state === "breached"
      ? days !== null
        ? `${Math.abs(days)}d over`
        : "Past due"
      : state === "met" || state === "met_late"
        ? null
        : days !== null
          ? days >= 0
            ? `${days}d left`
            : `${Math.abs(days)}d over`
          : null;

  return (
    <span className="inline-flex flex-wrap items-center gap-1.5">
      <StatusBadge status={state} size="sm" />
      {caption && !compact ? <span className="text-xs text-ink-500">{caption}</span> : null}
    </span>
  );
}
