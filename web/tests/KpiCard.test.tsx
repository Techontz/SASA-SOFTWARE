import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { KpiCard } from "@/components/ui/KpiCard";
import type { Kpi } from "@/types/api";

vi.mock("next/link", () => ({
  default: ({ children, href }: { children: React.ReactNode; href: string }) => <a href={href}>{children}</a>,
}));

const kpi: Kpi = {
  key: "grievances_open",
  label: "Open grievances",
  definition: "Cases not currently closed, rejected or withdrawn — measured now, not within the period.",
  unit: "count",
  value: 14,
  delta_percent: null,
  context: {},
  drill: "grievances?status=open",
};

describe("KPI card", () => {
  it("shows the number and its label", () => {
    render(<KpiCard kpi={kpi} />);

    expect(screen.getByText("14")).toBeInTheDocument();
    expect(screen.getByText("Open grievances")).toBeInTheDocument();
  });

  it("carries the definition, so two people cannot read the number two ways", () => {
    render(<KpiCard kpi={kpi} />);

    expect(screen.getByRole("note")).toHaveAccessibleName(/Cases not currently closed/);
  });

  it("drills through to the records behind it", () => {
    render(<KpiCard kpi={kpi} />);

    expect(screen.getByRole("link")).toHaveAttribute("href", "/grievances?status=open");
  });

  it("shows an em dash rather than a zero when there is no data", () => {
    render(<KpiCard kpi={{ ...kpi, value: null, drill: null }} />);

    expect(screen.getByText("—")).toBeInTheDocument();
  });

  it("formats a percentage with its sign", () => {
    render(<KpiCard kpi={{ ...kpi, unit: "percent", value: 88.9, drill: null }} />);

    expect(screen.getByText("88.9%")).toBeInTheDocument();
  });
});
