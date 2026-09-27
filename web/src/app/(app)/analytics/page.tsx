"use client";

import { Info } from "lucide-react";
import Link from "next/link";
import { useState } from "react";
import { PeriodPicker, presetPeriods, type Period } from "@/components/app/PeriodPicker";
import {
  useSeriesColour,
  ChartCard,
  ComplianceLineChart,
  DonutChart,
  GroupedBarChart,
  HorizontalBarChart,
  ProgressMeter,
  TrendChart,
} from "@/components/charts/Charts";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { PageHeader, Tabs } from "@/components/ui/DetailLayout";
import { KpiCard, KpiCardSkeleton } from "@/components/ui/KpiCard";
import { SeverityBadge, StatusBadge } from "@/components/ui/StatusBadge";
import { EmptyState, ErrorState, PermissionDenied } from "@/components/ui/States";
import {
  useDisaggregationDashboard,
  useEngagementDashboard,
  useExecutiveDashboard,
  useSeverityDashboard,
  useTimelinessDashboard,
} from "@/lib/api/hooks";
import { formatDate, humanise } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";

export default function AnalyticsPage() {
  const { can } = useSession();
  const [period, setPeriod] = useState<Period>(() => presetPeriods()[1]);

  if (!can("dashboard.view")) return <PermissionDenied what="the dashboards" />;

  const query = { from: period.from, to: period.to };

  return (
    <div>
      <PageHeader
        eyebrow="Module 4 · Dashboards, analytics and reporting"
        title="Analytics"
        description="Five views over the same live records. Every number carries its definition, and every one drills through to the records behind it."
        actions={<PeriodPicker period={period} onChange={setPeriod} />}
      />

      <Tabs
        tabs={[
          { key: "executive", label: "Executive", content: <ExecutiveDashboard query={query} /> },
          { key: "timeliness", label: "Timeliness & SLA", content: <TimelinessDashboard query={query} /> },
          { key: "severity", label: "Severity & escalation", content: <SeverityDashboard query={query} /> },
          { key: "engagement", label: "Engagement & commitments", content: <EngagementDashboard query={query} /> },
          ...(can("dashboard.view_disaggregation")
            ? [{ key: "disaggregation", label: "Who is reached", content: <DisaggregationDashboard query={query} /> }]
            : []),
        ]}
      />
    </div>
  );
}

type Query = { from: string; to: string };

/* ------------------------------------------------------------- executive */

function ExecutiveDashboard({ query }: { query: Query }) {
  const { data, isLoading, isError, refetch } = useExecutiveDashboard(query);
  const dashboard = data?.data;
  /* A second single-series bar chart on the same screen takes the next slot in
     order, so the two are never the same colour. */
  const violetSlot = useSeriesColour(2);

  if (isError) return <ErrorState onRetry={() => void refetch()} />;

  return (
    <div className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {isLoading
          ? Array.from({ length: 8 }).map((_, index) => <KpiCardSkeleton key={index} />)
          : (dashboard?.kpis ?? []).map((kpi) => <KpiCard key={kpi.key} kpi={kpi} />)}
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <ChartCard
          title="Received and closed over time"
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

        <ChartCard
          title="Grievances by category"
          definition="Cases received in the period, grouped by their confirmed category."
          period={dashboard?.period.label}
          empty={!isLoading && (dashboard?.by_category ?? []).length === 0}
        >
          {dashboard?.by_category?.length ? (
            <HorizontalBarChart
              data={dashboard.by_category.map((row) => ({ name: row.category, total: row.total }))}
              labelKey="name"
              valueKey="total"
            />
          ) : null}
        </ChartCard>
      </div>

      <ChartCard
        title="Where cases are coming from"
        definition="Cases received in the period, grouped by the location recorded on the case."
        period={dashboard?.period.label}
        empty={!isLoading && (dashboard?.by_location ?? []).length === 0}
      >
        {dashboard?.by_location?.length ? (
          <HorizontalBarChart
            data={dashboard.by_location.map((row) => ({ name: row.location, total: row.total }))}
            labelKey="name"
            valueKey="total"
            colour={violetSlot}
            height={Math.max(240, dashboard.by_location.length * 34)}
          />
        ) : null}
      </ChartCard>
    </div>
  );
}

