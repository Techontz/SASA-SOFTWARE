"use client";

import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Legend,
  Line,
  LineChart,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import { useChartTheme } from "@/lib/chartTheme";
import { cn } from "@/lib/utils";

/*
 * Charts answer a question or they are not drawn. Every one carries a title,
 * the period it covers, and a plain-language definition of what it counts.
 */

/*
 * Series colour lives in lib/chartTheme.ts, which has the validation notes and
 * both themes' steps. Charts read it through useChartTheme() rather than
 * importing a constant, because the values differ per theme and Recharts needs
 * real hex rather than a CSS variable.
 *
 * `useSeriesColour` is for the rare caller outside this file that needs to name
 * one slot — it keeps slot order in one place.
 */
export function useSeriesColour(slot: number): string {
  const { series } = useChartTheme();
  return series[slot % series.length];
}

export function ChartCard({
  title,
  definition,
  period,
  action,
  children,
  className,
  empty,
}: {
  title: string;
  definition?: string;
  period?: string;
  action?: React.ReactNode;
  children: React.ReactNode;
  className?: string;
  empty?: boolean;
}) {
  return (
    <section className={cn("sasa-card flex flex-col overflow-hidden", className)}>
      <header className="flex items-start justify-between gap-4 px-5 pt-5">
        <div className="min-w-0">
          <h3 className="text-[0.9375rem] font-semibold text-ink-900">{title}</h3>
          {definition ? <p className="mt-1 text-xs leading-relaxed text-ink-500">{definition}</p> : null}
          {period ? <p className="mt-1 text-xs text-ink-400">{period}</p> : null}
        </div>
        {action}
      </header>
      <div className="min-h-[16rem] flex-1 px-2 pb-4 pt-4">
        {empty ? (
          <p className="flex h-full min-h-[14rem] items-center justify-center px-6 text-center text-sm text-ink-500">
            No records in this period yet.
          </p>
        ) : (
          children
        )}
      </div>
    </section>
  );
}

/* The legend's own text must wear a text token, never a series colour. */
const LEGEND_STYLE = { fontSize: 12, paddingTop: 8 } as const;

export function TrendChart({
  data,
  series,
}: {
  data: Array<Record<string, string | number>>;
  series: Array<{ key: string; label: string; colour?: string }>;
}) {
  const theme = useChartTheme();

  return (
    <ResponsiveContainer width="100%" height={260}>
      <AreaChart data={data} margin={{ top: 4, right: 12, left: -18, bottom: 0 }}>
        <defs>
          {series.map((entry, index) => (
            <linearGradient key={entry.key} id={`fill-${entry.key}`} x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor={entry.colour ?? theme.series[index]} stopOpacity={0.22} />
              <stop offset="100%" stopColor={entry.colour ?? theme.series[index]} stopOpacity={0.02} />
            </linearGradient>
          ))}
        </defs>
        <CartesianGrid strokeDasharray="3 3" stroke={theme.grid} vertical={false} />
        <XAxis dataKey="bucket" {...theme.axisProps} />
        <YAxis allowDecimals={false} width={44} {...theme.axisProps} />
        <Tooltip {...theme.tooltip} />
        <Legend iconType="circle" iconSize={8} wrapperStyle={LEGEND_STYLE} />
        {series.map((entry, index) => (
          <Area
            key={entry.key}
            type="monotone"
            dataKey={entry.key}
            name={entry.label}
            stroke={entry.colour ?? theme.series[index]}
            strokeWidth={2}
            fill={`url(#fill-${entry.key})`}
          />
        ))}
      </AreaChart>
    </ResponsiveContainer>
  );
}

export function HorizontalBarChart({
  data,
  labelKey,
  valueKey,
  colour,
  height = 280,
}: {
  data: Array<Record<string, string | number>>;
  labelKey: string;
  valueKey: string;
  /** Omit to take the leading series slot for this theme. */
  colour?: string;
  height?: number;
}) {
  const theme = useChartTheme();

  return (
    <ResponsiveContainer width="100%" height={height}>
      <BarChart data={data} layout="vertical" margin={{ top: 0, right: 24, left: 8, bottom: 0 }}>
        <CartesianGrid strokeDasharray="3 3" stroke={theme.grid} horizontal={false} />
        <XAxis type="number" allowDecimals={false} {...theme.axisProps} />
        <YAxis type="category" dataKey={labelKey} width={150} {...theme.axisProps} />
        <Tooltip {...theme.tooltip} />
        <Bar dataKey={valueKey} fill={colour ?? theme.series[0]} radius={[0, 4, 4, 0]} maxBarSize={22} />
      </BarChart>
    </ResponsiveContainer>
  );
}

