import type { Metadata } from "next";
import Link from "next/link";
import type { CSSProperties } from "react";
import {
  BarChart3,
  Bot,
  CalendarClock,
  ClipboardCheck,
  EyeOff,
  Handshake,
  HardHat,
  History,
  Lock,
  MessagesSquare,
  ScrollText,
  ShieldCheck,
  Siren,
  UserRoundCog,
  Users,
  WifiOff,
  type LucideIcon,
} from "lucide-react";
import { BrandMark, DashboardPreview, Landscape } from "@/components/marketing/Brand";
import { Faq } from "@/components/marketing/Faq";
import { LandingNav } from "@/components/marketing/LandingNav";
import { Eyebrow, Pill } from "@/components/marketing/Pill";
import { Reveal } from "@/components/marketing/Reveal";
import { cn } from "@/lib/utils";

export const metadata: Metadata = {
  title: { absolute: "SASA — Stakeholder engagement and E&S compliance" },
  description:
    "One trusted record for stakeholders, engagements, grievances, commitments, project-affected persons, contractor ESHS assurance and incidents — offline in the field, confidential by design.",
};

/* ---------------------------------------------------------------- content */

type Module = { icon: LucideIcon; title: string; body: string; accent?: boolean };

const MODULES: Module[] = [
  { icon: Users, title: "Stakeholder register", body: "Who the stakeholders are, prioritised by influence, interest, power and impact — transparently." },
  { icon: CalendarClock, title: "Engagements", body: "Planned against actual on every meeting, with attendance, minutes and evidence." },
  { icon: ClipboardCheck, title: "Grievances", body: "Every channel into one case, one reference and one service-level clock, with escalation.", accent: true },
  { icon: Handshake, title: "Commitments", body: "What was promised, to whom, by when — owned, reminded and verified at closure." },
  { icon: UserRoundCog, title: "PAP register", body: "Project-affected persons from valuation to payment, linked to their stakeholder record." },
  { icon: HardHat, title: "ESHS assurance", body: "Contractor anomalies raised, actioned and closed only on verified evidence." },
  { icon: Siren, title: "Incidents", body: "Injuries, spills, losses and security events notified with actual and potential consequence." },
  { icon: BarChart3, title: "Reporting", body: "Dashboards and reports generated from live data, every metric with its definition." },
];

const STEPS = [
  { title: "Register", body: "Record every stakeholder and project-affected person once, with consent and the way they want to be reached." },
  { title: "Engage", body: "Plan engagements, log what was said, and capture concerns and commitments in the same sitting — even with no signal." },
  { title: "Resolve", body: "Each grievance runs on its own clock, with acknowledgement, investigation and escalation that nobody has to remember." },
  { title: "Report", body: "Lender and management reporting comes from the live record, so the numbers and the evidence always agree." },
];

const SAFEGUARDS: Array<{ icon: LucideIcon; text: string }> = [
  { icon: EyeOff, text: "Anonymous and confidential cases are protected at the server — identity is never sent to someone who may not see it." },
  { icon: Lock, text: "Sensitive categories are invisible outside their handling group, not merely hidden on screen." },
  { icon: History, text: "Nothing is deleted. Every change is written to an append-only audit trail." },
  { icon: Bot, text: "AI only suggests. A person reviews and decides before anything reaches the record." },
  { icon: WifiOff, text: "Work saved offline is labelled as on the device until the server has it — never reported as saved when it is not." },
];

const PRINCIPLES = [
  { word: "Offline-first", detail: "Field teams capture everything without signal; it syncs when the network returns." },
  { word: "Confidential", detail: "Who may see a complainant is decided by the server, case by case." },
  { word: "Auditable", detail: "Who changed what, and when, is kept for every record — permanently." },
  { word: "Configurable", detail: "Lists, mandatory fields and service levels are set per project, without a release." },
];

