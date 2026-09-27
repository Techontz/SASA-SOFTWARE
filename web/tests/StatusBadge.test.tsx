import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { PriorityBadge, SeverityBadge, StatusBadge } from "@/components/ui/StatusBadge";

/**
 * Status must never be communicated by colour alone — every badge has to carry
 * a word too, or it is unreadable to a colour-blind user and on a printed page.
 */
describe("status badges", () => {
  it("always renders a word, not only a colour", () => {
    render(<StatusBadge status="under_investigation" />);
    expect(screen.getByText("Investigating")).toBeInTheDocument();
  });

  it("names the confidentiality of a case", () => {
    render(<StatusBadge status="confidential" />);
    expect(screen.getByText("Confidential")).toBeInTheDocument();
  });

  it("says when a clock is breached", () => {
    render(<StatusBadge status="breached" />);
    expect(screen.getByText("Breached")).toBeInTheDocument();
  });

  it("renders an em dash rather than nothing for a missing status", () => {
    render(<StatusBadge status={null} />);
    expect(screen.getByText("—")).toBeInTheDocument();
  });

  it("reads severity as a level, never as a bare number", () => {
    render(<SeverityBadge severity={5} />);
    expect(screen.getByText("Level 5")).toBeInTheDocument();
  });

  it("says so when severity has not been assessed", () => {
    render(<SeverityBadge severity={null} />);
    expect(screen.getByText("Not assessed")).toBeInTheDocument();
  });

  it("labels priority and can carry a prefix", () => {
    render(<PriorityBadge priority="high" prefix="Risk" />);
    expect(screen.getByText(/Risk High/)).toBeInTheDocument();
  });
});
