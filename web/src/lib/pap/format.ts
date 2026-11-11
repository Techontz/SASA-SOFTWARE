/**
 * Money and figures for the PAP register. Amounts are Tanzanian shillings,
 * written the same way everywhere: "TZS 12,110,000", or compact on a tile.
 */

const whole = new Intl.NumberFormat("en-GB", { maximumFractionDigits: 0 });

export function formatMoney(value: number | string | null | undefined, currency = "TZS"): string {
  if (value === null || value === undefined || value === "") return "—";
  const numeric = typeof value === "string" ? Number(value) : value;
  if (Number.isNaN(numeric)) return "—";
  return `${currency} ${whole.format(numeric)}`;
}

/** "TZS 12.1M" — for KPI tiles on a phone, where the full figure would overflow. */
export function formatMoneyCompact(value: number | null | undefined, currency = "TZS"): string {
  if (value === null || value === undefined || Number.isNaN(value)) return "—";
  const abs = Math.abs(value);
  const sign = value < 0 ? "-" : "";
  const scaled = (divisor: number, suffix: string) => {
    const amount = abs / divisor;
    const digits = amount >= 100 ? 0 : 1;
    return `${currency} ${sign}${amount.toFixed(digits).replace(/\.0$/, "")}${suffix}`;
  };

  if (abs >= 1_000_000_000) return scaled(1_000_000_000, "bn");
  if (abs >= 1_000_000) return scaled(1_000_000, "M");
  if (abs >= 10_000) return scaled(1_000, "k");
  return `${currency} ${sign}${whole.format(abs)}`;
}

export function formatQuantity(value: number | string | null | undefined, unit?: string): string {
  if (value === null || value === undefined || value === "") return "—";
  const numeric = typeof value === "string" ? Number(value) : value;
  if (Number.isNaN(numeric)) return "—";
  const formatted = new Intl.NumberFormat("en-GB", { maximumFractionDigits: 4 }).format(numeric);
  return unit ? `${formatted} ${unit}` : formatted;
}

/** The API value a form input turns into: blank is null, numbers are numbers. */
export function toPayloadValue(raw: string | boolean, kind: "text" | "number" | "boolean"): string | number | boolean | null {
  if (kind === "boolean") return Boolean(raw);
  if (typeof raw === "boolean") return raw;
  const trimmed = raw.trim();
  if (trimmed === "") return null;
  if (kind === "number") {
    const numeric = Number(trimmed.replace(/,/g, ""));
    return Number.isNaN(numeric) ? null : numeric;
  }
  return trimmed;
}