const FAQ = [
  {
    q: "Does SASA work without an internet connection?",
    a: "Yes. After the first sign-in on a device, field officers can register stakeholders, log engagements and record grievances with no signal. Everything is kept on the device, clearly marked as not yet sent, and synced automatically when the connection returns.",
  },
  {
    q: "Who can see a complainant's identity?",
    a: "On a confidential case, only the handling group. On an anonymous case, nobody — no identity is ever stored. Sensitive categories such as sexual exploitation and abuse are invisible to everyone outside their handling group. This is enforced by the server, not by hiding fields in the browser.",
  },
  {
    q: "Can it be set up for our project's way of working?",
    a: "Yes. Stakeholder types, engagement methods, grievance categories, service levels, priority weighting, contractor lists and which fields are mandatory are all project configuration, changed by your administrator without new software.",
  },
  {
    q: "Is anything ever deleted?",
    a: "No. Archiving takes a record out of the active registers, and every change — including archiving — is written to an audit trail that cannot be edited.",
  },
  {
    q: "How do I get started?",
    a: "SASA accounts are created by your project administrator, with the role that matches your work. Once you have yours, sign in with your work e-mail and password.",
  },
];

/* ---------------------------------------------------------------- page */

export default function LandingPage() {
  return (
    <div className="bg-chrome-deep">
      <a
        href="#main"
        className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[70] focus:rounded-full focus:bg-accent-solid focus:px-4 focus:py-2 focus:text-on-primary"
      >
        Skip to content
      </a>
      <LandingNav />

      <main id="main">
        <Hero />
        <Ecosystem />
        <ToLight />
        <Mission />
        <ModuleGrid />
        <ToDark />
        <How />
        <Safeguards />
        <FaqSection />
        <FinalCta />
      </main>

      <Footer />
    </div>
  );
}

function rise(delay: number): CSSProperties {
  return { "--d": `${delay}ms` } as CSSProperties;
}

/* ---------------------------------------------------------------- hero */

function Hero() {
  return (
    <section className="relative isolate overflow-hidden bg-chrome-deep text-white">
      <div className="lp-grid absolute inset-0 -z-10" aria-hidden />
      <div
        className="absolute left-1/2 top-24 -z-10 h-[34rem] w-[34rem] -translate-x-1/2 rounded-full bg-accent-solid opacity-[0.12] blur-[140px]"
        aria-hidden
      />
      <Landscape className="-z-10 h-[38%] opacity-90" />

      <div className="mx-auto flex max-w-[1240px] flex-col items-center px-4 pb-20 pt-36 text-center sm:px-6 md:px-10 md:pt-44">
        <p className="lp-rise text-[0.8125rem] font-medium uppercase tracking-[0.26em] text-chrome-muted" style={rise(0)}>
          People <span className="mx-2 text-accent-300">●</span> Insights <span className="mx-2 text-accent-300">●</span> Action
        </p>

        <h1
          className="lp-rise mt-6 max-w-[18ch] text-[2.75rem] font-semibold leading-[1.02] tracking-[-0.045em] text-white sm:text-[4rem] lg:text-[5.25rem]"
          style={rise(140)}
        >
          Every stakeholder voice,{" "}
          <span className="text-accent-300">
            one trusted{" "}
            <span className="relative inline-block">
              record.
              <svg
              viewBox="0 0 300 20"
              preserveAspectRatio="none"
              className="absolute -bottom-2 left-0 h-[0.28em] w-full"
              aria-hidden
            >
              <path
                d="M3 14 C 60 4, 140 3, 297 9"
                fill="none"
                stroke="var(--color-accent-solid)"
                strokeWidth="6"
                strokeLinecap="round"
                className="lp-draw"
              />
              </svg>
            </span>
          </span>
        </h1>

        <p
          className="lp-rise mt-8 max-w-[40rem] text-[1.0625rem] leading-[1.6] text-chrome-fg/80 md:text-xl"
          style={rise(280)}
        >
          SASA turns stakeholder conversations into actionable intelligence — from registration and engagement to
          grievance closure, commitments, project-affected persons, contractor ESHS assurance and incidents.
        </p>

        <div className="lp-rise mt-10 flex w-full flex-col items-center gap-3 sm:w-auto sm:flex-row" style={rise(400)}>
          <Pill href="/sign-in" className="w-full justify-between sm:w-auto">
            Get started
          </Pill>
          <Pill href="/sign-in" tone="glass" arrow={false} className="w-full justify-center sm:w-auto">
            Log in to SASA
          </Pill>
        </div>

        <ul className="lp-rise mt-10 flex flex-wrap justify-center gap-x-6 gap-y-3 text-sm text-chrome-muted" style={rise(520)}>
          {[
            { icon: WifiOff, label: "Works offline in the field" },
            { icon: ShieldCheck, label: "Confidential by design" },
            { icon: ScrollText, label: "Every change audited" },
          ].map(({ icon: Icon, label }) => (
            <li key={label} className="flex items-center gap-2">
              <Icon className="h-4 w-4 text-chrome-accent" aria-hidden />
              {label}
            </li>
          ))}
        </ul>

        <div className="lp-rise relative mt-16 w-full max-w-[52rem]" style={rise(640)}>
          <div className="absolute -inset-6 rounded-[2rem] bg-chrome-accent/10 blur-2xl" aria-hidden />
          <DashboardPreview className="lp-drift relative flex" />
        </div>
      </div>
    </section>
  );
}

