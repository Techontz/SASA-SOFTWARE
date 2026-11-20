import { ArrowRight } from "lucide-react";
import Link from "next/link";
import type { ReactNode } from "react";
import { cn } from "@/lib/utils";

/**
 * The landing page's call to action: a pill with the arrow in its own disc.
 * Solid fills carrying white text use the solid tokens, so they stay legible
 * in both themes.
 */
const TONES = {
  accent: { pill: "bg-accent-solid text-on-primary hover:bg-accent-solid-hover", disc: "bg-chrome-deep/20" },
  primary: { pill: "bg-primary text-on-primary hover:bg-primary-hover", disc: "bg-chrome-deep/20" },
  light: { pill: "bg-surface text-ink-900 ring-1 ring-hairline hover:ring-hairline-strong", disc: "bg-accent-solid text-on-primary" },
  glass: { pill: "bg-chrome-raised/40 text-white ring-1 ring-chrome-line backdrop-blur-md hover:bg-chrome-raised/80", disc: "bg-chrome-fg text-chrome-deep" },
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
  const style = TONES[tone];

  return (
    <Link
      href={href}
      className={cn(
        "group inline-flex h-[3.25rem] items-center gap-3 rounded-full text-[0.96875rem] font-medium tracking-[-0.01em] transition-colors duration-200 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-accent-300/50",
        arrow ? "py-1.5 pl-6 pr-1.5" : "px-6",
        style.pill,
        className,
      )}
    >
      <span className="whitespace-nowrap">{children}</span>
      {arrow ? (
        <span
          className={cn(
            "grid h-10 w-10 place-items-center rounded-full transition-transform duration-300 group-hover:translate-x-0.5",
            style.disc,
          )}
        >
          <ArrowRight className="h-[18px] w-[18px]" aria-hidden />
        </span>
      ) : null}
    </Link>
  );
}

export function Eyebrow({ children, onDark = false }: { children: ReactNode; onDark?: boolean }) {
  return (
    <p
      className={cn(
        "inline-flex items-center gap-2 text-[0.8125rem] font-semibold uppercase tracking-[0.12em]",
        onDark ? "text-chrome-muted" : "text-brand-700",
      )}
    >
      <span className="h-1.5 w-1.5 rounded-full bg-accent-solid" aria-hidden />
      {children}
    </p>
  );
}
