"use client";

import { useMemo } from "react";
import { useTheme } from "@/lib/theme";

/**
 * Chart colour, per theme.
 *
 * Recharts writes `stroke` and `fill` as SVG presentation attributes, and those
 * do not resolve `var(--token)` — so unlike the rest of SASA, a chart cannot be
 * styled by flipping a CSS variable. The values have to be handed over as real
 * hex, which is what this hook is for. Chart *furniture* (grid lines, axis
 * labels, the tooltip) is done the normal way, in globals.css.
 *
 * WHY THESE EIGHT COLOURS
 * -----------------------
 * The series palette is not the brand ramp. SASA's petrol teal is deliberately
 * desaturated so it can carry large areas of interface, and a colour that quiet
 * reads as grey when it is two pixels wide — so the chart palette opens with a
 * chromatic teal that belongs to the same family and can actually be seen.
 *
 * Both columns are *selected*, not computed from one another: the dark steps
 * were chosen against the dark card and validated as their own set. Order is
 * load-bearing, not cosmetic — it is what keeps neighbouring series apart for
 * colour-blind readers, so slots are assigned in sequence and never cycled.
 *
 * Verified with the data-visualisation validator, against #ffffff and the dark
 * card #19293e: every hard gate passes in both themes on the adjacent pairlist
 * (worst adjacent CVD ΔE 9.1 light / 8.4 dark against a ≥8 target; worst
 * normal-vision ΔE 19.6 / 19.3 against a ≥15 floor), and the leading three
 * slots also pass with every pair compared, which is the case that matters for
 * a donut. The dark trio's teal↔violet pair sits in the 6–8 CVD band, which is
 * permitted only where colour is not the sole encoding: every chart in SASA
 * with more than one series carries a legend, and the donut labels its slices.
 *
 * Re-run after any change:
 *   node scripts/validate_palette.js "<hex,…>" --mode dark --surface "#19293e"
 */
const SERIES = {
  light: [
    "#0f8fa6", // teal — the SASA family, at a chroma that survives a 2px line
    "#d67227", // clay — the accent, unchanged from the interface
    "#4a3aa7", // violet
    "#1baf7a", // green
    "#eda100", // yellow
    "#e87ba4", // magenta
    "#2a78d6", // blue
    "#e34948", // red
  ],
  dark: [
    "#1fa3bd",
    "#d9722e",
    "#9085e9",
    "#199e70",
    "#c98500",
    "#d55181",
    "#3987e5",
    "#e66767",
  ],
} as const;

/* Status is a reserved role: these never stand in for "series 4", and they are
   always shipped with a word beside them rather than on their own. */
const STATUS = {
  light: { good: "#12855a", warning: "#b45309", serious: "#c2410c", critical: "#b42318" },
  dark: { good: "#34b981", warning: "#e08c17", serious: "#ef7c45", critical: "#ef6b5b" },
} as const;

const FURNITURE = {
  light: {
    grid: "#e8edf3",
    axis: "#7d92a8",
    text: "#5a6e81",
    tooltipBg: "#ffffff",
    tooltipBorder: "#cfdae6",
    tooltipShadow: "0 8px 20px rgba(16,32,46,0.12)",
    cursor: "rgba(15,143,166,0.07)",
    surface: "#ffffff",
  },
  dark: {
    grid: "#2b4058",
    axis: "#6b849f",
    text: "#97acc2",
    tooltipBg: "#213652",
    tooltipBorder: "#3a5170",
    tooltipShadow: "0 10px 26px rgba(0,0,0,0.45)",
    cursor: "rgba(31,163,189,0.12)",
    surface: "#19293e",
  },
} as const;

export interface ChartTheme {
  /** Assign in order. A ninth series folds into "Other" rather than wrapping. */
  series: readonly string[];
  status: Readonly<Record<"good" | "warning" | "serious" | "critical", string>>;
  grid: string;
  axis: string;
  text: string;
  /** The card colour, for the 2px gap that separates adjacent filled marks. */
  surface: string;
  axisProps: {
    stroke: string;
    fontSize: number;
    tickLine: boolean;
    axisLine: boolean;
    tick: { fill: string };
  };
  tooltip: {
    contentStyle: React.CSSProperties;
    itemStyle: React.CSSProperties;
    labelStyle: React.CSSProperties;
    cursor: { fill: string };
  };
}

export function useChartTheme(): ChartTheme {
  const { theme } = useTheme();

  return useMemo(() => {
    const f = FURNITURE[theme];
    return {
      series: SERIES[theme],
      status: STATUS[theme],
      grid: f.grid,
      axis: f.axis,
      text: f.text,
      surface: f.surface,
      axisProps: {
        stroke: f.axis,
        fontSize: 11,
        tickLine: false,
        axisLine: false,
        /* Recharts draws tick labels as <text fill>, which does not inherit
           `color`, so the label colour has to be set explicitly. */
        tick: { fill: f.text },
      },
      tooltip: {
        contentStyle: {
          borderRadius: 8,
          background: f.tooltipBg,
          border: `1px solid ${f.tooltipBorder}`,
          boxShadow: f.tooltipShadow,
          fontSize: 13,
          padding: "8px 12px",
          color: theme === "dark" ? "#f4f8fb" : "#10202e",
        },
        itemStyle: { color: theme === "dark" ? "#e5edf4" : "#1f2f3d" },
        labelStyle: { color: theme === "dark" ? "#f4f8fb" : "#10202e", fontWeight: 600 },
        cursor: { fill: f.cursor },
      },
    };
  }, [theme]);
}