/* ---------------------------------------------------------------- ecosystem */

const RADIUS = 39;

function position(index: number) {
  const angle = (-90 + index * 45) * (Math.PI / 180);
  return { x: 50 + RADIUS * Math.cos(angle), y: 50 + RADIUS * Math.sin(angle) };
}

function Hub({ className }: { className?: string }) {
  return (
    <div
      className={cn(
        "relative grid place-items-center rounded-full bg-chrome text-center ring-[6px] ring-chrome-accent/70",
        className,
      )}
    >
      <span className="lp-halo absolute -inset-3.5 rounded-full ring-1 ring-chrome-accent/40" aria-hidden />
      <div className="px-5">
        <BrandMark className="mx-auto h-11 w-11" />
        <p className="mt-3 text-2xl font-semibold tracking-[-0.02em] text-white">SASA</p>
        <p className="mt-1 text-sm leading-snug text-chrome-accent">
          One trusted
          <br />
          source of truth
        </p>
      </div>
    </div>
  );
}

function NodeCard({ module, compact = false }: { module: Module; compact?: boolean }) {
  const Icon = module.icon;

  return (
    <div
      className={cn(
        "group relative rounded-[0.875rem] bg-chrome text-center ring-1 transition-colors duration-300 hover:bg-chrome-raised",
        module.accent ? "ring-accent-400/60" : "ring-chrome-line hover:ring-chrome-accent/70",
        compact ? "h-full px-3 py-4" : "w-[10.5rem] px-3 py-3.5",
      )}
    >
      <span
        className={cn(
          "mx-auto grid h-9 w-9 place-items-center rounded-[0.625rem]",
          module.accent ? "bg-accent-solid/20 text-accent-300" : "bg-chrome-accent/15 text-chrome-accent",
        )}
      >
        <Icon className="h-[18px] w-[18px]" aria-hidden />
      </span>
      <p className="mt-2.5 text-[0.90625rem] font-semibold leading-tight text-white">{module.title}</p>
      {compact ? (
        <p className="mt-2 text-[0.78125rem] leading-snug text-chrome-muted">{module.body}</p>
      ) : (
        <p className="pointer-events-none absolute left-1/2 top-full z-20 mt-2 w-56 -translate-x-1/2 rounded-[0.625rem] bg-surface px-3 py-2 text-[0.78125rem] leading-snug text-ink-800 opacity-0 shadow-[var(--shadow-overlay)] transition-opacity duration-200 group-hover:opacity-100">
          {module.body}
        </p>
      )}
    </div>
  );
}

