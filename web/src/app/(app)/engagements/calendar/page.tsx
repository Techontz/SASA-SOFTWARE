"use client";

import { CalendarClock, ChevronLeft, ChevronRight } from "lucide-react";
import Link from "next/link";
import { useMemo, useState } from "react";
import { EngagementNav } from "@/components/app/EngagementNav";
import { Button } from "@/components/ui/Button";
import { PageHeader } from "@/components/ui/DetailLayout";
import { PriorityBadge, StatusBadge } from "@/components/ui/StatusBadge";
import { EmptyState, LoadingState } from "@/components/ui/States";
import { useEngagementCalendar } from "@/lib/api/hooks";
import { cn, formatDate, humanise } from "@/lib/utils";

/** A month at a glance, and a list below it for a phone. */
const EMPTY_PLANS: NonNullable<ReturnType<typeof useEngagementCalendar>["data"]>["data"] = [];

export default function EngagementCalendarPage() {
  const [month, setMonth] = useState(() => {
    const now = new Date();
    return new Date(now.getFullYear(), now.getMonth(), 1);
  });

  const from = new Date(month.getFullYear(), month.getMonth(), 1).toISOString().slice(0, 10);
  const to = new Date(month.getFullYear(), month.getMonth() + 1, 0).toISOString().slice(0, 10);

  const { data, isLoading } = useEngagementCalendar(from, to);
  /* A fresh [] every render would rebuild the map below it each time. */
  const plans = data?.data ?? EMPTY_PLANS;

  const byDate = useMemo(() => {
    const map = new Map<string, typeof plans>();
    plans.forEach((plan) => {
      if (!plan.date) return;
      map.set(plan.date, [...(map.get(plan.date) ?? []), plan]);
    });
    return map;
  }, [plans]);

  const firstWeekday = (new Date(month.getFullYear(), month.getMonth(), 1).getDay() + 6) % 7; // Monday first
  const daysInMonth = new Date(month.getFullYear(), month.getMonth() + 1, 0).getDate();
  const today = new Date().toISOString().slice(0, 10);

  return (
    <div>
      <PageHeader
        eyebrow="Module 2 · Stakeholder management"
        title="Engagement calendar"
        description="What is planned, and when. Anything still showing as planned after its date has passed becomes a missed engagement on the dashboards."
      />

      <EngagementNav />

      <div className="sasa-card overflow-hidden">
        <div className="flex items-center justify-between gap-3 border-b border-hairline px-5 py-4">
          <h2 className="text-lg font-semibold text-ink-900">
            {month.toLocaleDateString("en-GB", { month: "long", year: "numeric" })}
          </h2>
          <div className="flex items-center gap-2">
            <Button
              size="sm"
              variant="secondary"
              icon={<ChevronLeft className="h-4 w-4" />}
              onClick={() => setMonth(new Date(month.getFullYear(), month.getMonth() - 1, 1))}
              aria-label="Previous month"
            />
            <Button size="sm" variant="secondary" onClick={() => setMonth(new Date(new Date().getFullYear(), new Date().getMonth(), 1))}>
              Today
            </Button>
            <Button
              size="sm"
              variant="secondary"
              icon={<ChevronRight className="h-4 w-4" />}
              onClick={() => setMonth(new Date(month.getFullYear(), month.getMonth() + 1, 1))}
              aria-label="Next month"
            />
          </div>
        </div>

        {isLoading ? (
          <LoadingState label="Loading the calendar" />
        ) : (
          <>
            {/* Month grid on larger screens. */}
            <div className="hidden md:block">
              <div className="grid grid-cols-7 border-b border-hairline bg-surface-sunken">
                {["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"].map((day) => (
                  <div key={day} className="px-3 py-2 text-center text-xs font-semibold uppercase tracking-wide text-ink-500">
                    {day}
                  </div>
                ))}
              </div>
              <div className="grid grid-cols-7">
                {Array.from({ length: firstWeekday }).map((_, index) => (
                  <div key={`pad-${index}`} className="min-h-[7rem] border-b border-r border-hairline bg-surface-sunken/50" />
                ))}
                {Array.from({ length: daysInMonth }).map((_, index) => {
                  const day = index + 1;
                  const date = new Date(month.getFullYear(), month.getMonth(), day);
                  const key = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(day).padStart(2, "0")}`;
                  const entries = byDate.get(key) ?? [];
                  const isToday = key === today;

                  return (
                    <div key={key} className="min-h-[7rem] border-b border-r border-hairline p-2">
                      <span
                        className={cn(
                          "tabular inline-flex h-6 w-6 items-center justify-center rounded-full text-xs font-medium",
                          isToday ? "bg-primary text-on-primary" : "text-ink-600",
                        )}
                      >
                        {day}
                      </span>
                      <div className="mt-1.5 space-y-1">
                        {entries.slice(0, 3).map((entry) => (
                          <Link
                            key={entry.id}
                            href={entry.href}
                            className={cn(
                              "block truncate rounded px-1.5 py-1 text-[0.6875rem] font-medium transition",
                              entry.status === "missed"
                                ? "bg-danger-50 text-danger-700 hover:bg-danger-100"
                                : entry.status === "completed"
                                  ? "bg-success-50 text-success-700 hover:bg-success-100"
                                  : "bg-brand-50 text-brand-800 hover:bg-brand-100",
                            )}
                            title={entry.title}
                          >
                            {entry.title}
                          </Link>
                        ))}
                        {entries.length > 3 ? (
                          <p className="px-1.5 text-[0.6875rem] text-ink-500">+{entries.length - 3} more</p>
                        ) : null}
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>

            {/* A simple list on a phone. */}
            <ul className="divide-y divide-hairline md:hidden">
              {plans.length === 0 ? (
                <li>
                  <EmptyState icon={CalendarClock} title="Nothing planned this month" />
                </li>
              ) : (
                plans.map((plan) => (
                  <li key={plan.id}>
                    <Link href={plan.href} className="block px-4 py-4 transition active:bg-brand-50">
                      <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0">
                          <p className="font-medium text-ink-900">{plan.title}</p>
                          <p className="mt-0.5 text-sm text-ink-600">
                            {formatDate(plan.date)} · {humanise(plan.method)}
                          </p>
                          <p className="mt-0.5 text-sm text-ink-500">{plan.location ?? plan.stakeholder}</p>
                        </div>
                        <div className="flex shrink-0 flex-col items-end gap-1.5">
                          <StatusBadge status={plan.status} size="sm" />
                          <PriorityBadge priority={plan.priority} size="sm" />
                        </div>
                      </div>
                    </Link>
                  </li>
                ))
              )}
            </ul>
          </>
        )}
      </div>
    </div>
  );
}
