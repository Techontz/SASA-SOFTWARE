import { cn } from "@/lib/utils";

/* Shared by the landing page and the sign-in page, so the two stay one design. */

export function BrandMark({ className }: { className?: string }) {
  return (
    <span className={cn("flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-accent-solid text-lg font-bold text-on-primary", className)}>
      S
    </span>
  );
}

/**
 * A drawing of the product, not a screenshot of anyone's data: shapes only, no
 * figures, so nobody reads a number off the sign-in page and believes it.
 */
export function DashboardPreview({ className = "hidden xl:flex" }: { className?: string }) {
  const nav = ["Overview", "Stakeholders", "Engagements", "Grievances", "Commitments", "Reports"];

  return (
    <div
      className={cn("rotate-[-2deg] overflow-hidden rounded-2xl border border-chrome-line bg-chrome/90 shadow-[var(--shadow-overlay)] backdrop-blur", className)}
      aria-hidden
    >
      <div className="w-32 shrink-0 border-r border-chrome-line p-3">
        <div className="mb-4 flex items-center gap-2">
          <span className="flex h-5 w-5 items-center justify-center rounded bg-accent-solid text-[0.625rem] font-bold text-on-primary">
            S
          </span>
          <span className="text-xs font-semibold text-white">SASA</span>
        </div>
        <ul className="space-y-1">
          {nav.map((label, index) => (
            <li
              key={label}
              className={
                index === 0
                  ? "rounded bg-chrome-raised px-2 py-1 text-[0.625rem] text-chrome-fg"
                  : "px-2 py-1 text-[0.625rem] text-chrome-subtle"
              }
            >
              {label}
            </li>
          ))}
        </ul>
      </div>

      <div className="min-w-0 flex-1 space-y-3 p-4">
        <p className="text-xs font-semibold text-white">Project overview</p>

        <div className="grid grid-cols-4 gap-2">
          {[0, 1, 2, 3].map((index) => (
            <div key={index} className="rounded-lg border border-chrome-line bg-chrome-raised p-2">
              <div className="h-1.5 w-3/4 rounded bg-chrome-line" />
              <div className="mt-2 h-3 w-1/2 rounded bg-chrome-muted/60" />
            </div>
          ))}
        </div>

        <div className="grid grid-cols-[0.9fr_1.1fr] gap-2">
          <div className="flex items-center gap-3 rounded-lg border border-chrome-line bg-chrome-raised p-3">
            <svg viewBox="0 0 36 36" className="h-14 w-14 shrink-0 -rotate-90">
              <circle cx="18" cy="18" r="14" fill="none" stroke="var(--color-chrome-line)" strokeWidth="4" />
              <circle cx="18" cy="18" r="14" fill="none" stroke="var(--color-chrome-accent)" strokeWidth="4" strokeDasharray="62 88" strokeLinecap="round" />
              <circle cx="18" cy="18" r="14" fill="none" stroke="var(--color-accent-300)" strokeWidth="4" strokeDasharray="12 88" strokeDashoffset="-64" strokeLinecap="round" />
            </svg>
            <div className="w-full space-y-1.5">
              <div className="h-1.5 w-full rounded bg-chrome-line" />
              <div className="h-1.5 w-4/5 rounded bg-chrome-line" />
              <div className="h-1.5 w-3/5 rounded bg-chrome-line" />
            </div>
          </div>

          <div className="rounded-lg border border-chrome-line bg-chrome-raised p-3">
            <svg viewBox="0 0 120 48" className="h-14 w-full" preserveAspectRatio="none">
              <path
                d="M0 40 L15 34 L30 36 L45 26 L60 29 L75 18 L90 21 L105 10 L120 13 L120 48 L0 48 Z"
                fill="var(--color-chrome-accent)"
                opacity="0.15"
              />
              <path
                d="M0 40 L15 34 L30 36 L45 26 L60 29 L75 18 L90 21 L105 10 L120 13"
                fill="none"
                stroke="var(--color-chrome-accent)"
                strokeWidth="1.5"
              />
            </svg>
          </div>
        </div>

        <div className="rounded-lg border border-chrome-line bg-chrome-raised p-3">
          <div className="h-1.5 w-1/4 rounded bg-chrome-line" />
          <div className="relative mt-3 h-16">
            {[
              [18, 30],
              [34, 55],
              [52, 40],
              [61, 62],
              [72, 28],
              [84, 48],
            ].map(([left, top], index) => (
              <span
                key={index}
                className={
                  index % 2 === 0
                    ? "absolute h-2 w-2 rounded-full bg-chrome-accent"
                    : "absolute h-2 w-2 rounded-full bg-accent-300"
                }
                style={{ left: `${left}%`, top: `${top}%` }}
              />
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}

/** Hills behind the panel, drawn in the chrome tokens so they follow the theme. */
export function Landscape({ className = "h-[55%]" }: { className?: string }) {
  return (
    <svg
      className={cn("pointer-events-none absolute inset-x-0 bottom-0 w-full", className)}
      viewBox="0 0 800 400"
      preserveAspectRatio="none"
      aria-hidden
    >
      <path d="M0 220 Q120 150 240 190 T480 150 T800 170 V400 H0 Z" fill="var(--color-chrome)" />
      <path d="M0 280 Q160 220 330 260 T640 230 T800 250 V400 H0 Z" fill="var(--color-chrome-raised)" opacity="0.7" />
      <path d="M0 330 Q200 300 400 320 T800 310 V400 H0 Z" fill="var(--color-chrome)" />
    </svg>
  );
}