function Ecosystem() {
  return (
    <section id="platform" className="relative scroll-mt-20 overflow-hidden bg-chrome-deep py-20 md:py-28">
      <div
        className="pointer-events-none absolute left-1/2 top-1/2 h-[45rem] w-[45rem] -translate-x-1/2 -translate-y-1/2 rounded-full bg-chrome-accent opacity-[0.07] blur-[140px]"
        aria-hidden
      />
      <div className="relative mx-auto max-w-[1240px] px-4 sm:px-6 md:px-10">
        <Reveal className="mx-auto max-w-[48rem] text-center">
          <Eyebrow onDark>The SASA platform</Eyebrow>
          <h2 className="mt-5 text-[2.25rem] font-semibold leading-[1.08] tracking-[-0.035em] text-white md:text-[3.25rem]">
            Eight registers. <span className="text-chrome-muted">One record that ties them together.</span>
          </h2>
          <p className="mx-auto mt-6 max-w-[42rem] text-[1.0625rem] leading-relaxed text-chrome-muted">
            A concern raised at a meeting becomes a grievance without being retyped. A promise made there becomes a
            tracked commitment. A project-affected person is a stakeholder too. Everything links, so nothing is lost
            between teams.
          </p>
        </Reveal>

        {/* Wide screens: hub and spokes. */}
        <Reveal className="relative mx-auto mt-16 hidden aspect-square w-full max-w-[52rem] lg:block">
          <svg className="absolute inset-0 h-full w-full" viewBox="0 0 100 100" aria-hidden>
            {MODULES.map((module, index) => {
              const point = position(index);
              return (
                <g key={module.title}>
                  <line
                    x1="50"
                    y1="50"
                    x2={point.x}
                    y2={point.y}
                    stroke="var(--color-chrome-accent)"
                    strokeOpacity="0.5"
                    strokeWidth="0.25"
                    strokeDasharray="1.2 1.2"
                    className="lp-flow"
                  />
                  <circle r="0.7" fill={module.accent ? "var(--color-accent-300)" : "var(--color-chrome-accent)"}>
                    <animateMotion
                      dur={`${3.2 + (index % 3) * 0.6}s`}
                      repeatCount="indefinite"
                      begin={`${index * 0.35}s`}
                      path={`M50,50 L${point.x},${point.y}`}
                    />
                  </circle>
                </g>
              );
            })}
          </svg>
          <div className="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2">
            <Hub className="h-64 w-64" />
          </div>
          {MODULES.map((module, index) => {
            const point = position(index);
            return (
              <div
                key={module.title}
                className="absolute -translate-x-1/2 -translate-y-1/2"
                style={{ left: `${point.x}%`, top: `${point.y}%` }}
              >
                <NodeCard module={module} />
              </div>
            );
          })}
        </Reveal>

        {/* Phones and tablets: the hub, then the registers as a grid. */}
        <div className="mt-12 lg:hidden">
          <Hub className="mx-auto h-52 w-52" />
          <div className="mx-auto h-8 w-px border-l border-dashed border-chrome-accent/60" aria-hidden />
          <ul className="grid grid-cols-2 gap-3 md:grid-cols-4">
            {MODULES.map((module, index) => (
              <Reveal as="li" key={module.title} delay={(index % 2) * 80}>
                <NodeCard module={module} compact />
              </Reveal>
            ))}
          </ul>
        </div>
      </div>
    </section>
  );
}

/* ---------------------------------------------------------------- transitions */

function ToLight() {
  return <div aria-hidden className="h-24 bg-gradient-to-b from-chrome-deep to-canvas md:h-36" />;
}

function ToDark() {
  return <div aria-hidden className="h-24 bg-gradient-to-b from-canvas to-chrome-deep md:h-36" />;
}

