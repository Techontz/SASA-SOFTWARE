import { StatusBadge } from "@/components/ui/StatusBadge";
import { INCIDENT_STATUS_BADGE } from "@/lib/incidents";

export function IncidentStatusBadge({ status, size = "md" }: { status: string; size?: "sm" | "md" }) {
  const entry = INCIDENT_STATUS_BADGE[status] ?? { status, label: status };
  return <StatusBadge status={entry.status} label={entry.label} size={size} />;
}

/** Serious is always spelled out — never a colour on its own. */
export function SeriousBadge({ size = "md" }: { size?: "sm" | "md" }) {
  return <StatusBadge status="escalated" label="Serious" size={size} />;
}
