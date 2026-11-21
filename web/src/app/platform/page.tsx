import type { Metadata } from "next";
import { Check, Info } from "lucide-react";
import Image from "next/image";
import { BrowserFrame } from "@/components/marketing/Frames";
import { MarketingPage, PageHero, WRAP } from "@/components/marketing/MarketingPage";
import { Pill } from "@/components/marketing/Pill";
import { Reveal } from "@/components/marketing/Reveal";
import { PLATFORM_MODULES } from "@/lib/content/modules";
import { cn } from "@/lib/utils";

export const metadata: Metadata = {
  title: "Platform",
  description:
    "One platform for connected project workflows: stakeholder register, engagement, grievances, PAP master register, incident notification, ESHS contractor assurance and reporting.",
};

export default function PlatformPage() {
  return (
    <MarketingPage>
      <PageHero eyebrow="Platform" title="One platform for connected project workflows.">
        <p>Explore our modules.</p>
      </PageHero>

      {/* An index of the modules, each a jump to its section. */}
      <nav aria-label="Modules" className={cn(WRAP, "pb-6")}>
        <ul className="flex flex-wrap gap-2">
          {PLATFORM_MODULES.map((module) => (
            <li key={module.key}>
              <a
                href={`#${module.key}`}
                className="inline-flex rounded-full bg-surface px-4 py-2 text-sm font-medium text-ink-800 ring-1 ring-hairline transition-colors hover:bg-ink-100"
              >
                {module.name}
              </a>
            </li>
          ))}
        </ul>
      </nav>

      <div className="divide-y divide-hairline">
        {PLATFORM_MODULES.map((module, index) => (
          <section key={module.key} id={module.key} className="scroll-mt-24 py-16 md:py-24">
            <div
              className={cn(
                WRAP,
                "grid items-center gap-10 lg:grid-cols-[0.9fr_1.1fr] lg:gap-16",
                index % 2 === 1 && "lg:grid-cols-[1.1fr_0.9fr]",
              )}
            >
              <Reveal className={cn(index % 2 === 1 && "lg:order-2")}>
                <p className="font-mono text-sm text-ink-500">{String(index + 1).padStart(2, "0")}</p>
                <h2 className="mt-3 text-[2rem] font-semibold leading-[1.06] tracking-[-0.04em] text-ink-900 md:text-[2.75rem]">
                  {module.name}
                </h2>
                <p className="mt-5 text-[1.0625rem] leading-relaxed text-ink-600 md:text-[1.125rem]">{module.summary}</p>
                <ul className="mt-7 space-y-3">
                  {module.points.map((point) => (
                    <li key={point} className="flex items-start gap-3 text-[0.96875rem] text-ink-800">
                      <span className="mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-full bg-brand-50 text-brand-600">
                        <Check className="h-3.5 w-3.5" aria-hidden />
                      </span>
                      {point}
                    </li>
                  ))}
                </ul>
                {module.note ? (
                  <p className="mt-7 flex items-start gap-3 rounded-2xl bg-surface p-4 text-sm leading-relaxed text-ink-700 ring-1 ring-hairline">
                    <Info className="mt-0.5 h-4 w-4 shrink-0 text-brand-600" aria-hidden />
                    <span>
                      <span className="font-semibold text-ink-900">Note. </span>
                      {module.note}
                    </span>
                  </p>
                ) : null}
              </Reveal>

              <Reveal delay={100} className={cn(index % 2 === 1 && "lg:order-1")}>
                <BrowserFrame tone="light" url={`sasacompliance.com/${module.path}`}>
                  <Image
                    src={module.screen}
                    alt={`The ${module.name} screen in SASA`}
                    width={2000}
                    height={1250}
                    sizes="(min-width: 1024px) 680px, 100vw"
                    className="block h-auto w-full"
                  />
                </BrowserFrame>
              </Reveal>
            </div>
          </section>
        ))}
      </div>

      <section className="bg-surface py-20 md:py-24">
        <div className={cn(WRAP, "flex flex-col items-start justify-between gap-8 md:flex-row md:items-center")}>
          <div className="max-w-[40rem]">
            <h2 className="text-[2rem] font-semibold leading-[1.06] tracking-[-0.04em] text-ink-900 md:text-[2.5rem]">
              See the modules with your own workflows in mind.
            </h2>
            <p className="mt-3 text-[1.0625rem] text-ink-600">
              Screens shown with demonstration data.
            </p>
          </div>
          <div className="flex flex-col gap-3 sm:flex-row">
            <Pill href="/book-demo">Book a demo</Pill>
            <Pill href="/register-interest" tone="light" arrow={false}>
              Register interest
            </Pill>
          </div>
        </div>
      </section>
    </MarketingPage>
  );
}
