import type { IncidentTypeOption } from "@/types/incident";

/**
 * Splits the incident type list for a chosen category: the types whose prefix
 * belongs to that category come first, every other type stays selectable
 * underneath. Any type may be recorded under any category.
 */
export function groupTypesForCategory(
  types: IncidentTypeOption[],
  category: string | null | undefined,
): { suggested: IncidentTypeOption[]; others: IncidentTypeOption[] } {
  if (!category) return { suggested: [], others: types };

  const suggested = types.filter((type) => type.category === category);
  const others = types.filter((type) => type.category !== category);

  return { suggested, others };
}

/** Mirrors the server's derivation, so the form can warn before submit. */
export function isSeriousIncident(args: {
  potentialConsequence: number | null | undefined;
  type: IncidentTypeOption | undefined;
  seriousLevel: number;
}): boolean {
  if (args.type?.serious) return true;
  return args.potentialConsequence !== null && args.potentialConsequence !== undefined
    ? args.potentialConsequence >= args.seriousLevel
    : false;
}

/** A "Name per line" textarea into a clean, de-duplicated list. */
export function parseNamesList(text: string): string[] {
  const seen = new Set<string>();
  const result: string[] = [];

  for (const line of text.split(/\r?\n/)) {
    const name = line.trim();
    if (!name || seen.has(name.toLowerCase())) continue;
    seen.add(name.toLowerCase());
    result.push(name);
  }

  return result;
}

/** A local `datetime-local` value for "now", in the device's own time zone. */
export function nowForDateTimeInput(date: Date = new Date()): string {
  const pad = (value: number) => String(value).padStart(2, "0");
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/** The status vocabulary shown on badges, mapped onto the shared badge tones. */
export const INCIDENT_STATUS_BADGE: Record<string, { status: string; label: string }> = {
  submitted: { status: "new", label: "Submitted" },
  under_review: { status: "under_investigation", label: "Under review" },
  closed: { status: "closed", label: "Closed" },
  archived: { status: "archived", label: "Archived" },
};
