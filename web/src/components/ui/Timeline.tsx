import type { ReactNode } from "react";
import { cn } from "@/lib/utils";

export interface TimelineItem {
  id: string | number;
  icon?: ReactNode;
  title: ReactNode;
  meta?: ReactNode;
  body?: ReactNode;
  at?: string | null;
  tone?: "neutral" | "brand" | "success" | "warning" | "danger";
}

const TONE_DOT: Record<NonNullable<TimelineItem["tone"]>, string> = {
  neutral: "bg-ink-200 text-ink-600 ring-ink-100",
  brand: "bg-brand-100 text-brand-700 ring-brand-50",
  success: "bg-success-100 text-success-700 ring-success-50",
  warning: "bg-warning-100 text-warning-700 ring-warning-50",
  danger: "bg-danger-100 text-danger-700 ring-danger-50",
};

/** The case history, read top to bottom, with the story visible at a glance. */
export function Timeline({ items, className }: { items: TimelineItem[]; className?: string }) {
  if (items.length === 0) {
    return <p className="px-1 py-6 text-center text-sm text-ink-500">Nothing has happened here yet.</p>;
  }

  return (
    <ol className={cn("relative", className)}>
      {items.map((item, index) => (
        <li key={item.id} className="relative flex gap-4 pb-6 last:pb-0">
          {index < items.length - 1 ? (
            <span className="absolute left-[15px] top-9 h-[calc(100%-1.5rem)] w-px bg-hairline" aria-hidden />
          ) : null}
          <span
            className={cn(
              "relative z-10 mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full ring-4",
              TONE_DOT[item.tone ?? "neutral"],
            )}
            aria-hidden
          >
            {item.icon}
          </span>
          <div className="min-w-0 flex-1 pt-1">
            <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
              <p className="font-medium text-ink-900">{item.title}</p>
              {item.at ? <time className="shrink-0 text-xs text-ink-500">{item.at}</time> : null}
            </div>
            {item.meta ? <div className="mt-0.5 text-sm text-ink-500">{item.meta}</div> : null}
            {item.body ? <div className="mt-2 text-sm leading-relaxed text-ink-700">{item.body}</div> : null}
          </div>
        </li>
      ))}
    </ol>
  );
}