/* ---------------------------------------------------------------- mission */

function Mission() {
  const statement =
    "A promise made at a village meeting should be as traceable as a signed contract. SASA makes it so.";
  const words = statement.split(" ");

  return (
    <section className="bg-canvas py-16 md:py-24">
      <div className="mx-auto max-w-[1240px] px-4 sm:px-6 md:px-10">
        <Reveal className="max-w-[60rem]">
          <Eyebrow>Why SASA</Eyebrow>
          <p className="mt-6 text-[1.875rem] font-medium leading-[1.22] tracking-[-0.03em] text-ink-900 md:text-[2.875rem]">
            {words.map((word, index) => (
              <span key={`${word}-${index}`} className={index < words.length * 0.6 ? undefined : "text-brand-600"}>
                {word}{" "}
              </span>
            ))}
          </p>
          <p className="mt-7 max-w-[47rem] text-[1.0625rem] leading-relaxed text-ink-600">
            Infrastructure and extractive projects are judged on how they treat the people around them. SASA gives
            community relations, grievance, safeguards and HSE teams one shared record — so every voice is heard,
            every commitment is kept, and the evidence is there when lenders and auditors ask.
          </p>
        </Reveal>

        <div className="mt-14 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {PRINCIPLES.map((principle, index) => (
            <Reveal key={principle.word} delay={index * 80}>
              <div className="relative h-full overflow-hidden rounded-2xl bg-surface p-6 ring-1 ring-hairline lg:p-7">
                <span
                  className={cn(
                    "absolute left-7 top-0 h-1 w-10 rounded-b-full",
                    index % 2 ? "bg-accent-solid" : "bg-primary",
                  )}
                  aria-hidden
                />
                <p className="text-[1.75rem] font-semibold tracking-[-0.03em] text-brand-700">{principle.word}</p>
                <p className="mt-4 text-[0.9375rem] leading-snug text-ink-600">{principle.detail}</p>
              </div>
            </Reveal>
          ))}
        </div>
      </div>
    </section>
  );
}

/* ---------------------------------------------------------------- modules */

function ModuleGrid() {
  return (
    <section className="bg-canvas pb-8 pt-8 md:pb-16">
      <div className="mx-auto max-w-[1240px] px-4 sm:px-6 md:px-10">
        <Reveal className="flex flex-col justify-between gap-6 lg:flex-row lg:items-end">
          <div className="max-w-[40rem]">
            <Eyebrow>What is inside</Eyebrow>
            <h2 className="mt-5 text-[2.25rem] font-semibold leading-[1.08] tracking-[-0.035em] text-ink-900 md:text-[3rem]">
              Built for the work, not around a spreadsheet.
            </h2>
          </div>
          <p className="max-w-[28rem] text-base leading-relaxed text-ink-600">
            Each register does one job well, and each one knows about the others.
          </p>
        </Reveal>

        <div className="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {MODULES.map((module, index) => {
            const Icon = module.icon;
            return (
              <Reveal key={module.title} delay={(index % 4) * 70}>
                <article className="group h-full rounded-[1.25rem] bg-surface p-6 ring-1 ring-hairline transition-all duration-300 hover:-translate-y-1 hover:shadow-[var(--shadow-card)] hover:ring-brand-300">
                  <span
                    className={cn(
                      "grid h-11 w-11 place-items-center rounded-2xl",
                      module.accent ? "bg-accent-100 text-accent-700" : "bg-brand-100 text-brand-700",
                    )}
                  >
                    <Icon className="h-5 w-5" aria-hidden />
                  </span>
                  <h3 className="mt-5 text-lg font-semibold tracking-[-0.015em] text-ink-900">{module.title}</h3>
                  <p className="mt-2 text-[0.9375rem] leading-relaxed text-ink-600">{module.body}</p>
                </article>
              </Reveal>
            );
          })}
        </div>
      </div>
    </section>
  );
}

