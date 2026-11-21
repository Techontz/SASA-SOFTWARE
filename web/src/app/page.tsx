import type { Metadata } from "next";
import Image from "next/image";
import Link from "next/link";
import type { CSSProperties } from "react";
import {
  ArrowDown,
  ArrowRight,
  Check,
  FileText,
  Bot,
  EyeOff,
  History,
  Lock,
  Mic,
  ShieldCheck,
  Smartphone,
  SquareCheckBig,
  WifiOff,
  type LucideIcon,
} from "lucide-react";
import { BackgroundVideo } from "@/components/marketing/BackgroundVideo";
import { Faq } from "@/components/marketing/Faq";
import { SiteFooter } from "@/components/marketing/SiteFooter";
import { BrowserFrame, PhoneFrame } from "@/components/marketing/Frames";
import { LandingNav } from "@/components/marketing/LandingNav";
import { Eyebrow, Pill } from "@/components/marketing/Pill";
import { ProductTour } from "@/components/marketing/ProductTour";
import { Reveal } from "@/components/marketing/Reveal";
import { PLATFORM_MODULES } from "@/lib/content/modules";
import { cn } from "@/lib/utils";

export const metadata: Metadata = {
  title: { absolute: "SASA Compliance | Environmental and Social performance. Connected." },
  description:
    "SASA Compliance brings stakeholder engagement, grievances, project-affected person records, incident notification and contractor assurance into one connected platform for infrastructure and extractive projects.",
};

/* ---------------------------------------------------------------- content */

const SECTORS = ["Infrastructure", "Mining", "Energy", "Oil and Gas"];

const CHAIN = [
  { title: "Stakeholder", body: "Everyone the project affects, registered once and ranked transparently." },
  { title: "Engagement", body: "Every meeting planned and logged, with who attended and what was said." },
  { title: "Concern", body: "What people raised, captured in the room, never retyped later." },
  { title: "Grievance", body: "A formal case with one reference, one owner and one service-level clock." },
  { title: "Action", body: "The investigation and the work done, assigned and tracked." },
  { title: "Commitment", body: "Every promise made, owned and due, until verified as kept." },
  { title: "Resolution", body: "Closed on evidence, with the complainant's response on record." },
  { title: "Reporting", body: "Lender and management reports generated from the live record." },
];

const FIELD_POINTS: Array<{ icon: LucideIcon; title: string; text: string }> = [
  { icon: WifiOff, title: "No signal, no problem", text: "Register people, log meetings and record grievances offline. SASA syncs when the network returns." },
  { icon: SquareCheckBig, title: "Honest about saving", text: "Work is labelled as on the device until the server has it. Never reported as saved when it is not." },
  { icon: Smartphone, title: "On the phone they carry", text: "Runs in the browser and adds to the home screen. No special hardware to buy." },
  { icon: Mic, title: "Speak, then confirm", text: "Record a voice note; AI drafts the details and the officer confirms them." },
];

const SAFEGUARDS: Array<{ icon: LucideIcon; title: string; text: string }> = [
  { icon: EyeOff, title: "Identity stays protected", text: "On anonymous and confidential cases, identity is never sent to anyone who may not see it. The server decides, not the screen." },
  { icon: Lock, title: "Sensitive cases stay sealed", text: "Categories such as sexual exploitation and abuse are invisible outside their handling group." },
  { icon: History, title: "Nothing is ever deleted", text: "Archiving takes a record out of view. Every change is kept in an append-only audit trail." },
  { icon: Bot, title: "AI suggests, people decide", text: "Nothing an AI produces reaches a record until a person has reviewed it." },
  { icon: ShieldCheck, title: "Configured, not customised", text: "Categories, service levels and mandatory fields are set per project, without new software." },
  { icon: SquareCheckBig, title: "Read-only for auditors", text: "Lenders and auditors can be given a view of one project that changes nothing." },
];

