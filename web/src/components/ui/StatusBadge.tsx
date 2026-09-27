import {
  AlertOctagon,
  AlertTriangle,
  Archive,
  CalendarClock,
  CheckCircle2,
  CircleDashed,
  CircleDot,
  Clock,
  EyeOff,
  Lock,
  PauseCircle,
  RotateCcw,
  ShieldAlert,
  Timer,
  XCircle,
  type LucideIcon,
} from "lucide-react";
import { cn, humanise } from "@/lib/utils";

type Tone = "neutral" | "info" | "success" | "warning" | "danger" | "brand" | "accent";

const TONES: Record<Tone, string> = {
  neutral: "bg-ink-100 text-ink-700 ring-ink-200",
  info: "bg-info-50 text-info-700 ring-info-100",
  success: "bg-success-50 text-success-700 ring-success-100",
  warning: "bg-warning-50 text-warning-700 ring-warning-100",
  danger: "bg-danger-50 text-danger-700 ring-danger-100",
  brand: "bg-brand-50 text-brand-800 ring-brand-100",
  accent: "bg-accent-50 text-accent-800 ring-accent-100",
};

/*
 * Status is never communicated by colour alone: every entry here carries a
 * word and an icon as well, so the badge still reads for someone who cannot
 * distinguish the colours, or who is looking at a printed page.
 */
const STATUS_MAP: Record<string, { tone: Tone; icon: LucideIcon; label?: string }> = {
  // Grievance lifecycle
  new: { tone: "info", icon: CircleDot, label: "New" },
  classified: { tone: "info", icon: CircleDot },
  assigned: { tone: "info", icon: CircleDot },
  acknowledged: { tone: "brand", icon: CheckCircle2 },
  under_investigation: { tone: "brand", icon: CircleDashed, label: "Investigating" },
  action_pending: { tone: "warning", icon: Clock, label: "Action pending" },
  resolved: { tone: "success", icon: CheckCircle2 },
  awaiting_confirmation: { tone: "warning", icon: Clock, label: "Awaiting complainant" },
  closed: { tone: "neutral", icon: CheckCircle2 },
  reopened: { tone: "danger", icon: RotateCcw },
  rejected: { tone: "neutral", icon: XCircle },
  withdrawn: { tone: "neutral", icon: XCircle },

  // Confidentiality
  normal: { tone: "neutral", icon: CircleDot, label: "Standard" },
  confidential: { tone: "danger", icon: Lock, label: "Confidential" },
  anonymous: { tone: "warning", icon: EyeOff, label: "Anonymous" },

  // Commitments
  open: { tone: "info", icon: CircleDot },
  in_progress: { tone: "brand", icon: CircleDashed, label: "In progress" },
  overdue: { tone: "danger", icon: AlertTriangle },
  fulfilled: { tone: "success", icon: CheckCircle2 },
  cancelled: { tone: "neutral", icon: XCircle },
  verified: { tone: "success", icon: CheckCircle2 },
  unverified: { tone: "warning", icon: Clock, label: "Not verified" },
  disputed: { tone: "danger", icon: AlertTriangle },

  // Engagement plans and planned-vs-actual
  planned: { tone: "info", icon: CalendarClock },
  completed: { tone: "success", icon: CheckCircle2 },
  rescheduled: { tone: "warning", icon: CalendarClock },
  postponed: { tone: "warning", icon: PauseCircle },
  missed: { tone: "danger", icon: AlertTriangle },
  on_plan: { tone: "success", icon: CheckCircle2, label: "On plan" },
  early: { tone: "info", icon: CheckCircle2 },
  late: { tone: "warning", icon: Clock },
  unplanned: { tone: "neutral", icon: CircleDashed },
  logged: { tone: "neutral", icon: CheckCircle2 },

  // Register
  active: { tone: "success", icon: CheckCircle2 },
  inactive: { tone: "neutral", icon: CircleDashed },
  merged: { tone: "neutral", icon: Archive },
  archived: { tone: "neutral", icon: Archive },
  escalated: { tone: "danger", icon: ShieldAlert },
  addressed: { tone: "success", icon: CheckCircle2 },

  // SLA clocks
  running: { tone: "info", icon: Timer, label: "On time" },
  at_risk: { tone: "warning", icon: Timer, label: "At risk" },
  breached: { tone: "danger", icon: AlertOctagon, label: "Breached" },
  met: { tone: "success", icon: CheckCircle2, label: "Met" },
  met_late: { tone: "warning", icon: Clock, label: "Met late" },
  paused: { tone: "neutral", icon: PauseCircle, label: "Paused" },

  // Sync
  pending: { tone: "warning", icon: Clock, label: "On this device" },
  uploading: { tone: "info", icon: CircleDashed, label: "Syncing" },
  synced: { tone: "success", icon: CheckCircle2, label: "Synced" },
  failed: { tone: "danger", icon: AlertTriangle, label: "Sync failed" },
  conflict: { tone: "danger", icon: AlertOctagon, label: "Needs a decision" },
  review: { tone: "danger", icon: AlertTriangle, label: "Needs attention" },
};

