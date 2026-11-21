import type { ReactNode } from "react";
import { MarketingPage, WRAP } from "./MarketingPage";
import { Eyebrow } from "./Pill";
import { cn } from "@/lib/utils";

export interface LegalSection {
  id: string;
  title: string;
  body: ReactNode;
}

/** A long policy page: contents on the side, readable sections, a date. */
export function LegalPage({
  eyebrow,
  title,
  intro,
  updated,
  sections,
}: {
  eyebrow: string;
  title: string;
  intro: ReactNode;
  updated: string;
  sections: LegalSection[];
}) {
  return (
    <MarketingPage>
      <section className={cn(WRAP, "pb-10 pt-32 md:pt-40")}>
        <Eyebrow>{eyebrow}</Eyebrow>
        <h1 className="mt-4 max-w-[46rem] text-[2.5rem] font-semibold leading-[1.04] tracking-[-0.045em] text-ink-900 md:text-[3.5rem]">
          {title}
        </h1>
        <div className="mt-6 max-w-[44rem] text-[1.125rem] leading-[1.6] text-ink-600">{intro}</div>
        <p className="mt-4 text-sm text-ink-500">Last updated {updated}</p>
      </section>

      <div className={cn(WRAP, "grid gap-12 pb-24 lg:grid-cols-[15rem_minmax(0,1fr)] lg:gap-16")}>
        <nav aria-label="On this page" className="hidden lg:block">
          <ul className="sticky top-28 space-y-1 border-l border-hairline text-sm">
            {sections.map((section) => (
              <li key={section.id}>
                <a href={`#${section.id}`} className="-ml-px block border-l border-transparent py-1.5 pl-4 text-ink-600 hover:border-ink-400 hover:text-ink-900">
                  {section.title}
                </a>
              </li>
            ))}
          </ul>
        </nav>

        <div className="max-w-[44rem] space-y-12">
          {sections.map((section) => (
            <section key={section.id} id={section.id} className="scroll-mt-28">
              <h2 className="text-[1.5rem] font-semibold tracking-[-0.025em] text-ink-900">{section.title}</h2>
              <div className="mt-4 space-y-4 text-[1.0625rem] leading-[1.7] text-ink-700">{section.body}</div>
            </section>
          ))}
        </div>
      </div>
    </MarketingPage>
  );
}

export function Bullets({ items }: { items: ReactNode[] }) {
  return (
    <ul className="list-disc space-y-2 pl-5 marker:text-ink-400">
      {items.map((item, index) => (
        <li key={index}>{item}</li>
      ))}
    </ul>
  );
}
