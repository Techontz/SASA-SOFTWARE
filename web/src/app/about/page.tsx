import type { Metadata } from "next";
import Image from "next/image";
import { MarketingPage, PageHero, WRAP } from "@/components/marketing/MarketingPage";
import { Eyebrow, Pill } from "@/components/marketing/Pill";
import { Reveal } from "@/components/marketing/Reveal";
import { cn } from "@/lib/utils";

export const metadata: Metadata = {
  title: "About",
  description:
    "SASA Compliance grew from ten years of field experience on Tanzania's infrastructure and extractive projects, including the East African Crude Oil Pipeline (EACOP).",
};

export default function AboutPage() {
  return (
    <MarketingPage>
      <PageHero eyebrow="About" title="Born from the realities of project delivery.">
        <p>
          SASA Compliance grew from the field experience of our Founder and CEO, Kisare LUTAMBI, whose ten years in
          Tanzania&apos;s infrastructure and extractive projects include his work on the East African Crude Oil Pipeline
          (EACOP) project.
        </p>
      </PageHero>

      <section className={cn(WRAP, "pb-16 md:pb-24")}>
        <Reveal className="overflow-hidden rounded-[1.75rem]">
          <Image
            src="/landing/closing-film.webp"
            alt="A high-voltage transmission tower against an evening sky."
            width={1600}
            height={900}
            sizes="(min-width: 1240px) 1160px, 100vw"
            className="block aspect-[16/9] h-auto w-full object-cover md:aspect-[21/9]"
            priority
          />
        </Reveal>
      </section>

      <section className={cn(WRAP, "grid gap-14 pb-20 md:grid-cols-2 md:gap-16 md:pb-28")}>
        <Reveal>
          <Eyebrow>Our story</Eyebrow>
          <p className="mt-5 text-[1.25rem] leading-[1.5] tracking-[-0.015em] text-ink-800 md:text-[1.375rem]">
            Through this experience, our founder recognized a practical need: project teams need a clearer way to connect
            stakeholder engagement, grievances, affected-person records and contractor actions.
          </p>
          <p className="mt-5 text-[1.0625rem] leading-relaxed text-ink-600">
            SASA was conceived to help project and contractor teams keep track of what was raised, what was agreed, who is
            responsible and what still needs attention.
          </p>
        </Reveal>
        <Reveal delay={100}>
          <Eyebrow>Built around practical project needs</Eyebrow>
          <p className="mt-5 text-[1.25rem] leading-[1.5] tracking-[-0.015em] text-ink-800 md:text-[1.375rem]">
            We are translating lessons from demanding construction environments into a connected environmental and
            social performance platform.
          </p>
          <p className="mt-5 text-[1.0625rem] leading-relaxed text-ink-600">
            Our software is being prepared for first launch in Tanzania, bringing community and land workflows together
            with incident notification, contractor ESHS assurance and reporting.
          </p>
        </Reveal>
      </section>

      <section className="bg-surface py-20 md:py-24">
        <div className={cn(WRAP, "flex flex-col items-start justify-between gap-8 md:flex-row md:items-center")}>
          <h2 className="max-w-[36rem] text-[2rem] font-semibold leading-[1.06] tracking-[-0.04em] text-ink-900 md:text-[2.5rem]">
            Bring your project requirement into the conversation.
          </h2>
          <div className="flex flex-col gap-3 sm:flex-row">
            <Pill href="/register-interest">Register interest</Pill>
            <Pill href="/book-demo" tone="light" arrow={false}>
              Book a demo
            </Pill>
          </div>
        </div>
      </section>
    </MarketingPage>
  );
}
