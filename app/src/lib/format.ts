import type { Grievance } from "./types";
import type { Tone } from "./theme";

const LABELS: Record<string, string> = {
  sw: "Kiswahili",
  en: "English",
  pap: "Project-affected person",
  cso: "CSO or NGO",
  sms: "SMS",
  whatsapp: "WhatsApp",
  in_person: "In person",
  through_leader: "Through a local leader",
  phone: "Phone call",
  email: "Email",
  prefer_not_to_say: "Prefer not to say",
  pre_construction: "Pre-construction",
  community_member: "Community member",
  worker: "Project worker",
  contractor: "Contractor staff",
  leader: "Local or traditional leader",
  government: "Government",
  suggestion_box: "Suggestion box",
  web: "Website",
  voice: "Phone line",
};

/** A machine value as words: `community_meeting` becomes "Community meeting". */
export function humanise(value: string | null | undefined): string {
  if (!value) return "";
  const known = LABELS[value];
  if (known) return known;
  const words = value.replace(/[_-]+/g, " ").trim();
  return words.charAt(0).toUpperCase() + words.slice(1);
}

/** The API's labels use a long dash; the app's copy does not. */
export function plain(label: string | null | undefined): string {
  return (label ?? "").replace(/\s*[—–]\s*/g, ", ");
}

const DAY = 86_400_000;

function startOfDay(date: Date): number {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate()).getTime();
}

export function formatDate(iso: string | null | undefined, withYear = false): string {
  if (!iso) return "";
  const date = new Date(iso);
  const sameYear = date.getFullYear() === new Date().getFullYear();
  return date.toLocaleDateString("en-GB", {
    day: "numeric",
    month: "short",
    ...(withYear || !sameYear ? { year: "numeric" } : {}),
  });
}

export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return "";
  const date = new Date(iso);
  return `${formatDate(iso)}, ${date.toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" })}`;
}

/** "Today", "Yesterday", "3 days ago", then a date. */
export function relativeDay(iso: string | null | undefined): string {
  if (!iso) return "";
  const days = Math.round((startOfDay(new Date()) - startOfDay(new Date(iso))) / DAY);
  if (days === 0) return "Today";
  if (days === 1) return "Yesterday";
  if (days > 1 && days < 7) return `${days} days ago`;
  if (days === -1) return "Tomorrow";
  return formatDate(iso);
}

export function greeting(now = new Date()): string {
  const hour = now.getHours();
  if (hour < 12) return "Good morning";
  if (hour < 17) return "Good afternoon";
  return "Good evening";
}

export function longDate(now = new Date()): string {
  return now.toLocaleDateString("en-GB", { weekday: "long", day: "numeric", month: "long" });
}

const CLOSED = new Set(["closed", "rejected", "withdrawn", "resolved"]);

/** The resolution clock, in words: "4d left", "Due today", "Overdue". */
export function slaLabel(grievance: Grievance): { label: string; tone: Tone } | null {
  if (CLOSED.has(grievance.status)) return null;
  const state = grievance.sla.resolution_state;
  if (state === "paused") return { label: "Clock paused", tone: "neutral" };
  if (state === "breached") return { label: "Overdue", tone: "danger" };
  const due = grievance.sla.resolution_due_at;
  if (!due) return null;
  const days = Math.ceil((new Date(due).getTime() - Date.now()) / DAY);
  if (days < 0) return { label: "Overdue", tone: "danger" };
  if (days === 0) return { label: "Due today", tone: "warning" };
  return { label: `${days}d left`, tone: days <= 2 || state === "at_risk" ? "warning" : "neutral" };
}

export function grievanceStatusTone(status: string): Tone {
  switch (status) {
    case "new":
    case "reopened":
      return "info";
    case "resolved":
    case "closed":
      return "success";
    case "awaiting_confirmation":
    case "action_pending":
      return "warning";
    case "rejected":
    case "withdrawn":
      return "neutral";
    default:
      return "accent";
  }
}

export function severityTone(level: number | null): Tone {
  if (!level) return "neutral";
  if (level >= 4) return "danger";
  if (level === 3) return "warning";
  return "neutral";
}

export function priorityTone(priority: string | null | undefined): Tone {
  if (priority === "high") return "danger";
  if (priority === "medium") return "warning";
  return "neutral";
}

export function initials(name: string | null | undefined): string {
  const parts = (name ?? "").trim().split(/\s+/).filter((part) => /^\p{L}/u.test(part));
  const first = parts[0]?.[0] ?? "";
  const last = parts.length > 1 ? (parts[parts.length - 1]?.[0] ?? "") : "";
  return (first + last).toUpperCase();
}
