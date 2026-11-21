"use client";

import Image from "next/image";
import { useCallback, useEffect, useRef, useState, type KeyboardEvent } from "react";
import { cn } from "@/lib/utils";
import { BrowserFrame } from "./Frames";

type Stop = { key: string; label: string; title: string; body: string; path: string };

const STOPS: Stop[] = [
  {
    key: "grievances",
    label: "Grievances",
    title: "Every channel, one case, one clock.",
    body: "Walk-in, phone, WhatsApp, voice or web: each complaint becomes one case with its own reference, severity and service-level timer. Confidential cases say so on every row.",
    path: "grievances",
  },
  {
    key: "stakeholders",
    label: "Stakeholders",
    title: "Know who matters, and why.",
    body: "Individuals, households, groups and authorities, each with a transparent priority score, vulnerability and consent flags, and a date for review.",
    path: "stakeholders",
  },
  {
    key: "engagements",
    label: "Engagements",
    title: "Planned against actual, on every meeting.",
    body: "Village assemblies, door-to-door visits and negotiations logged as they happen, with attendance, the concerns raised and the commitments made.",
    path: "engagements",
  },
  {
    key: "commitments",
    label: "Commitments",
    title: "A promise is a deliverable.",
    body: "What was promised, to whom, by when and by whom. Overdue items surface on their own, and nothing closes without verified evidence.",
    path: "commitments",
  },
  {
    key: "paps",
    label: "PAP register",
    title: "From valuation to the last payment.",
    body: "Project-affected persons with their households, compensation budget, amounts paid and outstanding, relocation and livelihood restoration.",
    path: "paps",
  },
  {
    key: "eshs",
    label: "ESHS assurance",
    title: "Contractors held to the evidence.",
    body: "Environmental, safety, health and social anomalies raised by the supervision team, closed by the contractor, and verified before they count.",
    path: "eshs",
  },
  {
    key: "incidents",
    label: "Incidents",
    title: "What happened, and how bad it could have been.",
    body: "Injuries, spills, losses and security events notified from the field, with actual and potential consequence side by side.",
    path: "incidents",
  },
  {
    key: "analytics",
    label: "Analytics",
    title: "Numbers you can defend.",
    body: "Live dashboards where every metric carries its definition and drills through to the records behind it, ready for lenders and management.",
    path: "analytics",
  },
];

const DWELL = 7000;

export function ProductTour() {
  const [active, setActive] = useState(0);
  const [auto, setAuto] = useState(true);
  const [hovering, setHovering] = useState(false);
  const [inView, setInView] = useState(false);
  const rootRef = useRef<HTMLDivElement | null>(null);
  const tabsRef = useRef<Array<HTMLButtonElement | null>>([]);

  useEffect(() => {
    const element = rootRef.current;
    if (!element) return;
    const observer = new IntersectionObserver(([entry]) => setInView(Boolean(entry?.isIntersecting)), {
      threshold: 0.35,
    });
    observer.observe(element);
    return () => observer.disconnect();
  }, []);

  const running = auto && inView && !hovering;

  useEffect(() => {
    // Nobody who has asked for less motion is moved along without asking.
    if (!running || window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;
    const timer = window.setTimeout(() => setActive((index) => (index + 1) % STOPS.length), DWELL);
    return () => window.clearTimeout(timer);
  }, [active, running]);

  const choose = useCallback((index: number, focus = false) => {
    setAuto(false);
    setActive(index);
    if (focus) tabsRef.current[index]?.focus();
  }, []);

  const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
    const last = STOPS.length - 1;
    const next =
      event.key === "ArrowRight" || event.key === "ArrowDown"
        ? active === last ? 0 : active + 1
        : event.key === "ArrowLeft" || event.key === "ArrowUp"
          ? active === 0 ? last : active - 1
          : event.key === "Home"
            ? 0
            : event.key === "End"
              ? last
              : null;
    if (next === null) return;
    event.preventDefault();
    choose(next, true);
  };

  const stop = STOPS[active] ?? STOPS[0]!;

  return (
    <div ref={rootRef} onMouseEnter={() => setHovering(true)} onMouseLeave={() => setHovering(false)}>
      <div
        role="tablist"
        aria-label="SASA registers"
        onKeyDown={onKeyDown}
        className="-mx-4 flex gap-1 overflow-x-auto border-b border-hairline px-4 [scrollbar-width:none] sm:-mx-6 sm:px-6 md:mx-0 md:px-0"
      >
        {STOPS.map((item, index) => {
          const selected = index === active;
          return (
            <button
              key={item.key}
              ref={(element) => {
                tabsRef.current[index] = element;
              }}
              type="button"
              role="tab"
              id={`tour-tab-${item.key}`}
              aria-selected={selected}
              aria-controls="tour-panel"
              tabIndex={selected ? 0 : -1}
              onClick={() => choose(index)}
              className={cn(
                "relative shrink-0 whitespace-nowrap px-3.5 pb-3.5 pt-2 text-[0.9375rem] font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-300",
                selected ? "text-ink-900" : "text-ink-500 hover:text-ink-900",
              )}
            >
              {item.label}
              <span
                className={cn("absolute inset-x-3.5 -bottom-px h-0.5", selected ? "bg-accent-solid" : "bg-transparent")}
                aria-hidden
              />
              {selected && running ? (
                <span
                  key={`progress-${active}`}
                  className="lp-progress absolute inset-x-3.5 -bottom-px h-0.5 origin-left bg-ink-900/30"
                  style={{ animationDuration: `${DWELL}ms` }}
                  aria-hidden
                />
              ) : null}
            </button>
          );
        })}
      </div>

      <div id="tour-panel" role="tabpanel" aria-labelledby={`tour-tab-${stop.key}`} className="mt-10">
        <div key={stop.key} className="lp-swap grid gap-4 md:grid-cols-[1fr_1fr] md:gap-12">
          <h3 className="text-[1.625rem] font-semibold leading-tight tracking-[-0.03em] text-ink-900 md:text-[2rem]">
            {stop.title}
          </h3>
          <p className="text-[1.0625rem] leading-relaxed text-ink-600 md:pt-1">{stop.body}</p>
        </div>

        <BrowserFrame tone="light" url={`sasacompliance.com/${stop.path}`} className="mt-10">
          <div className="relative aspect-[1440/900] w-full bg-surface">
            {STOPS.map((item, index) => (
              <Image
                key={item.key}
                src={`/landing/screens/${item.path}.webp`}
                alt={index === active ? `The ${item.label} screen in SASA` : ""}
                fill
                sizes="(min-width: 1280px) 1160px, 100vw"
                className={cn(
                  "object-cover object-top transition-opacity duration-700",
                  index === active ? "opacity-100" : "opacity-0",
                )}
                aria-hidden={index !== active}
              />
            ))}
          </div>
        </BrowserFrame>
      </div>
    </div>
  );
}
