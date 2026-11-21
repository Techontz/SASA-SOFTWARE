import type { CSSProperties, ReactNode } from "react";
import { cn } from "@/lib/utils";
import { LandingNav } from "./LandingNav";
import { Eyebrow } from "./Pill";
import { SiteFooter } from "./SiteFooter";

export const WRAP = "mx-auto w-full max-w-[1240px] px-4 sm:px-6 md:px-10";

/** The frame every public page shares: the solid bar, the page, the footer. */
export function MarketingPage({ children }: { children: ReactNode }) {
  return (
    <div id="top" className="min-h-dvh bg-canvas">
      <a
        href="#main"
        className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[70] focus:rounded-lg focus:bg-accent-solid focus:px-4 focus:py-2 focus:text-on-primary"
      >
        Skip to content
      </a>
      <LandingNav solid />
      <main id="main">{children}</main>
      <SiteFooter />
    </div>
  );
}

/** The opening of an inner page: a label, the headline and its paragraph. */
export function PageHero({
  eyebrow,
  title,
  children,
  className,
}: {
  eyebrow: string;
  title: ReactNode;
  children?: ReactNode;
  className?: string;
}) {
  return (
    <section className={cn(WRAP, "pb-12 pt-32 md:pb-16 md:pt-44", className)}>
      <div className="max-w-[52rem]">
        <Eyebrow>{eyebrow}</Eyebrow>
        <h1 className="lp-rise mt-4 text-[2.75rem] font-semibold leading-[1.02] tracking-[-0.045em] text-ink-900 md:text-[4.25rem]">
          {title}
        </h1>
        {children ? (
          <div className="lp-rise mt-6 max-w-[44rem] text-[1.125rem] leading-[1.55] tracking-[-0.01em] text-ink-600 md:text-[1.25rem]" style={{ "--d": "120ms" } as CSSProperties}>
            {children}
          </div>
        ) : null}
      </div>
    </section>
  );
}