/* ---------------------------------------------------------------- how it works */

function How() {
  return (
    <section id="how" className="scroll-mt-20 bg-chrome-deep py-16 md:py-24">
      <div className="mx-auto max-w-[1240px] px-4 sm:px-6 md:px-10">
        <Reveal className="mx-auto max-w-[45rem] text-center">
          <Eyebrow onDark>How it works</Eyebrow>
          <h2 className="mt-5 text-[2.125rem] font-semibold leading-[1.1] tracking-[-0.035em] text-white md:text-[3rem]">
            From the first conversation to the closing report.
          </h2>
        </Reveal>

        <ol className="mt-14 grid gap-5 md:grid-cols-2 lg:grid-cols-4">
          {STEPS.map((step, index) => (
            <Reveal as="li" key={step.title} delay={index * 90}>
              <div className="relative h-full rounded-3xl bg-chrome p-6 ring-1 ring-chrome-line">
                <span
                  className={cn(
                    "grid h-11 w-11 place-items-center rounded-full font-mono text-sm",
                    index === STEPS.length - 1 ? "bg-accent-solid text-on-primary" : "bg-chrome-raised text-white",
                  )}
                >
                  {String(index + 1).padStart(2, "0")}
                </span>
                <h3 className="mt-6 text-lg font-semibold tracking-[-0.015em] text-white">{step.title}</h3>
                <p className="mt-2 text-[0.90625rem] leading-relaxed text-chrome-muted">{step.body}</p>
              </div>
            </Reveal>
          ))}
        </ol>

        <div className="mt-12 flex flex-col items-center justify-center gap-3 sm:flex-row">
          <Pill href="/sign-in">Get started</Pill>
          <Pill href="/sign-in" tone="glass" arrow={false}>
            Log in
          </Pill>
        </div>
      </div>
    </section>
  );
}

/* ---------------------------------------------------------------- safeguards */

