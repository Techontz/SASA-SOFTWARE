import { formatMoney, formatQuantity } from "@/lib/pap/format";
import { formatDate } from "@/lib/utils";
import type { PapRecord, PapRecordValue } from "@/types/pap";
import type { PapDerivedDefinition, PapFieldDefinition } from "./sections";

/** How one field of a PAP child record reads on screen. */
export function displayField(
  record: PapRecord,
  field: PapFieldDefinition | PapDerivedDefinition,
  currency: string,
): string {
  const value: PapRecordValue = record[field.key];

  if (value === undefined) return "Withheld";
  if (value === null || value === "") return "—";

  if ("options" in field && field.options) {
    const label = record[`${field.key}_label`];
    return typeof label === "string" ? label : String(value);
  }

  switch (field.kind) {
    case "money":
      return formatMoney(typeof value === "number" ? value : Number(value), currency);
    case "percent":
      return `${Number(value).toLocaleString("en-GB", { maximumFractionDigits: 2 })}%`;
    case "date":
      return formatDate(String(value));
    case "boolean":
      return value ? "Yes" : "No";
    case "number":
      return formatQuantity(typeof value === "number" ? value : String(value), field.unit);
    default:
      return Array.isArray(value) ? `${value.length}` : String(value);
  }
}

export function labelFor(record: PapRecord, key: string): string | null {
  const label = record[`${key}_label`];
  return typeof label === "string" ? label : null;
}
