"use client";

import { ArrowLeft, ArrowRight, Check, CircleCheck, CreditCard, Lock, RotateCcw, Smartphone } from "lucide-react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from "react";
import { Button } from "@/components/ui/Button";
import { Field, FieldRow, Input, Textarea } from "@/components/ui/Form";
import { Scanner } from "@/components/ui/States";
import { ApiRequestError } from "@/lib/api/client";
import {
  createSubscription,
  fetchPayment,
  fetchSubscription,
  formatMoney,
  planPrice,
  startPayment,
  usePublicPlans,
} from "@/lib/api/billing";
import { cn } from "@/lib/utils";
import type { Currency, Payment, PaymentMethod, Plan, SubscriptionRequest } from "@/types/billing";
import { CurrencySwitch } from "./PricingPlans";

type Step = "details" | "pay" | "waiting" | "done" | "quote";

type Details = {
  organisation_name: string;
  contact_name: string;
  email: string;
  phone: string;
  country: string;
  message: string;
};

const EMPTY: Details = { organisation_name: "", contact_name: "", email: "", phone: "", country: "Tanzania", message: "" };

/** 07XXXXXXXX, 06XXXXXXXX, +255 7XX XXX XXX and 2557XXXXXXXX are all one number. */
export function normaliseTzPhone(raw: string): string | null {
  const digits = raw.replace(/[^\d]/g, "");
  const local = digits.startsWith("255") ? digits.slice(3) : digits.startsWith("0") ? digits.slice(1) : digits;
  return /^[67]\d{8}$/.test(local) ? `255${local}` : null;
}

/** 255712345678 reads better as 0712 345 678. Anything else is left alone. */
export function friendlyTzPhone(raw: string): string {
  const normalised = normaliseTzPhone(raw);
  if (!normalised) return raw;
  const local = `0${normalised.slice(3)}`;
  return `${local.slice(0, 4)} ${local.slice(4, 7)} ${local.slice(7)}`;
}

function messageOf(error: unknown, fallback: string): string {
  if (error instanceof ApiRequestError) {
    if (error.isOffline) return "You are offline. Connect to the internet to subscribe.";
    return error.message || fallback;
  }
  return fallback;
}

function fieldErrors(error: unknown): Record<string, string> {
  if (error instanceof ApiRequestError && error.validationErrors) {
    return Object.fromEntries(Object.entries(error.validationErrors).map(([key, value]) => [key, value[0] ?? ""]));
  }
  return {};
}

const POLL_MS = 3000;
const WAIT_LIMIT_MS = 3 * 60_000;

