import type { Metadata } from "next";
import { Check, Factory, Fuel, Mountain, Zap, type LucideIcon } from "lucide-react";
import Image from "next/image";
import { MarketingPage, PageHero, WRAP } from "@/components/marketing/MarketingPage";
import { Pill } from "@/components/marketing/Pill";
import { Reveal } from "@/components/marketing/Reveal";
import { cn } from "@/lib/utils";

export const metadata: Metadata = {
  title: "Industries",
  description:
    "SASA Compliance is being developed for infrastructure, mining, energy, and oil and gas projects, where communities, contractors and project teams meet.",
};

const INDUSTRIES: Array<{ name: string; icon: LucideIcon }> = [
  { name: "Infrastructure", icon: Factory },
  { name: "Mining", icon: Mountain },
  { name: "Energy", icon: Zap },
  { name: "Oil and Gas", icon: Fuel },
];

export default function IndustriesPage() {
  return (
    <MarketingPage>
      <PageHero eyebrow="Industries" title="Built around the realities of project delivery.">
        <p>
          Infrastructure and extractive projects bring together communities, contractors and project management teams.
          SASA Compliance is being developed to help these teams organize environmental and social performance records
          and follow up responsibilities.
        </p>
      </PageHero>

      <section className={cn(WRAP, "pb-16 md:pb-24")}>
        <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {INDUSTRIES.map(({ name, icon: Icon }, index) => (
            <Reveal as="li" key={name} delay={index * 70}>
              <div className="sasa-card flex h-full flex-col p-7">
                <span className="grid h-12 w-12 place-items-center rounded-2xl bg-brand-50 text-brand-600">
                  <Icon className="h-6 w-6" aria-hidden />
                </span>
                <p className="mt-10 flex items-center gap-2 text-xl font-semibold tracking-[-0.02em] text-ink-900">
                  <Check className="h-5 w-5 text-brand-600" aria-hidden />
                  {name}
                </p>
              </div>
            </Reveal>
          ))}
        </ul>
      </section>

      <section className={cn(WRAP, "pb-20 md:pb-28")}>
        <Reveal className="relative overflow-hidden rounded-[1.75rem] bg-chrome-deep">
          <Image
            src="/landing/site-talk.webp"
            alt="Two engineers in hard hats talking on a construction site."
            width={1600}
            height={900}
            sizes="(min-width: 1240px) 1160px, 100vw"
            className="block aspect-[16/9] h-auto w-full object-cover md:aspect-[21/9]"
          />
          <div className="absolute inset-0 bg-gradient-to-t from-chrome-deep/85 via-chrome-deep/20 to-transparent" aria-hidden />
          <div className="absolute inset-x-0 bottom-0 flex flex-col items-start justify-between gap-5 p-6 md:flex-row md:items-end md:p-10">
            <p className="max-w-[34rem] text-[1.375rem] font-semibold leading-tight tracking-[-0.025em] text-white md:text-[1.75rem]">
              Communities, contractors and project teams, working from one record.
            </p>
            <Pill href="/book-demo">Book a demo</Pill>
          </div>
        </Reveal>
      </section>
    </MarketingPage>
  );
}
