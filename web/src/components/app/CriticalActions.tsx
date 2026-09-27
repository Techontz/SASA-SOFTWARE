import { AlertOctagon, AlertTriangle, ArrowRight, CheckCircle2, Info } from "lucide-react";
import Link from "next/link";
import { cn } from "@/lib/utils";
import type { CriticalAction } from "@/types/api";

const TONE = {
  danger: {
    icon: AlertOctagon,
    wrapper: "border-danger-500/25 bg-danger-50 hover:border-danger-500/40",
    iconClass: "bg-danger-100 text-danger-700",
    count: "text-danger-700",
  },
  warning: {
    icon: AlertTriangle,
    wrapper: "border-warning-500/25 bg-warning-50 hover:border-warning-500/40",
    iconClass: "bg-warning-100 text-warning-700",
    count: "text-warning-700",
  },
  info: {
    icon: Info,
    wrapper: "border-brand-500/20 bg-brand-50 hover:border-brand-500/40",
    iconClass: "bg-brand-100 text-brand-700",
    count: "text-brand-700",
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
    <ul className="grid gap-3 sm:grid-cols-2">
      {actions.map((action) => {
        const tone = TONE[action.severity] ?? TONE.info;
        const Icon = tone.icon;

        return (
          <li key={action.kind}>
            <Link
              href={action.href}
              className={cn(
                "group flex items-center gap-4 rounded-xl border p-4 transition-all duration-[var(--duration-base)] hover:-translate-y-0.5 hover:shadow-[var(--shadow-card)]",
                tone.wrapper,
              )}
            >
              <span className={cn("flex h-11 w-11 shrink-0 items-center justify-center rounded-full", tone.iconClass)}>
                <Icon className="h-5 w-5" aria-hidden />
              </span>
              <span className="min-w-0 flex-1">
                <span className={cn("tabular block text-2xl font-semibold leading-none", tone.count)}>
                  {action.count}
                </span>
                <span className="mt-1 block text-sm font-medium text-ink-800">{action.label}</span>
              </span>
              <ArrowRight className="h-4 w-4 shrink-0 text-ink-400 transition group-hover:translate-x-0.5 group-hover:text-ink-700" aria-hidden />
            </Link>
          </li>
        );
      })}
    </ul>
  );
}