export function SubscribeFlow() {
  const router = useRouter();
  const params = useSearchParams();
  const { data: plansData, isLoading: plansLoading } = usePublicPlans();

  const [currency, setCurrency] = useState<Currency>(params.get("currency") === "TZS" ? "TZS" : "USD");
  const [planCode, setPlanCode] = useState<string | null>(params.get("plan"));
  const [details, setDetails] = useState<Details>(EMPTY);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [request, setRequest] = useState<SubscriptionRequest | null>(null);
  const [step, setStep] = useState<Step>("details");
  const [method, setMethod] = useState<PaymentMethod>("mobile_money");
  const [payPhone, setPayPhone] = useState("");
  const [payment, setPayment] = useState<Payment | null>(null);
  const [waitedTooLong, setWaitedTooLong] = useState(false);

  const plans = plansData?.data ?? [];
  const plan: Plan | undefined = request?.plan ?? plans.find((item) => item.code === planCode) ?? plans[0];
  const price = plan ? planPrice(plan, currency) : null;
  const quote = Boolean(plan && (plan.is_custom_quote || price === null));

  /* Coming back to the page (a refresh, or a failed card payment) resumes the
     same request instead of starting a second one. */
  const resumeId = params.get("request");
  const resumed = useRef(false);
  useEffect(() => {
    if (!resumeId || resumed.current || request?.public_id === resumeId) return;
    resumed.current = true;
    fetchSubscription(resumeId)
      .then(({ data }) => {
        setRequest(data);
        setCurrency(data.currency);
        setPlanCode(data.plan.code);
        setPayPhone(friendlyTzPhone(data.phone));
        setStep(data.status === "paid" || data.status === "active" ? "done" : data.status === "quote_requested" ? "quote" : "pay");
      })
      .catch(() => setFormError("That subscription could not be found. Please start again."));
  }, [request?.public_id, resumeId]);

  const update = (key: keyof Details) => (value: string) => setDetails((current) => ({ ...current, [key]: value }));

  const submitDetails = async (event: FormEvent) => {
    event.preventDefault();
    if (!plan) return;

    const next: Record<string, string> = {};
    if (!details.organisation_name.trim()) next.organisation_name = "Enter the organisation's name.";
    if (!details.contact_name.trim()) next.contact_name = "Enter your name.";
    if (!/^\S+@\S+\.\S+$/.test(details.email.trim())) next.email = "Enter a valid e-mail address.";
    if (!normaliseTzPhone(details.phone)) next.phone = "Enter a Tanzanian mobile number, for example 0712 345 678.";
    setErrors(next);
    if (Object.keys(next).length) return;

    setBusy(true);
    setFormError(null);
    try {
      const { data } = await createSubscription({
        plan_id: plan.id,
        currency,
        organisation_name: details.organisation_name.trim(),
        contact_name: details.contact_name.trim(),
        email: details.email.trim(),
        phone: details.phone.trim(),
        country: details.country.trim() || undefined,
        message: details.message.trim() || undefined,
      });
      setRequest(data);
      setPayPhone(details.phone.trim());
      router.replace(`/subscribe?request=${data.public_id}`, { scroll: false });
      setStep(data.status === "quote_requested" ? "quote" : "pay");
      window.scrollTo({ top: 0, behavior: "smooth" });
    } catch (error) {
      setErrors(fieldErrors(error));
      setFormError(messageOf(error, "We could not start your subscription. Please try again."));
    } finally {
      setBusy(false);
    }
  };

  const pay = async () => {
    if (!request) return;
    if (method === "mobile_money" && !normaliseTzPhone(payPhone)) {
      setErrors({ pay_phone: "Enter a Tanzanian mobile number, for example 0712 345 678." });
      return;
    }
    setErrors({});
    setBusy(true);
    setFormError(null);
    try {
      const { data } = await startPayment(request.public_id, {
        method,
        phone: method === "mobile_money" ? payPhone.trim() : undefined,
      });
      setPayment(data);
      if (method === "card") {
        if (data.checkout_url) {
          window.location.assign(data.checkout_url);
          return;
        }
        setFormError("The card payment page could not be opened. Please try again.");
      } else {
        setWaitedTooLong(false);
        setStep("waiting");
      }
    } catch (error) {
      const fields = fieldErrors(error);
      setErrors(fields.phone ? { pay_phone: fields.phone } : fields);
      setFormError(messageOf(error, "The payment could not be started. Please try again."));
    } finally {
      setBusy(false);
    }
  };

  /* While the phone prompt is out, ask the server every few seconds. */
  const poll = useCallback(async (id: string) => {
    try {
      const { data } = await fetchPayment(id);
      setPayment(data);
      if (data.status === "succeeded") setStep("done");
      if (data.status === "failed" || data.status === "cancelled") {
        setStep("pay");
        setFormError(data.failure_reason || "The payment was not completed. You can try again.");
      }
    } catch {
      /* A dropped poll is not a failed payment; the next one will tell. */
    }
  }, []);

  useEffect(() => {
    if (step !== "waiting" || !payment) return;
    const started = Date.now();
    const timer = window.setInterval(() => {
      if (Date.now() - started > WAIT_LIMIT_MS) setWaitedTooLong(true);
      void poll(payment.public_id);
    }, POLL_MS);
    return () => window.clearInterval(timer);
  }, [payment, poll, step]);

  const charge = request?.charge_options?.[method] ?? null;
  const chargeLabel = charge ? formatMoney(charge.amount, charge.currency) : formatMoney(request?.amount, request?.currency ?? currency);
  const methodUnavailable = Boolean(request?.charge_options && charge === null);

  const steps = useMemo(
    () => [
      { key: "details", label: "Your details" },
      { key: "pay", label: "Payment" },
      { key: "done", label: "Confirmed" },
    ],
    [],
  );
  const stepIndex = step === "details" ? 0 : step === "pay" || step === "waiting" ? 1 : 2;

  if (plansLoading && !plan) {
    return <div className="sasa-skeleton h-[36rem] rounded-[1.5rem]" aria-hidden />;
  }

  if (!plan) {
    return (
      <div className="mx-auto max-w-md text-center">
        <h1 className="text-3xl font-bold tracking-[-0.03em] text-ink-900">No plans are available</h1>
        <p className="mt-3 text-ink-600">Please contact us, or come back shortly.</p>
      </div>
    );
  }

  return (
    <div>
      <Link href="/pricing" className="inline-flex items-center gap-1.5 text-sm font-medium text-ink-600 hover:text-ink-900">
        <ArrowLeft className="h-4 w-4" aria-hidden /> All plans
      </Link>

      <ol className="mt-6 flex items-center gap-3 text-sm" aria-label="Progress">
        {steps.map((item, index) => (
          <li key={item.key} className="flex items-center gap-3">
            <span
              className={cn(
                "flex items-center gap-2 font-medium",
                index <= stepIndex ? "text-ink-900" : "text-ink-500",
              )}
              aria-current={index === stepIndex ? "step" : undefined}
            >
              <span
                className={cn(
                  "grid h-6 w-6 place-items-center rounded-full text-xs font-semibold",
                  index < stepIndex
                    ? "bg-primary text-on-primary"
                    : index === stepIndex
                      ? "bg-ink-900 text-surface"
                      : "bg-ink-100 text-ink-600",
                )}
              >
                {index < stepIndex ? <Check className="h-3.5 w-3.5" aria-hidden /> : index + 1}
              </span>
              <span className={cn(index === stepIndex ? "" : "hidden sm:inline")}>{item.label}</span>
            </span>
            {index < steps.length - 1 ? <span className="h-px w-6 bg-hairline-strong sm:w-10" aria-hidden /> : null}
          </li>
        ))}
      </ol>

      <div className="mt-8 grid gap-8 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start lg:gap-12">
        <div className="min-w-0">
          {step === "details" ? (
            <form onSubmit={submitDetails} noValidate className="sasa-card p-6 sm:p-8">
              <h1 className="text-[1.75rem] font-bold tracking-[-0.03em] text-ink-900 sm:text-[2rem]">Tell us about your organisation</h1>
              <p className="mt-2 text-ink-600">We use these details to set up your account and send your receipt.</p>

              <div className="mt-8 space-y-5">
                <Field label="Organisation" required error={errors.organisation_name} htmlFor="organisation_name">
                  <Input
                    id="organisation_name"
                    autoComplete="organization"
                    value={details.organisation_name}
                    onChange={(event) => update("organisation_name")(event.target.value)}
                    invalid={Boolean(errors.organisation_name)}
                  />
                </Field>
                <FieldRow>
                  <Field label="Your name" required error={errors.contact_name} htmlFor="contact_name">
                    <Input
                      id="contact_name"
                      autoComplete="name"
                      value={details.contact_name}
                      onChange={(event) => update("contact_name")(event.target.value)}
                      invalid={Boolean(errors.contact_name)}
                    />
                  </Field>
                  <Field label="Work e-mail" required error={errors.email} htmlFor="email">
                    <Input
                      id="email"
                      type="email"
                      autoComplete="email"
                      autoCapitalize="none"
                      value={details.email}
                      onChange={(event) => update("email")(event.target.value)}
                      invalid={Boolean(errors.email)}
                    />
                  </Field>
                </FieldRow>
                <FieldRow>
                  <Field label="Mobile number" required error={errors.phone} hint="We will also use this for mobile money." htmlFor="phone">
                    <Input
                      id="phone"
                      type="tel"
                      inputMode="tel"
                      autoComplete="tel"
                      placeholder="0712 345 678"
                      value={details.phone}
                      onChange={(event) => update("phone")(event.target.value)}
                      invalid={Boolean(errors.phone)}
                    />
                  </Field>
                  <Field label="Country" optional htmlFor="country">
                    <Input
                      id="country"
                      autoComplete="country-name"
                      value={details.country}
                      onChange={(event) => update("country")(event.target.value)}
                    />
                  </Field>
                </FieldRow>
                <Field label="Anything we should know?" optional htmlFor="message">
                  <Textarea
                    id="message"
                    rows={3}
                    value={details.message}
                    onChange={(event) => update("message")(event.target.value)}
                    placeholder="The project, its location, how many people will use SASA"
                  />
                </Field>
              </div>

              {formError ? (
                <p className="mt-6 rounded-xl bg-danger-50 p-3.5 text-sm text-danger-700" role="alert">
                  {formError}
                </p>
              ) : null}

              <div className="mt-8 flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p className="flex items-center gap-2 text-sm text-ink-500">
                  <Lock className="h-4 w-4" aria-hidden /> Your details are only used for your subscription.
                </p>
                <Button type="submit" variant="accent" size="lg" loading={busy} iconRight={<ArrowRight className="h-4 w-4" />}>
                  {quote ? "Request a quote" : "Continue to payment"}
                </Button>
              </div>
            </form>
          ) : null}

          {step === "pay" && request ? (
            <div className="sasa-card p-6 sm:p-8">
              <h1 className="text-[1.75rem] font-bold tracking-[-0.03em] text-ink-900 sm:text-[2rem]">How would you like to pay?</h1>
              <p className="mt-2 text-ink-600">
                {request.plan.name}, reference <span className="font-medium text-ink-800">{request.reference}</span>.
              </p>
              {charge && charge.currency !== request.currency ? (
                <p className="mt-4 rounded-xl bg-info-50 p-3.5 text-sm text-info-800">
                  Payments are made in Tanzanian shillings. You will be charged{" "}
                  <span className="font-semibold">{chargeLabel}</span>, the shilling price of this plan.
                </p>
              ) : null}

              <div className="mt-8 grid gap-3 sm:grid-cols-2" role="radiogroup" aria-label="Payment method">
                {(
                  [
                    { key: "mobile_money", icon: Smartphone, title: "Mobile money", detail: "M-Pesa, Airtel Money, Mixx by Yas or Halotel." },
                    { key: "card", icon: CreditCard, title: "Card", detail: "Debit or credit card, on a secure page." },
                  ] as const
                ).map((option) => {
                  const Icon = option.icon;
                  const selected = method === option.key;
                  return (
                    <button
                      key={option.key}
                      type="button"
                      role="radio"
                      aria-checked={selected}
                      onClick={() => setMethod(option.key)}
                      className={cn(
                        "flex items-start gap-4 rounded-2xl p-5 text-left ring-1 transition-[box-shadow,background-color,transform] duration-[var(--duration-base)] ease-[var(--ease-spring)] active:scale-[0.99]",
                        selected ? "bg-brand-50 ring-2 ring-brand-500" : "bg-surface ring-hairline-strong hover:bg-surface-sunken",
                      )}
                    >
                      <span
                        className={cn(
                          "grid h-11 w-11 shrink-0 place-items-center rounded-xl",
                          selected ? "bg-primary text-on-primary" : "bg-ink-100 text-ink-700",
                        )}
                      >
                        <Icon className="h-5 w-5" aria-hidden />
                      </span>
                      <span>
                        <span className="block font-semibold text-ink-900">{option.title}</span>
                        <span className="mt-0.5 block text-sm text-ink-600">{option.detail}</span>
                      </span>
                    </button>
                  );
                })}
              </div>

              {method === "mobile_money" ? (
                <div className="mt-6">
                  <Field
                    label="Mobile money number"
                    error={errors.pay_phone}
                    hint="No need to choose a network. A prompt will appear on this phone to approve the payment."
                    htmlFor="pay_phone"
                  >
                    <Input
                      id="pay_phone"
                      type="tel"
                      inputMode="tel"
                      autoComplete="tel"
                      placeholder="0712 345 678"
                      value={payPhone}
                      onChange={(event) => setPayPhone(event.target.value)}
                      invalid={Boolean(errors.pay_phone)}
                      className="text-lg tracking-wide"
                    />
                  </Field>
                </div>
              ) : (
                <p className="mt-6 flex items-start gap-2 rounded-xl bg-surface-sunken p-4 text-sm text-ink-600">
                  <Lock className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
                  You will enter your card on our payment partner&apos;s secure page. SASA never sees or stores your card
                  number.
                </p>
              )}

              {formError ? (
                <p className="mt-6 rounded-xl bg-danger-50 p-3.5 text-sm text-danger-700" role="alert">
                  {formError}
                </p>
              ) : null}

              <Button
                type="button"
                variant="accent"
                size="lg"
                fullWidth
                className="mt-8"
                loading={busy}
                disabled={methodUnavailable}
                onClick={pay}
              >
                {method === "mobile_money" ? `Pay ${chargeLabel}` : `Continue to card payment`}
              </Button>
            </div>
          ) : null}

          {step === "waiting" && payment ? (
            <div className="sasa-card flex flex-col items-center p-8 text-center sm:p-12" aria-live="polite">
              <Scanner label="Waiting for your approval" size={56} />
              <h1 className="mt-8 text-[1.75rem] font-bold tracking-[-0.03em] text-ink-900">Check your phone</h1>
              <p className="mt-3 max-w-[26rem] text-ink-600">
                A prompt has been sent to <span className="font-medium text-ink-900">{friendlyTzPhone(payment.phone ?? payPhone)}</span>.
                Enter your mobile money PIN to pay{" "}
                <span className="font-medium text-ink-900">{formatMoney(payment.amount, payment.currency)}</span>.
              </p>
              <p className="mt-2 text-sm text-ink-500">This page updates by itself once the payment goes through.</p>
              {waitedTooLong ? (
                <div className="mt-8 flex flex-col gap-3 sm:flex-row">
                  <Button variant="secondary" icon={<RotateCcw className="h-4 w-4" />} onClick={() => setStep("pay")}>
                    Try again
                  </Button>
                </div>
              ) : (
                <button type="button" onClick={() => setStep("pay")} className="mt-8 text-sm font-medium text-brand-700 hover:underline">
                  Use a different number or a card
                </button>
              )}
            </div>
          ) : null}

          {step === "done" && request ? (
            <div className="sasa-card flex flex-col items-center p-8 text-center sm:p-12">
              <span className="grid h-16 w-16 place-items-center rounded-full bg-success-50 text-success-700">
                <CircleCheck className="h-8 w-8" aria-hidden />
              </span>
              <h1 className="mt-6 text-[1.75rem] font-bold tracking-[-0.03em] text-ink-900">Payment received</h1>
              <p className="mt-3 max-w-[28rem] text-ink-600">
                Thank you, {request.contact_name}. Your {request.plan.name} subscription for {request.organisation_name} is
                confirmed. Our team will set up your organisation and e-mail your sign-in to {request.email}.
              </p>
              <p className="mt-6 rounded-full bg-ink-100 px-4 py-2 text-sm font-medium text-ink-800">Reference {request.reference}</p>
              <Link href="/" className="mt-8 text-sm font-semibold text-brand-700 hover:underline">
                Back to the home page
              </Link>
            </div>
          ) : null}

          {step === "quote" && request ? (
            <div className="sasa-card flex flex-col items-center p-8 text-center sm:p-12">
              <span className="grid h-16 w-16 place-items-center rounded-full bg-brand-50 text-brand-700">
                <CircleCheck className="h-8 w-8" aria-hidden />
              </span>
              <h1 className="mt-6 text-[1.75rem] font-bold tracking-[-0.03em] text-ink-900">Quote requested</h1>
              <p className="mt-3 max-w-[28rem] text-ink-600">
                Thank you, {request.contact_name}. We will be in touch at {request.email} within one working day with a
                proposal for {request.organisation_name}.
              </p>
              <p className="mt-6 rounded-full bg-ink-100 px-4 py-2 text-sm font-medium text-ink-800">Reference {request.reference}</p>
            </div>
          ) : null}
        </div>

        {/* ----------------------------- the order summary ----------------- */}
        <aside className="sasa-card p-6 lg:sticky lg:top-28">
          <p className="text-sm text-ink-500">Your plan</p>
          <h2 className="mt-1 text-2xl font-bold tracking-[-0.03em] text-ink-900">{plan.name}</h2>
          {plan.tagline ? <p className="mt-1 text-sm text-ink-600">{plan.tagline}</p> : null}

          {!request && plans.length > 1 ? (
            <label className="mt-4 block">
              <span className="sr-only">Change plan</span>
              <select
                className="sasa-field"
                value={plan.code}
                onChange={(event) => setPlanCode(event.target.value)}
              >
                {plans.map((item) => (
                  <option key={item.code} value={item.code}>
                    {item.name}
                  </option>
                ))}
              </select>
            </label>
          ) : null}

          <div className="mt-6 flex items-center justify-between gap-3">
            <span className="text-sm text-ink-600">Currency</span>
            {request ? (
              <span className="text-sm font-semibold text-ink-900">{request.currency}</span>
            ) : (
              <CurrencySwitch value={currency} onChange={setCurrency} className="text-xs [&_button]:px-3.5 [&_button]:py-1.5" />
            )}
          </div>

          <ul className="mt-6 space-y-2.5 border-t border-hairline pt-6 text-sm">
            {plan.features.slice(0, 5).map((feature) => (
              <li key={feature} className="flex items-start gap-2.5 text-ink-700">
                <Check className="mt-0.5 h-4 w-4 shrink-0 text-brand-600" aria-hidden />
                {feature}
              </li>
            ))}
          </ul>

          <div className="mt-6 flex items-baseline justify-between gap-3 border-t border-hairline pt-6">
            <span className="font-semibold text-ink-900">Total</span>
            <span className="tabular text-right">
              <span className="block text-2xl font-bold tracking-[-0.03em] text-ink-900">
                {quote ? "Custom quote" : formatMoney(request?.amount ?? price, request?.currency ?? currency)}
              </span>
              {!quote ? (
                <span className="text-xs text-ink-500">per {plan.billing_period === "yearly" ? "year" : "month"}</span>
              ) : null}
            </span>
          </div>
        </aside>
      </div>
    </div>
  );
}
