import { ArrowRight } from "lucide-react";
import Link from "next/link";
import type { ReactNode } from "react";
import { cn } from "@/lib/utils";

/**
 * The landing page's calls to action. Solid fills carrying white text use the
 * solid tokens, so they stay legible in both themes.
 */
const TONES = {
  accent: "bg-accent-solid text-on-primary hover:bg-accent-solid-hover",
  primary: "bg-primary text-on-primary hover:bg-primary-hover",
  light: "bg-surface text-ink-900 ring-1 ring-inset ring-hairline-strong hover:bg-surface-sunken",
  /** Outline for use over film or the dark chrome. */
  glass: "text-white ring-1 ring-inset ring-chrome-fg/45 hover:bg-chrome-fg/10",
} as const;

export function Pill({
  href,
  children,
  tone = "accent",
  arrow = true,
  className,
}: {
  href: string;
  children: ReactNode;
  tone?: keyof typeof TONES;
  arrow?: boolean;
  className?: string;
}) {
  return (
    <Link
      href={href}
      className={cn(
        "group inline-flex h-12 items-center justify-center gap-2 rounded-[0.625rem] px-6 text-[0.9375rem] font-semibold tracking-[-0.005em] transition-colors duration-200 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-accent-300/50",
        TONES[tone],
        className,
      )}
    >
      <span className="whitespace-nowrap">{children}</span>
      {arrow ? (
        <ArrowRight className="h-4 w-4 transition-transform duration-200 group-hover:translate-x-0.5" aria-hidden />
      ) : null}
    </Link>
  );
}

/** A section's short label, above its heading. */
export function Eyebrow({ children, onDark = false }: { children: ReactNode; onDark?: boolean }) {
  return (
    <p className={cn("text-[0.9375rem] font-semibold", onDark ? "text-chrome-accent" : "text-brand-700")}>{children}</p>
  );
}