/* ------------------------------------------------------------ timeliness */

function TimelinessDashboard({ query }: { query: Query }) {
  const { data, isLoading, isError, refetch } = useTimelinessDashboard(query);
  const dashboard = data?.data;

  if (isError) return <ErrorState onRetry={() => void refetch()} />;

  return (
    <div className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {isLoading
          ? Array.from({ length: 4 }).map((_, index) => <KpiCardSkeleton key={index} />)
          : (dashboard?.kpis ?? []).map((kpi) => <KpiCard key={kpi.key} kpi={kpi} />)}
      </div>

      <div className="grid gap-4 lg:grid-cols-[1.3fr_1fr]">
        <ChartCard
          title="On-time performance over time"
          definition="Finished clocks that were met before their deadline, as a share of all clocks finished in each period."
          period={dashboard?.period.label}
          empty={!isLoading && (dashboard?.trend ?? []).length === 0}
        >
          {dashboard?.trend?.length ? <ComplianceLineChart data={dashboard.trend} /> : null}
        </ChartCard>

        <Card>
          <CardHeader title="By clock" description="Acknowledgement, investigation and resolution, measured separately." />
          <CardBody className="space-y-5">
            {(dashboard?.by_clock ?? []).length === 0 ? (
              <p className="text-sm text-ink-500">No clocks have finished in this period yet.</p>
            ) : (
              (dashboard?.by_clock ?? []).map((row) => (
                <ProgressMeter
                  key={row.clock}
                  label={humanise(row.clock)}
                  value={row.percent}
                  caption={`${row.on_time} of ${row.total} met on time`}
                  tone={row.percent === null ? "brand" : row.percent >= 80 ? "success" : row.percent >= 50 ? "warning" : "danger"}
                />
              ))
            )}

            <div className="grid grid-cols-2 gap-3 border-t border-hairline pt-4">
              <div>
                <p className="sasa-eyebrow">Clocks running</p>
                <p className="tabular mt-1 text-2xl font-semibold text-ink-900">{dashboard?.open_clocks ?? "—"}</p>
              </div>
              <div>
                <p className="sasa-eyebrow">Paused</p>
                <p className="tabular mt-1 text-2xl font-semibold text-ink-900">{dashboard?.paused_clocks ?? "—"}</p>
              </div>
            </div>
          </CardBody>
        </Card>
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <SlaBreakdownTable title="By category" rows={dashboard?.by_category ?? []} />
        <SlaBreakdownTable title="By severity" rows={dashboard?.by_severity ?? []} />
      </div>

      <SlaBreakdownTable title="By owner" rows={dashboard?.by_assignee ?? []} />

      {(dashboard?.paused_detail ?? []).length > 0 ? (
        <Card>
          <CardHeader
            title="Paused clocks"
            description="Pauses are reported, because otherwise pausing becomes a way to make breaches disappear."
          />
          <CardBody>
            <ul className="divide-y divide-hairline">
              {(dashboard?.paused_detail ?? []).map((row, index) => (
                <li key={index} className="flex flex-wrap items-center justify-between gap-3 py-3">
                  <Link href={`/grievances/${row.subject_id}`} className="font-medium text-brand-700 hover:underline">
                    Case #{row.subject_id}
                  </Link>
                  <span className="text-sm text-ink-600">{humanise(row.clock)}</span>
                  <span className="min-w-0 flex-1 text-sm text-ink-600">{row.pause_reason ?? "No reason recorded"}</span>
                  <span className="text-sm text-ink-500">{formatDate(row.paused_at)}</span>
                </li>
              ))}
            </ul>
          </CardBody>
        </Card>
      ) : null}
    </div>
  );
}

