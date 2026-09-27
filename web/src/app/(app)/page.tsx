"use client";

import {
  Activity,
  ArrowRight,
  CalendarClock,
  ClipboardCheck,
  Handshake,
  Users,
} from "lucide-react";
import Link from "next/link";
import { useState } from "react";
import { CriticalActions } from "@/components/app/CriticalActions";
import { PeriodPicker, presetPeriods, type Period } from "@/components/app/PeriodPicker";
import { ChartCard, TrendChart } from "@/components/charts/Charts";
import { Card, CardHeader } from "@/components/ui/Card";
import { PageHeader } from "@/components/ui/DetailLayout";
import { KpiCard, KpiCardSkeleton } from "@/components/ui/KpiCard";
import { ErrorState } from "@/components/ui/States";
import { useLandingDashboard } from "@/lib/api/hooks";
import { QUICK_ACTIONS } from "@/lib/navigation";
import { cn, formatRelative, humanise } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";

/**
 * The landing view changes by role, because the first question of the day is
 * different for each — without building six different applications.
 */
export default function DashboardPage() {
  const { user, project, canAny } = useSession();
  const [period, setPeriod] = useState<Period>(() => presetPeriods()[1]);

  const { data, isLoading, isError, refetch } = useLandingDashboard({
    from: period.from,
    to: period.to,
  });

  const dashboard = data?.data;
  const quickActions = QUICK_ACTIONS.filter((action) => !action.permissions?.length || canAny(...action.permissions));

  const firstName = user?.name.split(" ")[0] ?? "there";
  const hour = new Date().getHours();
  const greeting = hour < 12 ? "Good morning" : hour < 17 ? "Good afternoon" : "Good evening";

  return (
    <div className="pb-4">
      <PageHeader
        eyebrow={project?.name}
        title={`${greeting}, ${firstName}`}
        description={dashboard?.question ?? "Loading your project…"}
        actions={<PeriodPicker period={period} onChange={setPeriod} />}
      />

      {/* --------------------------- quick actions --------------------- */}
      {quickActions.length > 0 ? (
        <div className="mb-8 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {quickActions.map((action) => {
            const Icon = action.icon;

            return (
              <Link
                key={action.href}
                href={action.href}
                className={cn(
                  "group flex items-start gap-3.5 rounded-xl border p-4 transition-all duration-[var(--duration-base)] hover:-translate-y-0.5 hover:shadow-[var(--shadow-card)]",
                  action.tone === "accent"
                    ? "border-accent-200 bg-accent-50 hover:border-accent-400"
                    : "border-hairline bg-surface hover:border-brand-300",
                )}
              >
                <span
                  className={cn(
                    "flex h-10 w-10 shrink-0 items-center justify-center rounded-lg",
                    action.tone === "accent" ? "bg-accent-600 text-white" : "bg-brand-100 text-brand-700",
                  )}
                >
                  <Icon className="h-5 w-5" aria-hidden />
                </span>
                <span className="min-w-0">
                  <span className="block font-medium text-ink-900">{action.label}</span>
                  <span className="mt-0.5 block text-sm leading-snug text-ink-600">{action.description}</span>
                </span>
              </Link>
            );
          })}
        </div>
      ) : null}

      {/* --------------------------- what needs attention --------------- */}
      <section className="mb-8">
        <h2 className="mb-3 text-[1.0625rem] font-semibold text-ink-900">What needs attention</h2>
        {isLoading ? (
          <div className="grid gap-3 sm:grid-cols-2">
            {[0, 1, 2, 3].map((index) => (
              <div key={index} className="sasa-card h-[5.5rem] animate-pulse bg-ink-50" />
            ))}
          </div>
        ) : isError ? (
          <Card>
            <ErrorState onRetry={() => void refetch()} />
          </Card>
        ) : (
          <CriticalActions actions={dashboard?.critical_actions ?? []} />
        )}
      </section>

      {/* --------------------------- my work ---------------------------- */}
      {dashboard?.my_work ? <MyWork work={dashboard.my_work} /> : null}

      {/* --------------------------- headline numbers ------------------- */}
      <section className="mb-8">
        <div className="mb-3 flex items-baseline justify-between gap-4">
          <h2 className="text-[1.0625rem] font-semibold text-ink-900">The project right now</h2>
          <Link href="/analytics" className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:text-brand-900">
            All dashboards
            <ArrowRight className="h-3.5 w-3.5" aria-hidden />
          </Link>
        </div>

        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {isLoading
            ? [0, 1, 2, 3, 4, 5, 6, 7].map((index) => <KpiCardSkeleton key={index} />)
            : (dashboard?.kpis ?? []).slice(0, 8).map((kpi) => <KpiCard key={kpi.key} kpi={kpi} />)}
        </div>
      </section>

      {/* --------------------------- trend + activity -------------------- */}
      <div className="grid gap-4 lg:grid-cols-[1.6fr_1fr]">
        <ChartCard
          title="Grievances received and closed"
          definition="Cases by the period they were received in, against the cases closed in the same period."
          period={dashboard?.period.label}
          empty={!isLoading && (dashboard?.trend ?? []).length === 0}
        >
          {dashboard?.trend?.length ? (
            <TrendChart
              data={dashboard.trend}
              series={[
                { key: "received", label: "Received" },
                { key: "closed", label: "Closed" },
              ]}
            />
          ) : null}
        </ChartCard>

        <Card className="flex flex-col overflow-hidden">
          <CardHeader
            title="Recent activity"
            description="The last things that happened on this project."
            action={
              <Link href="/activity" className="text-sm font-medium text-brand-700 hover:text-brand-900">
                All
              </Link>
            }
          />
          <ul className="flex-1 divide-y divide-hairline overflow-y-auto">
            {(dashboard?.recent_activity ?? []).length === 0 ? (
              <li className="px-5 py-10 text-center text-sm text-ink-500">
                Nothing recorded yet. It will appear here as your team works.
              </li>
            ) : (
              (dashboard?.recent_activity ?? []).map((entry) => (
                <li key={entry.id} className="flex gap-3 px-5 py-3.5">
                  <span className="mt-1 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-700">
                    <Activity className="h-3.5 w-3.5" aria-hidden />
                  </span>
                  <div className="min-w-0 flex-1">
                    <p className="text-sm text-ink-800">
                      {entry.reference ? (
                        <span className="font-medium text-ink-900">{entry.reference} </span>
                      ) : null}
                      {entry.summary ?? humanise(entry.action)}
                    </p>
                    <p className="mt-0.5 text-xs text-ink-500">
                      {entry.user ?? "System"} · {formatRelative(entry.at)}
                    </p>
                  </div>
                </li>
              ))
            )}
          </ul>
        </Card>
      </div>
    </div>
  );
}