const FAQ = [
  {
    q: "Does SASA work without an internet connection?",
    a: "Yes. After the first sign-in on a device, field officers can register stakeholders, log engagements and record grievances with no signal. Everything is kept on the device, clearly marked as not yet sent, and synced automatically when the connection returns.",
  },
  {
    q: "Who can see a complainant's identity?",
    a: "On a confidential case, only the handling group. On an anonymous case, nobody, because no identity is ever stored. Sensitive categories are invisible to everyone outside their handling group. This is enforced by the server, not by hiding fields in the browser.",
  },
  {
    q: "Can it be set up for our project's way of working?",
    a: "Yes. Stakeholder types, engagement methods, grievance categories, service levels, priority weighting, contractor lists and which fields are mandatory are all project configuration, changed by your administrator without new software.",
  },
  {
    q: "Can one organisation run several projects?",
    a: "Yes. Each project keeps its own registers, people and settings, and someone who works on more than one switches between them without signing out.",
  },
  {
    q: "How do we get started?",
    a: "Register your interest or book a demonstration, and tell us about your project and the workflows your team needs. We will contact you about SASA Compliance and relevant pilot opportunities as they become available.",
  },
];

/* ---------------------------------------------------------------- page */

const WRAP = "mx-auto w-full max-w-[1240px] px-4 sm:px-6 md:px-10";

export default function LandingPage() {
  return (
    <div id="top" className="overflow-x-clip bg-chrome-deep">
      <a
        href="#main"
        className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[70] focus:rounded-lg focus:bg-accent-solid focus:px-4 focus:py-2 focus:text-on-primary"
      >
        Skip to content
      </a>
      <LandingNav />

      <main id="main">
        <Hero />
        <Sectors />
        <Challenge />
        <Platform />
        <Chain />
        <Tour />
        <Field />
        <Safeguards />
        <FaqSection />
        <Closing />
      </main>

      <SiteFooter />
    </div>
  );
}

function SectionHead({
  eyebrow,
  title,
  body,
  onDark = false,
  className,
}: {
  eyebrow: string;
  title: string;
  body?: string;
  onDark?: boolean;
  className?: string;
}) {
  return (
    <Reveal className={cn("grid gap-5 md:grid-cols-[1.1fr_0.9fr] md:items-end md:gap-12", className)}>
      <div>
        <Eyebrow onDark={onDark}>{eyebrow}</Eyebrow>
        <h2
          className={cn(
            "mt-4 text-[2.375rem] font-semibold leading-[1.03] tracking-[-0.042em] md:text-[3.5rem]",
            onDark ? "text-white" : "text-ink-900",
          )}
        >
          {title}
        </h2>
      </div>
      {body ? (
        <p className={cn("text-[1.125rem] leading-[1.55] tracking-[-0.01em] md:text-[1.25rem]", onDark ? "text-chrome-muted" : "text-ink-600")}>{body}</p>
      ) : null}
    </Reveal>
  );
}

/* ---------------------------------------------------------------- hero */

