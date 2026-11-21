import { AlertOctagon, AlertTriangle, CheckCircle2, ChevronRight, Info } from "lucide-react";
import Link from "next/link";
import { cn } from "@/lib/utils";
import type { CriticalAction } from "@/types/api";

const TONE = {
  danger: {
    icon: AlertOctagon,
    iconClass: "bg-danger-50 text-danger-600",
    count: "text-danger-600",
  },
  warning: {
    icon: AlertTriangle,
    iconClass: "bg-warning-50 text-warning-600",
    count: "text-warning-600",
  },
  info: {
    icon: Info,
    iconClass: "bg-brand-50 text-brand-600",
    count: "text-ink-900",
  },
};

/**
 * The answer to "what needs my decision" — ordered by consequence, with the
 * count on the left and a route straight into the underlying records.
 */
export function CriticalActions({ actions }: { actions: CriticalAction[] }) {
  if (actions.length === 0) {
    return (
      <div className="sasa-card flex items-center gap-4 p-5">
        <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-success-50 text-success-600">
          <CheckCircle2 className="h-5 w-5" aria-hidden />
        </span>
        <div>
          <p className="font-medium text-ink-900">Nothing is overdue</p>
          <p className="mt-0.5 text-sm text-ink-600">
            No breached deadlines, unowned cases or overdue commitments on this project right now.
          </p>
        </div>
      </div>
    );
  }

  return (
    <ul className="sasa-card divide-y divide-hairline overflow-hidden">
      {actions.map((action) => {
        const tone = TONE[action.severity] ?? TONE.info;
        const Icon = tone.icon;

        return (
          <li key={action.kind}>
            <Link
              href={action.href}
              className="group flex items-center gap-4 px-5 py-4 transition-colors duration-[var(--duration-base)] hover:bg-surface-sunken sm:px-6"
            >
              <span className={cn("flex h-10 w-10 shrink-0 items-center justify-center rounded-xl", tone.iconClass)}>
                <Icon className="h-5 w-5" aria-hidden />
              </span>
              <span className="min-w-0 flex-1 text-[0.9375rem] font-medium text-ink-900">{action.label}</span>
              <span className={cn("tabular text-xl font-semibold tracking-[-0.02em]", tone.count)}>{action.count}</span>
              <ChevronRight className="h-4 w-4 shrink-0 text-ink-400 transition group-hover:translate-x-0.5" aria-hidden />
            </Link>
          </li>
        );
      })}
    </ul>
  );
}
