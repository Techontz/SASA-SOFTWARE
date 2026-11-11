import { AlertOctagon, AlertTriangle, CheckCircle2, CircleDashed, CircleDot, Clock, type LucideIcon } from "lucide-react";
import { riskTone, stageTone, type EshsTone } from "@/lib/eshs";
import { cn } from "@/lib/utils";

/* The same tinted-chip pairs StatusBadge uses, so contrast is already verified in both themes. */
const TONES: Record<EshsTone, string> = {
  neutral: "bg-ink-100 text-ink-700 ring-ink-200",
  info: "bg-info-50 text-info-700 ring-info-100",
  brand: "bg-brand-50 text-brand-800 ring-brand-100",
  success: "bg-success-50 text-success-700 ring-success-100",
  warning: "bg-warning-50 text-warning-700 ring-warning-100",
  accent: "bg-accent-50 text-accent-800 ring-accent-100",
  danger: "bg-danger-50 text-danger-700 ring-danger-100",
};

function Chip({ tone, icon: Icon, label, size }: { tone: EshsTone; icon: LucideIcon; label: string; size: "sm" | "md" }) {
  return (
    <span
      className={cn(
        "inline-flex items-center gap-1.5 whitespace-nowrap rounded-full font-medium ring-1 ring-inset",
        size === "sm" ? "px-2 py-0.5 text-[0.6875rem]" : "px-2.5 py-1 text-xs",
        TONES[tone],
      )}
    >
      <Icon className={size === "sm" ? "h-3 w-3" : "h-3.5 w-3.5"} aria-hidden />
      {label}
    </span>
  );
}

/** Critical = danger, High = orange — always with the word. */
export function EshsRiskBadge({
  risk,
  label,
  size = "md",
  withRiskWord = true,
}: {
  risk: string;
  label?: string;
  size?: "sm" | "md";
  withRiskWord?: boolean;
}) {
  const tone = riskTone(risk);
  const icon = risk === "critical" ? AlertOctagon : risk === "high" ? AlertTriangle : CircleDot;
  const word = label ?? risk.charAt(0).toUpperCase() + risk.slice(1);

  return <Chip tone={tone} icon={icon} label={withRiskWord ? `${word} risk` : word} size={size} />;
}

/** Closed = success. Awaiting verification reads as its own stage inside In progress. */
export function EshsStageBadge({ stage, label, size = "md" }: { stage: string; label: string; size?: "sm" | "md" }) {
  const icon = stage === "closed" ? CheckCircle2 : stage === "awaiting_verification" ? Clock : stage === "in_progress" ? CircleDashed : CircleDot;

  return <Chip tone={stageTone(stage)} icon={icon} label={label} size={size} />;
}

export function EshsOverdueBadge({ size = "md" }: { size?: "sm" | "md" }) {
  return <Chip tone="danger" icon={AlertTriangle} label="Overdue" size={size} />;
}
