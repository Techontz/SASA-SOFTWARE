"use client";

import { MessageSquareWarning, Plus } from "lucide-react";
import Link from "next/link";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { StatusBadge } from "@/components/ui/StatusBadge";
import { EmptyState, ErrorState, TableSkeleton } from "@/components/ui/States";
import { usePapGrievances } from "@/lib/api/pap";
import { formatDate } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";
import type { Pap } from "@/types/pap";

/**
 * Part K. Not a second grievance system: these are the grievance module's
 * cases linked to the PAP's stakeholder record, and logging one opens the
 * ordinary grievance form already linked to that stakeholder.
 */
export function PapGrievancesPanel({ pap }: { pap: Pap }) {
  const { can } = useSession();
  const { data, isLoading, isError, error, refetch } = usePapGrievances(pap.id);

  const logHref = pap.stakeholder ? `/grievances/new?stakeholder_id=${pap.stakeholder.id}` : "/grievances/new";
  const cases = data?.data ?? [];

  return (
    <Card>
      <CardHeader
        eyebrow="Part K"
        title="Grievances"
        description="Cases in the grievance management module linked to this PAP. Confidential cases appear only to those allowed to see who raised them."
        action={
          can("grievance.create") && !pap.archived_at ? (
            <Link href={logHref}>
              <Button variant="secondary" size="sm" icon={<Plus className="h-4 w-4" />}>
                Log a grievance for this PAP
              </Button>
            </Link>
          ) : null
        }
      />
      <CardBody>
        {isError ? (
          <ErrorState error={error} onRetry={() => void refetch()} />
        ) : isLoading ? (
          <TableSkeleton rows={2} columns={3} />
        ) : data && !data.meta.can_view ? (
          <p className="text-sm text-ink-600">Your role does not include the grievance register, so this PAP&apos;s cases are not shown.</p>
        ) : cases.length === 0 ? (
          <EmptyState icon={MessageSquareWarning} title="No grievances linked to this PAP" className="py-8" />
        ) : (
          <ul className="space-y-3">
            {cases.map((grievance) => (
              <li key={grievance.id}>
                <Link href={grievance.href} className="block rounded-lg border border-hairline p-4 transition hover:border-brand-300">
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <div className="min-w-0">
                      <p className="font-mono text-xs text-ink-500">{grievance.reference}</p>
                      <p className="mt-0.5 break-words font-medium text-ink-900">{grievance.title ?? "Untitled case"}</p>
                    </div>
                    <StatusBadge status={grievance.status} label={grievance.status_label} size="sm" />
                  </div>
                  <dl className="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    <Item label="Date logged" value={formatDate(grievance.date_logged)} />
                    <Item label="Issue category" value={grievance.category ?? "—"} />
                    <Item label="Assigned officer" value={grievance.assigned_officer ?? "Unassigned"} />
                    <Item label="Resolution date" value={formatDate(grievance.resolution_date)} />
                    <Item label="Closure date" value={formatDate(grievance.closure_date)} />
                    {grievance.resolution_agreed ? <Item label="Resolution agreed" value={grievance.resolution_agreed} /> : null}
                  </dl>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </CardBody>
    </Card>
  );
}

function Item({ label, value }: { label: string; value: string }) {
  return (
    <div className="min-w-0">
      <dt className="sasa-eyebrow">{label}</dt>
      <dd className="mt-0.5 break-words text-ink-800">{value}</dd>
    </div>
  );
}
