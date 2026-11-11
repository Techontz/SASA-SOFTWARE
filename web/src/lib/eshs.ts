import type { EshsCategoryOption, EshsRisk, EshsStage } from "@/types/eshs";

export type EshsTone = "neutral" | "info" | "brand" | "success" | "warning" | "accent" | "danger";

/**
 * The client's conditional formatting: Critical = red, High = orange,
 * Closed = green. Orange is the accent ramp; every badge also carries a word.
 */
export function riskTone(risk: string | null | undefined): EshsTone {
  switch (risk as EshsRisk) {
    case "critical":
      return "danger";
    case "high":
      return "accent";
    case "medium":
      return "warning";
    default:
      return "neutral";
  }
}

export function stageTone(stage: string | null | undefined, overdue = false): EshsTone {
  if (stage === "closed") return "success";
  if (overdue) return "danger";

  switch (stage as EshsStage) {
    case "awaiting_verification":
      return "warning";
    case "in_progress":
      return "brand";
    default:
      return "info";
  }
}

/** The subcategory list depends on the ESHS category. */
export function subcategoriesFor(categories: EshsCategoryOption[] | undefined, category: string | null | undefined): string[] {
  if (!category) return [];
  return categories?.find((entry) => entry.key === category)?.subcategories ?? [];
}

/** "3 days over", "due today", "in 5 days" — or null when there is no target. */
export function targetLabel(daysUntil: number | null | undefined, closed: boolean): string | null {
  if (daysUntil === null || daysUntil === undefined || closed) return null;
  if (daysUntil < 0) return `${Math.abs(daysUntil)} ${Math.abs(daysUntil) === 1 ? "day" : "days"} over`;
  if (daysUntil === 0) return "due today";
  return `in ${daysUntil} ${daysUntil === 1 ? "day" : "days"}`;
}

/** Closure rate as shown: one decimal, or a dash when nothing has been raised. */
export function formatRate(rate: number | null | undefined): string {
  return rate === null || rate === undefined ? "—" : `${rate}%`;
}

/** Cleans a one-per-line textarea into a list: trimmed, no blanks, no repeats. */
export function parseList(text: string): string[] {
  const seen = new Set<string>();
  return text
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter((line) => {
      if (!line || seen.has(line.toLowerCase())) return false;
      seen.add(line.toLowerCase());
      return true;
    });
}
