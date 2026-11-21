"use client";

import { Check } from "lucide-react";
import Link from "next/link";
import { useState } from "react";
import { formatMoney, planPrice, usePublicPlans } from "@/lib/api/billing";
import { cn } from "@/lib/utils";
import type { Currency, Plan } from "@/types/billing";

export function CurrencySwitch({
  value,
  onChange,
  className,
}: {
  value: Currency;
  onChange: (currency: Currency) => void;
  className?: string;
}) {
  return (
    <div
      role="radiogroup"
      aria-label="Currency"
      className={cn("inline-flex rounded-full bg-ink-100 p-1 text-sm font-semibold", className)}
    >
      {(["USD", "TZS"] as Currency[]).map((currency) => (
        <button
          key={currency}
          type="button"
          role="radio"
          aria-checked={value === currency}
          onClick={() => onChange(currency)}
          className={cn(
            "rounded-full px-5 py-2 transition-all duration-[var(--duration-base)] ease-[var(--ease-spring)]",
            value === currency ? "bg-surface text-ink-900 shadow-[var(--shadow-raised)]" : "text-ink-600 hover:text-ink-900",
          )}
        >
          {currency}
        </button>
      ))}
    </div>
  );
}

function PlanCard({ plan, currency }: { plan: Plan; currency: Currency }) {
  const price = planPrice(plan, currency);
  const quote = plan.is_custom_quote || price === null;
  const href = `/subscribe?plan=${encodeURIComponent(plan.code)}&currency=${currency}`;

  return (
    <article
      className={cn(
        "relative flex h-full flex-col rounded-[1.5rem] p-7 transition-[transform,box-shadow] duration-[var(--duration-slow)] ease-[var(--ease-spring)] hover:-translate-y-1 md:p-8",
        plan.is_featured
          ? "bg-chrome-deep text-white shadow-[var(--shadow-showcase)] ring-1 ring-chrome-line"
          : "bg-surface ring-1 ring-hairline hover:shadow-[var(--shadow-raised)]",
      )}
    >
      <div className="flex items-center justify-between gap-3">
        <h3 className={cn("text-xl font-semibold tracking-[-0.02em]", plan.is_featured ? "text-white" : "text-ink-900")}>
          {plan.name}
        </h3>
        {plan.is_featured ? (
          <span className="rounded-full bg-accent-solid px-3 py-1 text-xs font-semibold text-on-primary">Most chosen</span>
        ) : null}
      </div>
      {plan.tagline ? (
        <p className={cn("mt-2 text-[0.9375rem]", plan.is_featured ? "text-chrome-muted" : "text-ink-600")}>{plan.tagline}</p>
      ) : null}

      <div className="mt-7 min-h-[4.25rem]">
        {quote ? (
          <p className={cn("text-[2.25rem] font-bold tracking-[-0.035em]", plan.is_featured ? "text-white" : "text-ink-900")}>
            Custom
          </p>
        ) : (
          <p className="flex flex-wrap items-baseline gap-x-2">
            <span
              className={cn(
                "tabular text-[2.25rem] font-bold tracking-[-0.035em]",
                plan.is_featured ? "text-white" : "text-ink-900",
              )}
            >
              {formatMoney(price, currency)}
            </span>
            <span className={cn("text-sm", plan.is_featured ? "text-chrome-muted" : "text-ink-500")}>
              per {plan.billing_period === "yearly" ? "year" : "month"}
            </span>
          </p>
        )}
        <p className={cn("mt-1 text-sm", plan.is_featured ? "text-chrome-muted" : "text-ink-500")}>
          {plan.limits.projects === null ? "Unlimited projects" : `${plan.limits.projects} project${plan.limits.projects === 1 ? "" : "s"}`}
          {" · "}
          {plan.limits.users === null ? "unlimited users" : `${plan.limits.users} users`}
        </p>
      </div>

      <Link
        href={href}
        className={cn(
          "mt-7 inline-flex h-12 items-center justify-center rounded-full text-[0.9375rem] font-semibold transition-[background-color,transform] duration-[var(--duration-base)] ease-[var(--ease-spring)] active:scale-[0.97]",
          plan.is_featured
            ? "bg-accent-solid text-on-primary hover:bg-accent-solid-hover"
            : "bg-ink-100 text-ink-900 hover:bg-ink-200",
        )}
      >
        {quote ? "Request a quote" : `Choose ${plan.name}`}
      </Link>

      <ul className={cn("mt-8 space-y-3 border-t pt-7 text-[0.9375rem]", plan.is_featured ? "border-chrome-line" : "border-hairline")}>
        {plan.features.map((feature) => (
          <li key={feature} className="flex items-start gap-3">
            <Check
              className={cn("mt-0.5 h-4 w-4 shrink-0", plan.is_featured ? "text-chrome-accent" : "text-brand-600")}
              aria-hidden
            />
            <span className={plan.is_featured ? "text-chrome-fg" : "text-ink-700"}>{feature}</span>
          </li>
        ))}
      </ul>
    </article>
  );
}

/** The plans, live from the API, with the currency switch above them. */
export function PricingPlans({ initialCurrency = "USD" }: { initialCurrency?: Currency }) {
  const [currency, setCurrency] = useState<Currency>(initialCurrency);
  const { data, isLoading, isError } = usePublicPlans();
  const plans = data?.data ?? [];

  return (
    <div>
      <div className="flex justify-center">
        <CurrencySwitch value={currency} onChange={setCurrency} />
      </div>

      {isLoading ? (
        <div className="mt-12 grid gap-5 lg:grid-cols-3" aria-hidden>
          {[0, 1, 2].map((index) => (
            <div key={index} className="sasa-skeleton h-[34rem] rounded-[1.5rem]" />
          ))}
        </div>
      ) : isError ? (
        <p className="mt-12 text-center text-ink-600" role="alert">
          The plans could not be loaded just now. Please refresh the page in a moment.
        </p>
      ) : (
        <div
          className={cn(
            "mx-auto mt-12 grid gap-5",
            plans.length >= 3 ? "lg:grid-cols-3" : plans.length === 2 ? "max-w-[52rem] md:grid-cols-2" : "max-w-[26rem]",
          )}
        >
          {plans.map((plan) => (
            <PlanCard key={plan.id} plan={plan} currency={currency} />
          ))}
        </div>
      )}
    </div>
  );
}
