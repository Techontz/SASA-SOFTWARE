import { type ClassValue, clsx } from "clsx";
import { twMerge } from "tailwind-merge";

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}

/** "under_investigation" -> "Under investigation" */
export function humanise(value: string | null | undefined): string {
  if (!value) return "—";
  const spaced = value.replace(/_/g, " ").trim();
  return spaced.charAt(0).toUpperCase() + spaced.slice(1);
}

/**
 * Renders a language code as the name speakers of it would use: "sw" becomes
 * "Kiswahili", not "Swahili", because the people reading it are the ones who
 * speak it.
 *
 * Asking the platform rather than keeping a lookup table means a project that
 * configures a language SASA has never seen still gets a real name.
 */
export function languageName(code: string | null | undefined): string {
  if (!code) return "—";

  try {
    /* The endonym first: the name in the language's own locale. */
    const endonym = new Intl.DisplayNames([code], { type: "language" }).of(code);
    if (endonym && endonym.toLowerCase() !== code.toLowerCase()) return endonym;

    const english = new Intl.DisplayNames(["en"], { type: "language" }).of(code);
    if (english && english.toLowerCase() !== code.toLowerCase()) return english;
  } catch {
    /* An unknown or malformed tag: fall through to the code itself. */
  }

  return code.toUpperCase();
}

export function initials(name: string | null | undefined): string {
  if (!name) return "?";
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? "")
    .join("");
}

const numberFormatter = new Intl.NumberFormat("en-GB");

export function formatNumber(value: number | null | undefined): string {
  if (value === null || value === undefined || Number.isNaN(value)) return "—";
  return numberFormatter.format(value);
}

export function formatMetric(
  value: number | string | null | undefined,
  unit?: string,
): string {
  if (value === null || value === undefined || value === "") return "—";
  const numeric = typeof value === "string" ? Number(value) : value;
  if (Number.isNaN(numeric)) return String(value);

  switch (unit) {
    case "percent":
      return `${numeric % 1 === 0 ? numeric : numeric.toFixed(1)}%`;
    case "days":
      return `${numeric % 1 === 0 ? numeric : numeric.toFixed(1)}`;
    case "hours":
      return `${numeric % 1 === 0 ? numeric : numeric.toFixed(1)}`;
    default:
      return numberFormatter.format(numeric);
  }
}

export function metricSuffix(unit?: string): string | null {
  switch (unit) {
    case "days":
      return "days";
    case "hours":
      return "hours";
    default:
      return null;
  }
}

/** Dates the way a person writes them, not the way a database stores them. */
export function formatDate(value: string | Date | null | undefined): string {
  if (!value) return "—";
  const date = typeof value === "string" ? new Date(value) : value;
  if (Number.isNaN(date.getTime())) return "—";
  return date.toLocaleDateString("en-GB", {
    day: "numeric",
    month: "short",
    year: "numeric",
  });
}

export function formatDateTime(value: string | Date | null | undefined): string {
  if (!value) return "—";
  const date = typeof value === "string" ? new Date(value) : value;
  if (Number.isNaN(date.getTime())) return "—";
  return date.toLocaleString("en-GB", {
    day: "numeric",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

export function formatRelative(value: string | Date | null | undefined): string {
  if (!value) return "—";
  const date = typeof value === "string" ? new Date(value) : value;
  if (Number.isNaN(date.getTime())) return "—";

  const seconds = Math.round((date.getTime() - Date.now()) / 1000);
  const units: [Intl.RelativeTimeFormatUnit, number][] = [
    ["year", 60 * 60 * 24 * 365],
    ["month", 60 * 60 * 24 * 30],
    ["week", 60 * 60 * 24 * 7],
    ["day", 60 * 60 * 24],
    ["hour", 60 * 60],
    ["minute", 60],
  ];

  const formatter = new Intl.RelativeTimeFormat("en-GB", { numeric: "auto" });

  for (const [unit, secondsInUnit] of units) {
    if (Math.abs(seconds) >= secondsInUnit) {
      return formatter.format(Math.round(seconds / secondsInUnit), unit);
    }
  }

  return "just now";
}

export function daysBetween(value: string | Date | null | undefined): number | null {
  if (!value) return null;
  const date = typeof value === "string" ? new Date(value) : value;
  if (Number.isNaN(date.getTime())) return null;
  return Math.round((date.getTime() - Date.now()) / 86_400_000);
}

/** A stable client id for this browser, used by the sync ledger. */
export function deviceId(): string {
  if (typeof window === "undefined") return "server";
  const key = "sasa.device-id";
  let value = window.localStorage.getItem(key);
  if (!value) {
    value = `web-${crypto.randomUUID()}`;
    window.localStorage.setItem(key, value);
  }
  return value;
}

export function uuid(): string {
  if (typeof crypto !== "undefined" && "randomUUID" in crypto) {
    return crypto.randomUUID();
  }
  return `${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

export function pluralise(count: number, singular: string, plural?: string): string {
  return count === 1 ? singular : (plural ?? `${singular}s`);
}

export function truncate(value: string | null | undefined, length = 120): string {
  if (!value) return "";
  return value.length > length ? `${value.slice(0, length - 1)}…` : value;
}