/** The personal queue. What this specific person owns, right now. */
function MyWork({ work }: { work: Record<string, number> }) {
  const items = [
    { key: "assigned_open_cases", label: "Open cases assigned to me", href: "/grievances?assignee=me&status=open", icon: ClipboardCheck },
    { key: "cases_breaching", label: "My cases at risk or breached", href: "/grievances?assignee=me&sla=breached", icon: ClipboardCheck, urgent: true },
    { key: "commitments_overdue", label: "My overdue commitments", href: "/commitments?overdue=1", icon: Handshake, urgent: true },
    { key: "commitments_due_this_week", label: "My commitments due this week", href: "/commitments?upcoming=7", icon: Handshake },
    { key: "engagements_this_week", label: "My engagements this week", href: "/engagements/plans?upcoming=7", icon: CalendarClock },
    { key: "stakeholders_due_review", label: "My register entries due for review", href: "/stakeholders?review=due", icon: Users },
  ].filter((item) => (work[item.key] ?? 0) > 0);

  if (items.length === 0) return null;

  return (
    <section className="mb-8">
      <h2 className="mb-3 text-[1.0625rem] font-semibold text-ink-900">Assigned to you</h2>
      <div className="sasa-card divide-y divide-hairline overflow-hidden">
        {items.map((item) => {
          const Icon = item.icon;

          return (
            <Link
              key={item.key}
              href={item.href}
              className="group flex items-center gap-4 px-5 py-3.5 transition hover:bg-brand-50/60"
            >
              <span
                className={cn(
                  "flex h-9 w-9 shrink-0 items-center justify-center rounded-lg",
                  item.urgent ? "bg-danger-50 text-danger-600" : "bg-ink-100 text-ink-600",
                )}
              >
                <Icon className="h-4 w-4" aria-hidden />
              </span>
              <span className="tabular w-10 shrink-0 text-xl font-semibold text-ink-900">{work[item.key]}</span>
              <span className="min-w-0 flex-1 text-sm text-ink-700">{item.label}</span>
              <ArrowRight className="h-4 w-4 shrink-0 text-ink-400 transition group-hover:translate-x-0.5 group-hover:text-ink-700" aria-hidden />
            </Link>
          );
        })}
      </div>
    </section>
  );
}
