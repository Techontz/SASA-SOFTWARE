"use client";

import { Lock, ScrollText, ShieldCheck } from "lucide-react";
import { Suspense } from "react";
import { PageHeader, Tabs } from "@/components/ui/DetailLayout";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { FilterBar } from "@/components/ui/FilterBar";
import { EmptyState, PermissionDenied, TableSkeleton } from "@/components/ui/States";
import { useListState } from "@/hooks/useListState";
import { useAudit } from "@/lib/api/hooks";
import { cn, formatDateTime, humanise } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";
import type { AuditEntry } from "@/types/api";

export default function AuditPage() {
  const { can } = useSession();

  if (!can("audit.view")) return <PermissionDenied what="the audit trail" />;

  return (
    <div>
      <PageHeader
        eyebrow="Administration"
        title="Audit trail"
        description="Append-only. Nothing in SASA is hard-deleted — archiving is itself an audit event — and every entry survives the record it describes."
      />

      <Suspense fallback={<TableSkeleton />}>
        <Tabs
          tabs={[
            { key: "all", label: "Everything", content: <AuditList /> },
            ...(can("audit.view_sensitive")
              ? [{ key: "sensitive", label: "Who read confidential cases", content: <SensitiveViews /> }]
              : []),
          ]}
        />
      </Suspense>
    </div>
  );
}

function AuditList() {
  const state = useListState({});
  const { data, isLoading } = useAudit(state.query);

  const entries = data?.data.data ?? [];
  const meta = data?.data.meta;

  return (
    <div className="space-y-4">
      <FilterBar
        search={state.search}
        onSearchChange={state.setSearch}
        searchPlaceholder="Search by record, person or summary"
        filters={[
          {
            key: "entity_type",
            label: "Record type",
            options: ["Stakeholder", "Grievance", "Engagement", "Commitment", "Concern", "Configuration"].map((value) => ({
              value,
              label: value,
            })),
          },
        ]}
        values={state.filterValues}
        onChange={state.setFilter}
        onClear={state.clear}
      />

      <Card>
        <CardBody className="p-0 sm:p-0">
          {isLoading ? (
            <TableSkeleton rows={8} columns={4} />
          ) : entries.length === 0 ? (
            <EmptyState icon={ScrollText} title="Nothing recorded yet" />
          ) : (
            <ul className="divide-y divide-hairline">
              {entries.map((entry) => (
                <AuditRow key={entry.id} entry={entry} />
              ))}
            </ul>
          )}
        </CardBody>
      </Card>

      {meta && meta.last_page > 1 ? (
        <div className="flex items-center justify-between">
          <p className="text-sm text-ink-600">
            {meta.from}–{meta.to} of {meta.total}
          </p>
          <div className="flex gap-2">
            <button
              type="button"
              disabled={meta.current_page <= 1}
              onClick={() => state.setPage(meta.current_page - 1)}
              className="h-9 rounded-md border border-ink-300 px-3 text-sm disabled:opacity-40"
            >
              Previous
            </button>
            <button
              type="button"
              disabled={meta.current_page >= meta.last_page}
              onClick={() => state.setPage(meta.current_page + 1)}
              className="h-9 rounded-md border border-ink-300 px-3 text-sm disabled:opacity-40"
            >
              Next
            </button>
          </div>
        </div>
      ) : null}
    </div>
  );
}

function SensitiveViews() {
  const state = useListState({ is_sensitive_view: "1" });
  const { data, isLoading } = useAudit({ ...state.query, is_sensitive_view: "1" });

  const entries = data?.data.data ?? [];

  return (
    <Card>
      <CardHeader
        title="Sensitive-view events"
        description={`"Who read this confidential case" is the question an investigation actually asks, so it is queryable on its own.`}
      />
      <CardBody className="p-0 sm:p-0">
        {isLoading ? (
          <TableSkeleton rows={6} columns={3} />
        ) : entries.length === 0 ? (
          <EmptyState
            icon={ShieldCheck}
            title="No confidential details have been released"
            description="Nobody has opened the complainant details on a confidential case on this project."
          />
        ) : (
          <ul className="divide-y divide-hairline">
            {entries.map((entry) => (
              <AuditRow key={entry.id} entry={entry} />
            ))}
          </ul>
        )}
      </CardBody>
    </Card>
  );
}

function AuditRow({ entry }: { entry: AuditEntry }) {
  const changed = Object.keys(entry.after ?? {});

  return (
    <li className={cn("px-5 py-3.5", entry.is_sensitive_view && "bg-danger-50/40")}>
      <div className="flex flex-wrap items-start gap-3">
        <span
          className={cn(
            "mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full",
            entry.is_sensitive_view ? "bg-danger-100 text-danger-700" : "bg-ink-100 text-ink-600",
          )}
        >
          {entry.is_sensitive_view ? <Lock className="h-3.5 w-3.5" aria-hidden /> : <ScrollText className="h-3.5 w-3.5" aria-hidden />}
        </span>
        <div className="min-w-0 flex-1">
          <p className="font-medium text-ink-900">{entry.summary ?? humanise(entry.action)}</p>
          <p className="mt-0.5 text-sm text-ink-500">
            {entry.user?.name ?? entry.user_name ?? "System"} · {entry.action}
            {entry.entity_reference ? ` · ${entry.entity_reference}` : entry.entity_type ? ` · ${entry.entity_type}` : ""}
          </p>
          {changed.length > 0 ? (
            <details className="mt-2">
              <summary className="cursor-pointer text-xs font-medium text-brand-700">
                {changed.length} {changed.length === 1 ? "field" : "fields"} changed
              </summary>
              <dl className="mt-2 space-y-1 rounded-lg bg-surface-sunken p-3 text-xs">
                {changed.slice(0, 12).map((field) => (
                  <div key={field} className="flex flex-wrap gap-2">
                    <dt className="font-medium text-ink-700">{humanise(field)}</dt>
                    <dd className="text-ink-600">
                      {entry.before?.[field] !== undefined ? (
                        <>
                          <span className="line-through opacity-60">{String(entry.before[field] ?? "—")}</span>{" "}
                          <span aria-hidden>→</span>{" "}
                        </>
                      ) : null}
                      <span className="font-medium text-ink-900">{String(entry.after?.[field] ?? "—")}</span>
                    </dd>
                  </div>
                ))}
              </dl>
            </details>
          ) : null}
        </div>
        <time className="shrink-0 text-xs text-ink-500">{formatDateTime(entry.created_at)}</time>
      </div>
    </li>
  );
}
