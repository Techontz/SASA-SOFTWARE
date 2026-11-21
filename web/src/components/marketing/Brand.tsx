import { cn } from "@/lib/utils";

/*
 * The SASA mark: a navy S whose middle stroke folds into blue. On a dark
 * surface it reverses to white with a lighter fold. The colours are brand
 * tokens, so the theme decides which version a themed surface gets, and
 * `onDark` forces the reversed one over film or the always-dark chrome.
 */

const TOP = "M670 215H1080L960 362H665C632 362 604 378 572 405C541 432 512 462 490 492C474 462 468 436 468 410C468 302 560 215 670 215Z";
const FOLD = "M654 390V483L912 572V636C912 643 906 648 898 648H840L620 597C571 585 535 561 507 517C530 489 560 458 588 434C612 413 632 398 654 390Z";
const BOTTOM = "M680 388H713L930 432C1018 450 1080 528 1080 612C1080 718 994 806 895 806H455L566 662H905C925 662 936 651 936 636V553L680 464Z";

export function LogoMark({ className, onDark = false }: { className?: string; onDark?: boolean }) {
  const ink = onDark ? "var(--color-logo-ink-reversed)" : "var(--color-logo-ink)";
  const fold = onDark ? "var(--color-logo-fold-reversed)" : "var(--color-logo-fold)";
  return (
    <svg viewBox="447 190 640 640" className={cn("h-10 w-10 shrink-0", className)} aria-hidden focusable="false">
      <path fill={ink} d={TOP} />
      <path fill={fold} d={FOLD} />
      <path fill={ink} d={BOTTOM} />
    </svg>
  );
}

/** The mark with the wordmark beside it: SASA, and the product line under it. */
export function Logo({
  onDark = false,
  className,
  markClassName,
  tagline = true,
}: {
  onDark?: boolean;
  className?: string;
  markClassName?: string;
  tagline?: boolean;
}) {
  return (
    <span className={cn("flex items-center gap-2.5", className)}>
      <LogoMark onDark={onDark} className={cn("h-9 w-9", markClassName)} />
      <span className="flex flex-col leading-none">
        <span className={cn("text-[1.1875rem] font-bold tracking-[-0.025em]", onDark ? "text-white" : "text-ink-900")}>SASA</span>
        {tagline ? (
          <span
            className={cn(
              "mt-[0.3rem] text-[0.5625rem] font-semibold uppercase tracking-[0.3em]",
              onDark ? "text-chrome-muted" : "text-ink-500",
            )}
          >
            Compliance
          </span>
        ) : null}
      </span>
    </span>
  );
}