export function GroupedBarChart({
  data,
  labelKey,
  series,
  height = 280,
}: {
  data: Array<Record<string, string | number>>;
  labelKey: string;
  series: Array<{ key: string; label: string; colour?: string }>;
  height?: number;
}) {
  const theme = useChartTheme();

  return (
    <ResponsiveContainer width="100%" height={height}>
      {/* barGap keeps a 2px slice of card between neighbouring bars, so two
          adjacent fills never touch and read as one shape. */}
      <BarChart data={data} margin={{ top: 4, right: 12, left: -18, bottom: 0 }} barGap={2}>
        <CartesianGrid strokeDasharray="3 3" stroke={theme.grid} vertical={false} />
        <XAxis dataKey={labelKey} {...theme.axisProps} />
        <YAxis allowDecimals={false} width={44} {...theme.axisProps} />
        <Tooltip {...theme.tooltip} />
        <Legend iconType="circle" iconSize={8} wrapperStyle={LEGEND_STYLE} />
        {series.map((entry, index) => (
          <Bar
            key={entry.key}
            dataKey={entry.key}
            name={entry.label}
            fill={entry.colour ?? theme.series[index]}
            radius={[4, 4, 0, 0]}
            maxBarSize={40}
          />
        ))}
      </BarChart>
    </ResponsiveContainer>
  );
}

export function DonutChart({
  data,
  height = 260,
  colours,
}: {
  data: Array<{ name: string; value: number }>;
  height?: number;
  colours?: string[];
}) {
  const theme = useChartTheme();
  const palette = colours ?? theme.series;
  const total = data.reduce((sum, entry) => sum + entry.value, 0);

  return (
    <div className="relative">
      <ResponsiveContainer width="100%" height={height}>
        <PieChart>
          <Pie
            data={data}
            dataKey="value"
            nameKey="name"
            innerRadius="58%"
            outerRadius="82%"
            paddingAngle={2}
            stroke={theme.surface}
            strokeWidth={2}
          >
            {data.map((entry, index) => (
              <Cell key={entry.name} fill={palette[index % palette.length]} />
            ))}
          </Pie>
          <Tooltip {...theme.tooltip} />
          <Legend iconType="circle" iconSize={8} wrapperStyle={{ fontSize: 12 }} />
        </PieChart>
      </ResponsiveContainer>
      <div className="pointer-events-none absolute inset-x-0 top-[38%] text-center">
        <p className="tabular text-2xl font-semibold text-ink-900">{total}</p>
        <p className="text-xs text-ink-500">total</p>
      </div>
    </div>
  );
}

export function ComplianceLineChart({
  data,
  height = 260,
}: {
  data: Array<{ bucket: string; compliance_percent: number | null }>;
  height?: number;
}) {
  const theme = useChartTheme();

  return (
    <ResponsiveContainer width="100%" height={height}>
      <LineChart data={data} margin={{ top: 4, right: 12, left: -18, bottom: 0 }}>
        <CartesianGrid strokeDasharray="3 3" stroke={theme.grid} vertical={false} />
        <XAxis dataKey="bucket" {...theme.axisProps} />
        <YAxis domain={[0, 100]} width={44} unit="%" {...theme.axisProps} />
        <Tooltip
          {...theme.tooltip}
          formatter={(value) => [`${value ?? "—"}%`, "On time"] as [string, string]}
        />
        <Line
          type="monotone"
          dataKey="compliance_percent"
          stroke={theme.series[0]}
          strokeWidth={2.5}
          /* r=4 gives an 8px marker, which is the smallest a person can
             reliably hit on a phone. */
          dot={{ r: 4, fill: theme.series[0], stroke: theme.surface, strokeWidth: 1.5 }}
          activeDot={{ r: 6 }}
        />
      </LineChart>
    </ResponsiveContainer>
  );
}

/** A single proportion, where a whole chart would be overkill. */
export function ProgressMeter({
  value,
  label,
  caption,
  tone = "brand",
}: {
  value: number | null;
  label: string;
  caption?: string;
  tone?: "brand" | "success" | "warning" | "danger";
}) {
  const safe = value === null ? 0 : Math.max(0, Math.min(100, value));

  const barColour = {
    brand: "bg-brand-500",
    success: "bg-success-500",
    warning: "bg-warning-500",
    danger: "bg-danger-500",
  }[tone];

  return (
    <div>
      <div className="flex items-baseline justify-between gap-3">
        <span className="text-sm font-medium text-ink-800">{label}</span>
        <span className="tabular text-sm font-semibold text-ink-900">
          {value === null ? "—" : `${value}%`}
        </span>
      </div>
      <div className="mt-2 h-2 overflow-hidden rounded-full bg-ink-100" role="progressbar" aria-valuenow={safe} aria-valuemin={0} aria-valuemax={100}>
        <div className={cn("h-full rounded-full transition-all duration-500", barColour)} style={{ width: `${safe}%` }} />
      </div>
      {caption ? <p className="mt-1.5 text-xs text-ink-500">{caption}</p> : null}
    </div>
  );
}