interface StatusBadgeProps {
  status: string | null | undefined;
  label?: string;
  size?: "sm" | "md";
  className?: string;
  showIcon?: boolean;
}

export function StatusBadge({ status, label, size = "md", className, showIcon = true }: StatusBadgeProps) {
  if (!status) return <span className="text-ink-400">—</span>;

  const entry = STATUS_MAP[status] ?? { tone: "neutral" as Tone, icon: CircleDot };
  const Icon = entry.icon;

  return (
    <span
      className={cn(
        "inline-flex items-center gap-1.5 rounded-full font-medium ring-1 ring-inset whitespace-nowrap",
        size === "sm" ? "px-2 py-0.5 text-[0.6875rem]" : "px-2.5 py-1 text-xs",
        TONES[entry.tone],
        className,
      )}
    >
      {showIcon ? <Icon className={size === "sm" ? "h-3 w-3" : "h-3.5 w-3.5"} aria-hidden /> : null}
      {label ?? entry.label ?? humanise(status)}
    </span>
  );
}

/* Priority and risk share a scale, so they share a component. */
export function PriorityBadge({
  priority,
  size = "md",
  prefix,
  className,
}: {
  priority: string | null | undefined;
  size?: "sm" | "md";
  prefix?: string;
  className?: string;
}) {
  if (!priority) return <span className="text-ink-400">—</span>;

  const tone: Tone = priority === "high" ? "danger" : priority === "medium" ? "warning" : "neutral";
  const bars = priority === "high" ? 3 : priority === "medium" ? 2 : 1;

  return (
    <span
      className={cn(
        "inline-flex items-center gap-1.5 rounded-full font-medium ring-1 ring-inset whitespace-nowrap",
        size === "sm" ? "px-2 py-0.5 text-[0.6875rem]" : "px-2.5 py-1 text-xs",
        TONES[tone],
        className,
      )}
    >
      <span className="flex items-end gap-[2px]" aria-hidden>
        {[1, 2, 3].map((level) => (
          <span
            key={level}
            className={cn(
              "w-[3px] rounded-sm",
              level === 1 ? "h-1.5" : level === 2 ? "h-2.5" : "h-3.5",
              level <= bars ? "bg-current" : "bg-current opacity-25",
            )}
          />
        ))}
      </span>
      {prefix ? `${prefix} ` : ""}
      {humanise(priority)}
    </span>
  );
}

/* Severity 1–5 reads as a level, never as a bare number. */
export function SeverityBadge({
  severity,
  label,
  size = "md",
  className,
}: {
  severity: number | null | undefined;
  label?: string | null;
  size?: "sm" | "md";
  className?: string;
}) {
  if (!severity) {
    return (
      <span className={cn("inline-flex items-center gap-1.5 rounded-full bg-ink-100 px-2.5 py-1 text-xs font-medium text-ink-600 ring-1 ring-inset ring-ink-200", className)}>
        <CircleDashed className="h-3.5 w-3.5" aria-hidden />
        Not assessed
      </span>
    );
  }

  const tone: Tone = severity >= 5 ? "danger" : severity === 4 ? "danger" : severity === 3 ? "warning" : "neutral";
  const Icon = severity >= 4 ? AlertOctagon : severity === 3 ? AlertTriangle : CircleDot;

  return (
    <span
      className={cn(
        "inline-flex items-center gap-1.5 rounded-full font-medium ring-1 ring-inset whitespace-nowrap",
        size === "sm" ? "px-2 py-0.5 text-[0.6875rem]" : "px-2.5 py-1 text-xs",
        TONES[tone],
        className,
      )}
      title={label ?? undefined}
    >
      <Icon className={size === "sm" ? "h-3 w-3" : "h-3.5 w-3.5"} aria-hidden />
      Level {severity}
    </span>
  );
}