function Safeguards() {
  return (
    <section id="safeguards" className="scroll-mt-20 bg-chrome-deep py-16 md:py-24">
      <div className="mx-auto grid max-w-[1240px] gap-12 px-4 sm:px-6 md:px-10 lg:grid-cols-2 lg:items-center lg:gap-16">
        <Reveal>
          <Eyebrow onDark>Safeguards</Eyebrow>
          <h2 className="mt-5 text-[2.25rem] font-semibold leading-[1.06] tracking-[-0.035em] text-white md:text-[3.25rem]">
            Built to protect the people it records.
          </h2>
          <p className="mt-6 max-w-[32rem] text-[1.0625rem] leading-relaxed text-chrome-muted">
            A grievance mechanism only works if people trust it. SASA&apos;s protections live in the system itself,
            so they hold whoever is using it.
          </p>
          <div className="mt-9">
            <Pill href="#faq" tone="glass">
              Read the questions people ask
            </Pill>
          </div>
        </Reveal>

        <ul className="grid gap-3">
          {SAFEGUARDS.map(({ icon: Icon, text }, index) => (
            <Reveal as="li" key={text} delay={index * 70}>
              <div className="flex items-center gap-5 rounded-[0.875rem] bg-chrome p-5 ring-1 ring-chrome-line">
                <span
                  className={cn(
                    "grid h-12 w-12 shrink-0 place-items-center rounded-2xl",
                    index % 2 ? "bg-accent-solid/20 text-accent-300" : "bg-chrome-accent/15 text-chrome-accent",
                  )}
                >
                  <Icon className="h-5 w-5" aria-hidden />
                </span>
                <p className="text-[0.96875rem] leading-snug text-chrome-fg">{text}</p>
              </div>
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
    <section id="faq" className="scroll-mt-20 bg-chrome-deep py-16 md:py-24">
      <div className="mx-auto grid max-w-[1240px] gap-12 px-4 sm:px-6 md:px-10 lg:grid-cols-[0.8fr_1.2fr] lg:gap-14">
        <Reveal>
          <Eyebrow onDark>FAQ</Eyebrow>
          <h2 className="mt-5 text-[2.125rem] font-semibold leading-[1.08] tracking-[-0.035em] text-white md:text-[3rem]">
            Questions, answered plainly.
          </h2>
          <p className="mt-5 max-w-[24rem] text-base leading-relaxed text-chrome-muted">
            What teams usually want to know before they start.
          </p>
        </Reveal>
        <Reveal delay={100}>
          <Faq items={FAQ} />
        </Reveal>
      </div>
    </section>
  );
}

/* ---------------------------------------------------------------- final call */

function FinalCta() {
  return (
    <section className="bg-chrome-deep px-3 pb-6 sm:px-5">
      <div className="relative isolate mx-auto flex min-h-[26rem] max-w-[1300px] flex-col items-center justify-center overflow-hidden rounded-[2rem] bg-chrome px-6 py-20 text-center text-white ring-1 ring-chrome-line">
        <div className="lp-grid absolute inset-0 -z-10" aria-hidden />
        <div
          className="absolute -bottom-40 -left-20 -z-10 h-[30rem] w-[30rem] rounded-full bg-accent-solid opacity-25 blur-[120px]"
          aria-hidden
        />
        <div
          className="absolute -right-24 -top-40 -z-10 h-[28rem] w-[28rem] rounded-full bg-chrome-accent opacity-20 blur-[120px]"
          aria-hidden
        />
        <Reveal>
          <MessagesSquare className="mx-auto h-10 w-10 text-chrome-accent" aria-hidden />
          <h2 className="mx-auto mt-6 max-w-[55rem] text-[2.5rem] font-semibold leading-[1.03] tracking-[-0.045em] text-white md:text-[4.25rem]">
            Bring every voice into one record.
          </h2>
          <p className="mx-auto mt-6 max-w-[35rem] text-[1.0625rem] leading-relaxed text-chrome-fg/80">
            Sign in with the account your project administrator gave you, and pick up where your team left off.
          </p>
          <div className="mt-10 flex flex-col items-center justify-center gap-3 sm:flex-row">
            <Pill href="/sign-in">Get started</Pill>
            <Pill href="/sign-in" tone="glass" arrow={false}>
              Log in
            </Pill>
          </div>
        </Reveal>
      </div>
    </section>
  );
}

/* ---------------------------------------------------------------- footer */

function Footer() {
  return (
    <footer className="bg-chrome-deep text-chrome-muted">
      <div className="mx-auto flex max-w-[1240px] flex-col gap-8 px-4 py-12 sm:px-6 md:flex-row md:items-center md:justify-between md:px-10">
        <div>
          <div className="flex items-center gap-2.5">
            <BrandMark className="h-9 w-9 text-base" />
            <span className="text-lg font-semibold tracking-tight text-white">SASA</span>
          </div>
          <p className="mt-3 text-[0.6875rem] uppercase tracking-[0.2em]">A more sustainable tomorrow</p>
        </div>
        <nav aria-label="Footer" className="flex flex-wrap gap-x-6 gap-y-3 text-sm">
          <a href="#platform" className="hover:text-white">Platform</a>
          <a href="#how" className="hover:text-white">How it works</a>
          <a href="#safeguards" className="hover:text-white">Safeguards</a>
          <a href="#faq" className="hover:text-white">FAQ</a>
          <Link href="/sign-in" className="font-medium text-white hover:text-accent-300">Log in</Link>
        </nav>
      </div>
      <div className="border-t border-chrome-line">
        <p className="mx-auto max-w-[1240px] px-4 py-6 text-xs sm:px-6 md:px-10">
          © {new Date().getFullYear()} SASA. Stakeholder engagement and E&amp;S compliance.
        </p>
      </div>
    </footer>
  );
}
