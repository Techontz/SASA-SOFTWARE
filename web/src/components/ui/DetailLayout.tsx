"use client";

import { ArrowLeft } from "lucide-react";
import Link from "next/link";
import { useState, type ReactNode } from "react";
import { cn } from "@/lib/utils";

/** Page heading used on every list and detail screen. */
export function PageHeader({
  eyebrow,
  title,
  description,
  actions,
  backHref,
  meta,
  className,
}: {
  eyebrow?: string;
  title: ReactNode;
  description?: ReactNode;
  actions?: ReactNode;
  backHref?: string;
  meta?: ReactNode;
  className?: string;
}) {
  return (
    <header className={cn("mb-6", className)}>
      {backHref ? (
        <Link
          href={backHref}
          className="mb-3 inline-flex items-center gap-1.5 text-sm font-medium text-ink-600 transition hover:text-brand-700"
        >
          <ArrowLeft className="h-4 w-4" aria-hidden />
          Back
        </Link>
      ) : null}
      <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between lg:gap-8">
        <div className="min-w-0">
          {eyebrow ? <p className="sasa-eyebrow mb-1.5">{eyebrow}</p> : null}
          <h1 className="text-[1.625rem] font-semibold leading-tight tracking-tight text-ink-900 sm:text-3xl">
            {title}
          </h1>
          {description ? (
            <div className="mt-2 max-w-3xl text-[0.9375rem] leading-relaxed text-ink-600">{description}</div>
          ) : null}
          {meta ? <div className="mt-3 flex flex-wrap items-center gap-2">{meta}</div> : null}
        </div>
        {actions ? <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div> : null}
      </div>
    </header>
  );
}

export interface TabDefinition {
  key: string;
  label: string;
  count?: number | null;
  content: ReactNode;
}

/** Tabs that scroll horizontally on a phone rather than wrapping into a mess. */
export function Tabs({
  tabs,
  initial,
  className,
}: {
  tabs: TabDefinition[];
  initial?: string;
  className?: string;
}) {
  const [active, setActive] = useState(initial ?? tabs[0]?.key);
  const current = tabs.find((tab) => tab.key === active) ?? tabs[0];

  return (
    <div className={className}>
      <div className="-mx-4 mb-5 overflow-x-auto border-b border-hairline px-4 sm:mx-0 sm:px-0">
        <div role="tablist" className="flex min-w-max gap-1">
          {tabs.map((tab) => {
            const isActive = tab.key === current?.key;

            return (
              <button
                key={tab.key}
                role="tab"
                type="button"
                aria-selected={isActive}
                onClick={() => setActive(tab.key)}
                className={cn(
                  "relative whitespace-nowrap px-3.5 py-2.5 text-sm font-medium transition-colors",
                  isActive ? "text-brand-800" : "text-ink-600 hover:text-ink-900",
                )}
              >
                {tab.label}
                {typeof tab.count === "number" ? (
                  <span
                    className={cn(
                      "ml-1.5 rounded-full px-1.5 py-0.5 text-xs tabular",
                      isActive ? "bg-brand-100 text-brand-800" : "bg-ink-100 text-ink-600",
                    )}
                  >
                    {tab.count}
                  </span>
                ) : null}
                {isActive ? (
                  <span className="absolute inset-x-2 -bottom-px h-0.5 rounded-full bg-brand-500" aria-hidden />
                ) : null}
              </button>
            );
          })}
        </div>
      </div>
      <div role="tabpanel">{current?.content}</div>
    </div>
  );
}

/** A label/value pair. Long text wraps; nothing is ever truncated silently. */
export function DetailField({
  label,
  children,
  className,
  wide,
}: {
  label: string;
  children: ReactNode;
  className?: string;
  wide?: boolean;
}) {
  return (
    <div className={cn(wide && "sm:col-span-2 lg:col-span-3", className)}>
      <dt className="sasa-eyebrow">{label}</dt>
      <dd className="mt-1 text-[0.9375rem] leading-relaxed text-ink-800">{children ?? "—"}</dd>
    </div>
  );
}

export function DetailGrid({ children, columns = 3 }: { children: ReactNode; columns?: 2 | 3 }) {
  return (
    <dl className={cn("grid gap-x-6 gap-y-5", columns === 2 ? "sm:grid-cols-2" : "sm:grid-cols-2 lg:grid-cols-3")}>
      {children}
    </dl>
  );
}