function Hero() {
  return (
    <section className="relative isolate flex min-h-[100svh] flex-col justify-end overflow-hidden bg-chrome-deep text-white">
      <BackgroundVideo
        src="/landing/hero-film.mp4"
        srcSmall="/landing/hero-film-sm.mp4"
        poster="/landing/hero-film.webp"
        eager
        controlClassName="bottom-6 right-4 sm:right-6 md:right-10"
      />
      {/* Darkened enough that the headline holds its contrast on every frame. */}
      <div className="absolute inset-0 -z-10 bg-chrome-deep/45" aria-hidden />
      <div className="absolute inset-0 -z-10 bg-gradient-to-t from-chrome-deep via-chrome-deep/40 to-chrome-deep/60" aria-hidden />

      <div className={cn(WRAP, "pb-20 pt-36 md:pb-24")}>
        <p className="lp-rise text-[0.9375rem] font-medium text-chrome-fg" style={{ "--d": "0ms" } as CSSProperties}>
          SASA Compliance, for infrastructure and extractive projects
        </p>
        <h1
          className="lp-rise mt-5 max-w-[18ch] text-[2.75rem] font-semibold leading-[1] tracking-[-0.05em] text-white sm:text-[4.25rem] lg:text-[5.5rem]"
          style={{ "--d": "120ms" } as CSSProperties}
        >
          Environmental and Social performance.{" "}
          <span className="relative inline-block whitespace-nowrap text-accent-300">
            Connected.
            <svg
              viewBox="0 0 400 24"
              preserveAspectRatio="none"
              className="pointer-events-none absolute -bottom-[0.14em] left-[-1%] h-[0.2em] w-[100%]"
              aria-hidden
            >
              <defs>
                <linearGradient id="hero-stroke" x1="0" x2="1" y1="0" y2="0">
                  <stop offset="0" stopColor="var(--color-accent-solid)" />
                  <stop offset="1" stopColor="var(--color-accent-300)" />
                </linearGradient>
              </defs>
              <path
                d="M4 16 C 90 7, 210 4, 396 11"
                fill="none"
                stroke="url(#hero-stroke)"
                strokeWidth="7"
                strokeLinecap="round"
                pathLength={1}
                className="lp-underline"
              />
            </svg>
          </span>
        </h1>
        <p
          className="lp-rise mt-7 max-w-[38rem] text-[1.125rem] leading-[1.5] tracking-[-0.01em] text-chrome-fg md:text-[1.375rem]"
          style={{ "--d": "240ms" } as CSSProperties}
        >
          We are building SASA Compliance to bring stakeholder engagement, grievances, project-affected person
          records, incident notification and contractor assurance into one connected platform for infrastructure and
          extractive projects.
        </p>
        <div className="lp-rise mt-10 flex flex-col gap-3 sm:flex-row" style={{ "--d": "360ms" } as CSSProperties}>
          <Pill href="/book-demo">Book a demo</Pill>
          <Pill href="/register-interest" tone="glass" arrow={false}>
            Register interest
          </Pill>
        </div>

        <dl
          className="lp-rise mt-16 grid gap-6 border-t border-chrome-fg/20 pt-7 sm:grid-cols-3 md:mt-20"
          style={{ "--d": "480ms" } as CSSProperties}
        >
          {[
            ["Stakeholders and engagement", "Registers, consultations, concerns and commitments"],
            ["Grievances and PAP records", "Cases to closure, households, land and compensation"],
            ["Incidents and contractors", "Incident notification and ESHS contractor assurance"],
          ].map(([term, detail]) => (
            <div key={term}>
              <dt className="text-[0.9375rem] font-semibold text-white">{term}</dt>
              <dd className="mt-1 text-sm text-chrome-muted">{detail}</dd>
            </div>
          ))}
        </dl>
      </div>
    </section>
  );
}

/* ---------------------------------------------------------------- sectors */

function Sectors() {
  return (
    <section className="border-b border-hairline bg-surface" aria-labelledby="sectors-heading">
      <div className={cn(WRAP, "flex flex-col gap-5 py-8 lg:flex-row lg:items-center lg:gap-10")}>
        <p id="sectors-heading" className="shrink-0 text-sm font-medium text-ink-500">
          Being developed for
        </p>
        <ul className="flex flex-1 flex-wrap gap-x-10 gap-y-2 text-[0.9375rem] font-semibold text-ink-800">
          {SECTORS.map((sector) => (
            <li key={sector} className="whitespace-nowrap">{sector}</li>
          ))}
        </ul>
      </div>
    </section>
  );
}

/* ---------------------------------------------------------------- the challenge */

const SCATTERED = ["Consultation minutes.docx", "Complaints log.xlsx", "Compensation schedule.pdf", "ESHS inspection.xlsx"];
const QUESTIONS = ["Who is responsible?", "What was agreed?", "What evidence is available?", "What still needs attention?"];