function SlaBreakdownTable({
  title,
  rows,
}: {
  title: string;
  rows: Array<{ bucket: string; on_time: number; breached: number; total: number; compliance_percent: number | null }>;
}) {
  return (
    <Card>
      <CardHeader title={title} />
      <CardBody className="p-0 sm:p-0">
        {rows.length === 0 ? (
          <p className="px-6 py-8 text-center text-sm text-ink-500">No cases in this period.</p>
        ) : (
          <div className="sasa-scroll-x overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-hairline bg-surface-sunken">
                  <th className="px-5 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-ink-500">Group</th>
                  <th className="px-3 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-ink-500">On time</th>
                  <th className="px-3 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-ink-500">Breached</th>
                  <th className="px-5 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-ink-500">Compliance</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-hairline">
                {rows.map((row) => (
                  <tr key={row.bucket}>
                    <td className="px-5 py-2.5 text-ink-800">{row.bucket}</td>
                    <td className="tabular px-3 py-2.5 text-right text-ink-700">{row.on_time}</td>
                    <td className="tabular px-3 py-2.5 text-right text-danger-700">{row.breached}</td>
                    <td className="tabular px-5 py-2.5 text-right font-medium text-ink-900">
                      {row.compliance_percent === null ? "—" : `${row.compliance_percent}%`}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </CardBody>
    </Card>
  );
}

/* --------------------------------------------------------------- severity */

function SeverityDashboard({ query }: { query: Query }) {
  const { data, isLoading, isError, refetch } = useSeverityDashboard(query);
  const dashboard = data?.data;

  if (isError) return <ErrorState onRetry={() => void refetch()} />;

  return (
    <div className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-3">
        {isLoading
          ? Array.from({ length: 3 }).map((_, index) => <KpiCardSkeleton key={index} />)
          : (dashboard?.kpis ?? []).map((kpi) => <KpiCard key={kpi.key} kpi={kpi} />)}
      </div>

      <div className="grid gap-4 lg:grid-cols-[1.2fr_1fr]">
        <Card>
          <CardHeader
            title="Severity distribution"
            description="What each level means on this project is configuration, not code."
          />
          <CardBody className="space-y-3">
            {(dashboard?.distribution ?? []).map((level) => {
              const total = (dashboard?.distribution ?? []).reduce((sum, entry) => sum + entry.total, 0);
              const share = total > 0 ? Math.round((level.total / total) * 100) : 0;

              return (
                <div key={level.level} className="rounded-lg border border-hairline p-3.5">
                  <div className="flex items-center justify-between gap-3">
                    <div className="flex items-center gap-2.5">
                      <SeverityBadge severity={level.level} size="sm" />
                      <span className="text-sm font-medium text-ink-800">{level.label.split("—")[1]?.trim()}</span>
                    </div>
                    <span className="tabular text-lg font-semibold text-ink-900">{level.total}</span>
                  </div>
                  <p className="mt-1.5 text-sm text-ink-600">{level.description}</p>
                  <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-ink-100">
                    <div
                      className={
                        level.level >= 4 ? "h-full rounded-full bg-danger-500" : level.level === 3 ? "h-full rounded-full bg-warning-500" : "h-full rounded-full bg-brand-500"
                      }
                      style={{ width: `${share}%` }}
                    />
                  </div>
                </div>
              );
            })}
          </CardBody>
        </Card>

        <div className="space-y-4">
          <ChartCard
            title="Why cases were escalated"
            definition="Escalation events raised in the period, by what triggered them."
            empty={!isLoading && (dashboard?.escalation_reasons ?? []).length === 0}
          >
            {dashboard?.escalation_reasons?.length ? (
              <DonutChart data={dashboard.escalation_reasons.map((row) => ({ name: humanise(row.trigger), value: row.total }))} />
            ) : null}
          </ChartCard>

          <Card>
            <CardHeader title="Time to escalation" description="Average days from a case being received to it being escalated." />
            <CardBody>
              <p className="tabular text-3xl font-semibold text-ink-900">
                {dashboard?.time_to_escalation_days ?? "—"}
                <span className="ml-2 text-base font-normal text-ink-500">days</span>
              </p>
            </CardBody>
          </Card>
        </div>
      </div>

      <Card>
        <CardHeader
          title="Open critical cases"
          description="Level 4 and 5 cases still open, oldest first. These are the ones that become incidents."
        />
        <CardBody className="p-0 sm:p-0">
          {(dashboard?.open_critical ?? []).length === 0 ? (
            <EmptyState title="No open critical cases" description="Nothing at Level 4 or 5 is open on this project right now." />
          ) : (
            <ul className="divide-y divide-hairline">
              {(dashboard?.open_critical ?? []).map((row) => (
                <li key={row.id}>
                  <Link href={`/grievances/${row.id}`} className="flex flex-wrap items-center gap-3 px-5 py-3.5 transition hover:bg-brand-50/50">
                    <span className="font-mono text-xs text-ink-500">{row.reference}</span>
                    <span className="min-w-0 flex-1 font-medium text-ink-900">{row.title}</span>
                    <SeverityBadge severity={row.severity} size="sm" />
                    <StatusBadge status={row.status} size="sm" />
                    {row.resolution_sla_state ? <StatusBadge status={row.resolution_sla_state} size="sm" /> : null}
                    <span className="tabular text-sm text-ink-500">{row.days_open}d open</span>
                    <span className="text-sm text-ink-600">{row.assignee ?? "Unassigned"}</span>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </CardBody>
      </Card>
    </div>
  );
}

/* ------------------------------------------------------------- engagement */

function EngagementDashboard({ query }: { query: Query }) {
  const { data, isLoading, isError, refetch } = useEngagementDashboard(query);
  const dashboard = data?.data;

  if (isError) return <ErrorState onRetry={() => void refetch()} />;

  const plannedVsActual = Object.entries(dashboard?.planned_vs_actual ?? {}).map(([key, value]) => ({
    name: humanise(key),
    value,
  }));

  const commitmentStatus = Object.entries(dashboard?.commitment_status ?? {}).map(([key, value]) => ({
    name: humanise(key),
    value,
  }));

  return (
    <div className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {isLoading
          ? Array.from({ length: 6 }).map((_, index) => <KpiCardSkeleton key={index} />)
          : (dashboard?.kpis ?? []).map((kpi) => <KpiCard key={kpi.key} kpi={kpi} />)}
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <ChartCard
          title="Planned against actual"
          definition="Logged engagements by how they fell against their plan. Unplanned means no plan was linked."
          empty={!isLoading && plannedVsActual.length === 0}
        >
          {plannedVsActual.length ? <DonutChart data={plannedVsActual} /> : null}
        </ChartCard>

        <ChartCard
          title="Commitments by status"
          definition="Every commitment on the project, by where it has got to."
          empty={!isLoading && commitmentStatus.length === 0}
        >
          {commitmentStatus.length ? <DonutChart data={commitmentStatus} /> : null}
        </ChartCard>
      </div>

      <ChartCard
        title="Engagement over time"
        definition="Engagements planned against engagements logged, and the attendance recorded at them."
        empty={!isLoading && (dashboard?.trend ?? []).length === 0}
      >
        {dashboard?.trend?.length ? (
          <GroupedBarChart
            data={dashboard.trend}
            labelKey="bucket"
            series={[
              { key: "planned", label: "Planned" },
              { key: "completed", label: "Held" },
            ]}
          />
        ) : null}
      </ChartCard>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader title="Overdue commitments" description="Oldest first — these are what a community remembers." />
          <CardBody className="p-0 sm:p-0">
            {(dashboard?.overdue_commitments ?? []).length === 0 ? (
              <EmptyState title="Nothing overdue" description="Every commitment on this project is inside its due date." />
            ) : (
              <ul className="divide-y divide-hairline">
                {(dashboard?.overdue_commitments ?? []).map((row) => (
                  <li key={row.id}>
                    <Link href={`/commitments/${row.id}`} className="block px-5 py-3.5 transition hover:bg-brand-50/50">
                      <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0">
                          <span className="font-mono text-xs text-ink-500">{row.reference}</span>
                          <p className="mt-0.5 text-ink-900">{row.commitment_text}</p>
                          <p className="mt-0.5 text-sm text-ink-500">
                            {row.owner ?? "Unassigned"} · {row.stakeholders.join(", ") || "No stakeholder linked"}
                          </p>
                        </div>
                        <span className="tabular shrink-0 rounded-full bg-danger-50 px-2 py-0.5 text-xs font-medium text-danger-700">
                          {row.days_overdue}d over
                        </span>
                      </div>
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader
            title="Overdue promises to people with an open case"
            description="An overdue commitment to a stakeholder who also has an open grievance is the reliable precursor to a dispute."
          />
          <CardBody className="p-0 sm:p-0">
            {(dashboard?.commitments_with_open_grievances ?? []).length === 0 ? (
              <EmptyState title="None right now" description="No stakeholder with an open case is also owed an overdue commitment." />
            ) : (
              <ul className="divide-y divide-hairline">
                {(dashboard?.commitments_with_open_grievances ?? []).map((row) => (
                  <li key={row.id}>
                    <Link href={`/commitments/${row.id}`} className="block px-5 py-3.5 transition hover:bg-danger-50/50">
                      <span className="font-mono text-xs text-ink-500">{row.reference}</span>
                      <p className="mt-0.5 text-ink-900">{row.commitment_text}</p>
                      <p className="mt-0.5 text-sm text-danger-700">
                        {row.stakeholders.map((stakeholder) => stakeholder.name).join(", ")}
                      </p>
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </CardBody>
        </Card>
      </div>

      <ChartCard
        title="How engagement is carried out"
        definition="Logged engagements and the attendance they reached, by method."
        empty={!isLoading && (dashboard?.engagement_methods ?? []).length === 0}
      >
        {dashboard?.engagement_methods?.length ? (
          <GroupedBarChart
            data={dashboard.engagement_methods.map((row) => ({
              method: humanise(row.method),
              total: row.total,
              attendance: row.attendance,
            }))}
            labelKey="method"
            series={[
              { key: "total", label: "Engagements" },
              { key: "attendance", label: "People reached" },
            ]}
          />
        ) : null}
      </ChartCard>
    </div>
  );
}

/* --------------------------------------------------------- disaggregation */

function DisaggregationDashboard({ query }: { query: Query }) {
  const [dimension, setDimension] = useState("gender");
  const [against, setAgainst] = useState("category");

  const { data, isLoading, isError, refetch } = useDisaggregationDashboard({ ...query, dimension, against });
  const dashboard = data?.data;

  if (isError) return <ErrorState onRetry={() => void refetch()} />;

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader
          title="Who is being reached, and who is being missed"
          description="The evidence lenders and standards bodies ask for, and the hardest thing to reconstruct after the fact."
          action={
            <div className="flex flex-wrap gap-2">
              <select value={dimension} onChange={(event) => setDimension(event.target.value)} className="sasa-field h-10 py-0 text-sm">
                <option value="gender">Gender</option>
                <option value="age_band">Age band</option>
                <option value="disability">Disability</option>
                <option value="employment">Employment</option>
                <option value="language">Language</option>
              </select>
              <select value={against} onChange={(event) => setAgainst(event.target.value)} className="sasa-field h-10 py-0 text-sm">
                <option value="category">against category</option>
                <option value="severity">against severity</option>
                <option value="channel">against channel</option>
                <option value="timeliness">against open or closed</option>
              </select>
            </div>
          }
        />
        <CardBody className="p-0 sm:p-0">
          {isLoading ? (
            <p className="px-6 py-10 text-center text-sm text-ink-500">Building the cross-tab…</p>
          ) : (dashboard?.rows ?? []).length === 0 ? (
            <EmptyState
              title="Nothing to cross-tabulate yet"
              description="A dashboard cannot disaggregate on data that has not been collected. Add the optional dimensions to your intake forms in Configuration, and only where it is lawful and voluntary."
            />
          ) : (
            <div className="sasa-scroll-x overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-hairline bg-surface-sunken">
                    <th className="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-500">
                      {humanise(dashboard?.dimension)}
                    </th>
                    {(dashboard?.columns ?? []).map((column) => (
                      <th key={column} className="px-3 py-3 text-right text-xs font-semibold uppercase tracking-wide text-ink-500">
                        {column}
                      </th>
                    ))}
                    <th className="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-ink-500">Total</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-hairline">
                  {(dashboard?.rows ?? []).map((row) => (
                    <tr key={row.dimension_value}>
                      <td className="px-5 py-3 font-medium text-ink-800">{humanise(row.dimension_value)}</td>
                      {(dashboard?.columns ?? []).map((column) => {
                        const value = row.cells[column];

                        return (
                          <td key={column} className="tabular px-3 py-3 text-right">
                            {value === undefined ? (
                              <span className="text-ink-300">0</span>
                            ) : value === null ? (
                              <span
                                className="rounded bg-ink-100 px-1.5 py-0.5 text-xs text-ink-500"
                                title={dashboard?.suppression_note}
                              >
                                &lt;{dashboard?.minimum_cell_size}
                              </span>
                            ) : (
                              <span className="text-ink-800">{value}</span>
                            )}
                          </td>
                        );
                      })}
                      <td className="tabular px-5 py-3 text-right font-semibold text-ink-900">{row.total}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardBody>
        {dashboard && dashboard.suppressed_cells > 0 ? (
          <div className="flex items-start gap-2.5 border-t border-hairline bg-warning-50 px-5 py-3.5 text-sm text-warning-800">
            <Info className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
            <p>
              {dashboard.suppressed_cells} {dashboard.suppressed_cells === 1 ? "cell is" : "cells are"} hidden.{" "}
              {dashboard.suppression_note}
            </p>
          </div>
        ) : null}
      </Card>

      <Card>
        <CardHeader
          title="Uptake equity"
          description="Whether the people the project affects most are the ones actually using the grievance mechanism."
        />
        <CardBody className="space-y-5">
          {(dashboard?.uptake_equity ?? []).length === 0 ? (
            <p className="text-sm text-ink-500">Not enough register data yet to compare shares.</p>
          ) : (
            (dashboard?.uptake_equity ?? []).map((row) => (
              <div key={row.group}>
                <p className="font-medium text-ink-900">{row.group}</p>
                <div className="mt-3 grid gap-4 sm:grid-cols-3">
                  <ProgressMeter label="Share of the register" value={row.population_share_percent} tone="brand" />
                  <ProgressMeter
                    label="Share of grievances"
                    value={row.grievance_share_percent}
                    tone={row.grievance_gap !== null && row.grievance_gap < -5 ? "warning" : "success"}
                    caption={
                      row.grievance_gap === null
                        ? undefined
                        : row.grievance_gap < 0
                          ? `${Math.abs(row.grievance_gap)} points below their share of the register`
                          : `${row.grievance_gap} points above`
                    }
                  />
                  <ProgressMeter
                    label="Share of attendance"
                    value={row.engagement_share_percent}
                    tone={row.engagement_gap !== null && row.engagement_gap < -5 ? "warning" : "success"}
                    caption={
                      row.engagement_gap === null
                        ? undefined
                        : row.engagement_gap < 0
                          ? `${Math.abs(row.engagement_gap)} points below`
                          : `${row.engagement_gap} points above`
                    }
                  />
                </div>
                <p className="mt-3 rounded-lg bg-surface-sunken p-3 text-sm text-ink-600">{row.note}</p>
              </div>
            ))
          )}
        </CardBody>
      </Card>
    </div>
  );
}