function Challenge() {
  return (
    <section id="challenge" className="scroll-mt-20 bg-canvas py-20 md:py-28">
      <div className={cn(WRAP, "grid items-center gap-14 lg:grid-cols-2 lg:gap-20")}>
        <Reveal>
          <Eyebrow>The challenge we are addressing</Eyebrow>
          <h2 className="mt-4 text-[2.375rem] font-semibold leading-[1.03] tracking-[-0.042em] text-ink-900 md:text-[3.5rem]">
            Keep important actions from getting lost between records.
          </h2>
          <p className="mt-6 text-[1.125rem] leading-[1.55] text-ink-600 md:text-[1.25rem]">
            Consultations, complaints, compensation records and ESHS inspection findings often sit in separate Excel,
            PDF and Word files. Project teams need to know who is responsible, what was agreed, what evidence is
            available and what still needs attention. SASA is being developed around these practical project needs.
          </p>
        </Reveal>

        <Reveal delay={120} className="relative">
          <div className="sasa-card p-6 md:p-8">
            <p className="text-sm font-medium text-ink-500">Today, spread across files</p>
            <ul className="mt-4 grid gap-2.5 sm:grid-cols-2">
              {SCATTERED.map((file) => (
                <li
                  key={file}
                  className="flex items-center gap-2.5 truncate rounded-xl bg-surface-sunken px-3.5 py-3 text-sm text-ink-600 ring-1 ring-hairline"
                >
                  <FileText className="h-4 w-4 shrink-0 text-ink-400" aria-hidden />
                  <span className="truncate">{file}</span>
                </li>
              ))}
            </ul>
            <div className="my-6 flex items-center gap-3 text-sm font-medium text-brand-700">
              <span className="h-px flex-1 bg-hairline" aria-hidden />
              <ArrowDown className="h-4 w-4" aria-hidden />
              Connected in SASA
              <span className="h-px flex-1 bg-hairline" aria-hidden />
            </div>
            <ul className="divide-y divide-hairline overflow-hidden rounded-2xl ring-1 ring-hairline">
              {QUESTIONS.map((question) => (
                <li key={question} className="flex items-center gap-3 bg-surface px-4 py-3.5 text-[0.9375rem] font-medium text-ink-900">
                  <span className="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-brand-50 text-brand-600">
                    <Check className="h-3.5 w-3.5" aria-hidden />
                  </span>
                  {question}
                </li>
              ))}
            </ul>
          </div>
        </Reveal>
      </div>
    </section>
  );
}

/* ---------------------------------------------------------------- platform */

function Platform() {
  return (
    <section id="platform" className="scroll-mt-20 bg-canvas py-20 md:py-28">
      <div className={WRAP}>
        <SectionHead
          eyebrow="The platform"
          title="One platform for connected project workflows."
          body="Stakeholder register, engagement, grievances, the PAP master register, incident notification, ESHS contractor assurance and reporting, in one place."
        />

        <Reveal className="mt-14">
          <BrowserFrame
            src="/landing/screens/dashboard-light.webp"
            alt="The SASA dashboard: quick actions, the cases that need attention today, and the state of the project."
            width={2400}
            height={1500}
            url="sasacompliance.com/dashboard"
            tone="light"
            priority
          />
          <p className="mt-4 text-center text-xs text-ink-500">Real screens from SASA, shown with demonstration data.</p>
        </Reveal>

        <ul className="mt-16 grid gap-x-8 gap-y-0 md:grid-cols-2 lg:grid-cols-3">
          {PLATFORM_MODULES.map((module, index) => (
            <Reveal as="li" key={module.key} delay={(index % 3) * 70} className="border-t border-hairline">
              <Link href={`/platform#${module.key}`} className="group flex items-start gap-4 py-6">
                <span className="font-mono text-sm text-ink-500">{String(index + 1).padStart(2, "0")}</span>
                <span className="min-w-0 flex-1">
                  <span className="block text-[1.125rem] font-semibold tracking-[-0.02em] text-ink-900">{module.name}</span>
                  <span className="mt-1.5 line-clamp-2 block text-[0.9375rem] leading-relaxed text-ink-600">{module.summary}</span>
                </span>
                <ArrowRight className="mt-1 h-4 w-4 shrink-0 text-ink-400 transition group-hover:translate-x-0.5 group-hover:text-ink-900" aria-hidden />
              </Link>
            </Reveal>
          ))}
        </ul>
        <div className="mt-10">
          <Pill href="/platform" tone="light">
            Explore the modules
          </Pill>
        </div>
      </div>
    </section>
  );
}

/* ---------------------------------------------------------------- the chain */

function Chain() {
  return (
    <section id="how" className="scroll-mt-20 bg-surface py-20 md:py-28">
      <div className={WRAP}>
        <SectionHead
          eyebrow="How it works"
          title="From the first conversation to the closing report."
          body="SASA keeps one unbroken chain of records. Each step is linked to the one before it, so a promise made at a meeting can always be traced to the people it was made to."
        />

        <ol className="mt-14 grid border-l border-t border-hairline sm:grid-cols-2 lg:grid-cols-4">
          {CHAIN.map((step, index) => (
            <Reveal
              as="li"
              key={step.title}
              delay={(index % 4) * 70}
              className="border-b border-r border-hairline p-6 md:p-7"
            >
              <span className="font-mono text-sm text-ink-500">{String(index + 1).padStart(2, "0")}</span>
              <h3 className="mt-10 text-[1.25rem] font-semibold tracking-[-0.025em] text-ink-900">{step.title}</h3>
              <p className="mt-2 text-[0.9375rem] leading-relaxed text-ink-600">{step.body}</p>
            </Reveal>
          ))}
        </ol>
      </div>
    </section>
  );
}

/* ---------------------------------------------------------------- product tour */

function Tour() {
  return (
    <section id="product" className="scroll-mt-20 bg-canvas py-20 md:py-28">
      <div className={WRAP}>
        <SectionHead
          eyebrow="Product"
          title="Built for the work, not around a spreadsheet."
          body="Each register does one job well, and each one knows about the others. These are the real screens."
        />
        <Reveal className="mt-12">
          <ProductTour />
        </Reveal>
      </div>
    </section>
  );
}

/* ---------------------------------------------------------------- field */

function Field() {
  return (
    <section id="field" className="scroll-mt-20 bg-surface py-20 md:py-28">
      <div className={cn(WRAP, "grid items-center gap-14 lg:grid-cols-2 lg:gap-20")}>
        <Reveal className="relative mx-auto w-full max-w-[34rem] pb-10 pr-10 sm:pb-14 sm:pr-16 lg:mx-0">
          <div className="overflow-hidden rounded-[1.25rem]">
            <Image
              src="/landing/field-tablet.webp"
              alt="An engineer in a high-visibility vest recording on a tablet at a construction site."
              width={1100}
              height={1100}
              sizes="(min-width: 1024px) 520px, 90vw"
              className="block aspect-[4/5] h-auto w-full object-cover"
            />
          </div>
          <PhoneFrame
            src="/landing/screens/phone-grievance.webp"
            alt="Logging a grievance on a phone in SASA: the confidentiality of the case is chosen first."
            className="absolute bottom-0 right-0 w-[44%] max-w-[250px]"
          />
        </Reveal>

        <div>
          <SectionHead
            eyebrow="In the field"
            title="Made for the site, not only the office."
            className="md:grid-cols-1"
          />
          <Reveal>
            <p className="mt-5 max-w-[34rem] text-[1.0625rem] leading-relaxed text-ink-600">
              The people SASA serves are rarely at a desk. Everything a field officer needs fits on the phone in their
              pocket, with or without a network.
            </p>
          </Reveal>
          <ul className="mt-10 grid gap-x-8 gap-y-8 sm:grid-cols-2">
            {FIELD_POINTS.map(({ icon: Icon, title, text }, index) => (
              <Reveal as="li" key={title} delay={index * 60}>
                <Icon className="h-5 w-5 text-brand-700" aria-hidden />
                <h3 className="mt-3 text-base font-semibold text-ink-900">{title}</h3>
                <p className="mt-1.5 text-[0.9375rem] leading-relaxed text-ink-600">{text}</p>
              </Reveal>
            ))}
          </ul>
        </div>
      </div>
    </section>
  );
}

/* ---------------------------------------------------------------- safeguards */

function Safeguards() {
  return (
    <section id="safeguards" className="scroll-mt-20 bg-chrome-deep py-20 text-white md:py-28">
      <div className={WRAP}>
        <SectionHead
          eyebrow="Safeguards"
          title="Built to protect the people it records."
          body="A grievance mechanism only works if people trust it. SASA's protections live in the system itself, so they hold whoever is using it."
          onDark
        />

        <ul className="mt-14 grid gap-px overflow-hidden rounded-[1.25rem] bg-chrome-line ring-1 ring-chrome-line sm:grid-cols-2 lg:grid-cols-3">
          {SAFEGUARDS.map(({ icon: Icon, title, text }, index) => (
            <Reveal as="li" key={title} delay={(index % 3) * 70} className="bg-chrome-deep p-7">
              <Icon className="h-5 w-5 text-chrome-accent" aria-hidden />
              <h3 className="mt-5 text-[1.0625rem] font-semibold text-white">{title}</h3>
              <p className="mt-2 text-[0.9375rem] leading-relaxed text-chrome-muted">{text}</p>
            </Reveal>
          ))}
        </ul>
      </div>
    </section>
  );
}

/* ---------------------------------------------------------------- FAQ */

function FaqSection() {
  return (
    <section id="faq" className="scroll-mt-20 bg-canvas py-20 md:py-28">
      <div className={cn(WRAP, "grid gap-12 lg:grid-cols-[0.8fr_1.2fr] lg:gap-16")}>
        <Reveal>
          <Eyebrow>Questions</Eyebrow>
          <h2 className="mt-4 text-[2.375rem] font-semibold leading-[1.03] tracking-[-0.042em] text-ink-900 md:text-[3.5rem]">
            What teams ask before they start.
          </h2>
        </Reveal>
        <Reveal delay={100}>
          <Faq items={FAQ} />
        </Reveal>
      </div>
    </section>
  );
}

/* ---------------------------------------------------------------- closing */

function Closing() {
  return (
    <section className="relative isolate overflow-hidden bg-chrome-deep text-white">
      <BackgroundVideo src="/landing/closing-film.mp4" poster="/landing/closing-film.webp" controlClassName="bottom-6 right-4 sm:right-6 md:right-10" />
      <div className="absolute inset-0 -z-10 bg-chrome-deep/55" aria-hidden />
      <div className="absolute inset-0 -z-10 bg-gradient-to-r from-chrome-deep/90 via-chrome-deep/50 to-transparent" aria-hidden />
      <div className={cn(WRAP, "py-28 md:py-40")}>
        <Reveal className="max-w-[40rem]">
          <h2 className="text-[2.75rem] font-semibold leading-[1] tracking-[-0.05em] text-white md:text-[4.5rem]">
            Bring your project requirement into the conversation.
          </h2>
          <p className="mt-6 max-w-[32rem] text-[1.0625rem] leading-relaxed text-chrome-fg">
            Tell us about your project and the workflows your team needs. We will contact you about SASA Compliance and
            relevant pilot opportunities as they become available.
          </p>
          <div className="mt-10 flex flex-col gap-3 sm:flex-row">
            <Pill href="/register-interest">Register interest</Pill>
            <Pill href="/book-demo" tone="glass" arrow={false}>
              Book a demo
            </Pill>
          </div>
        </Reveal>
      </div>
    </section>
  );
}
