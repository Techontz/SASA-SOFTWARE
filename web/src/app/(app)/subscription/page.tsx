"use client";

import { CalendarClock, Check, ChevronDown, CreditCard, Lock, Minus, Smartphone } from "lucide-react";
import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useEffect, useRef, useState } from "react";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { PageHeader } from "@/components/ui/DetailLayout";
import { Drawer } from "@/components/ui/Drawer";
import { Checkbox, Field, Input } from "@/components/ui/Form";
import { StatusBadge } from "@/components/ui/StatusBadge";
import { EmptyState, LoadingState, PermissionDenied, Scanner } from "@/components/ui/States";
import { ApiRequestError } from "@/lib/api/client";
import {
  AGREEMENT_STATUS,
  INVOICE_STATUS,
  INVOICE_TONE,
  payInvoice,
  useMyAgreement,
  useMyInvoices,
  useOrganisationRoles,
  useSetAutoPay,
  type AgreementForCustomer,
  type Invoice,
} from "@/lib/api/agreements";
import { fetchPayment, formatMoney } from "@/lib/api/billing";
import { PLATFORM_MODULES } from "@/lib/content/modules";
import { cn } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";
import { useToast } from "@/providers/ToastProvider";
import { useQueryClient } from "@tanstack/react-query";
import type { Currency, Payment, PaymentMethod } from "@/types/billing";

function money(amount: number | null | undefined, currency: string) {
  return formatMoney(amount ?? null, (currency === "USD" ? "USD" : "TZS") as Currency);
}

function longDate(iso: string) {
  return new Date(iso).toLocaleDateString("en-GB", { day: "numeric", month: "long", year: "numeric" });
}

function shortDate(iso: string) {
  return new Date(iso).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" });
}

/** 255712345678 reads as 0712 345 678. */
function friendlyPhone(raw: string | null) {
  if (!raw) return "";
  const digits = raw.replace(/[^\d]/g, "");
  const local = digits.startsWith("255") ? `0${digits.slice(3)}` : digits;
  return local.length === 10 ? `${local.slice(0, 4)} ${local.slice(4, 7)} ${local.slice(7)}` : raw;
}

export default function SubscriptionPage() {
  return (
    <Suspense fallback={<LoadingState label="Loading your plan" />}>
      <YourPlan />
    </Suspense>
  );
}

function YourPlan() {
  const { can } = useSession();
  const allowed = can("organisation.billing");
  const agreement = useMyAgreement(allowed);
  const invoices = useMyInvoices(allowed);
  const roles = useOrganisationRoles(allowed);
  const [paying, setPaying] = useState<Invoice | null>(null);
  useCardReturn();

  if (!allowed) return <PermissionDenied what="your organisation's plan" />;
  if (agreement.isLoading) return <LoadingState label="Loading your plan" />;

  const plan = agreement.data?.data ?? null;
  const list = invoices.data?.data ?? [];
  const due = list.find((invoice) => invoice.status === "open" || invoice.status === "overdue") ?? null;

  return (
    <div>
      <PageHeader
        eyebrow="Administration"
        title="Your plan"
        description="The plan agreed with SASA for your organisation, what it includes, and your monthly invoices."
      />

      {!plan ? (
        <Card>
          <EmptyState
            title="Your plan is being prepared"
            description="Once your quote is agreed with the SASA team, your monthly amount, the modules included and your invoices appear here."
          />
        </Card>
      ) : (
        <div className="space-y-6">
          <PlanSummary plan={plan} due={due} onPay={setPaying} />

          <div className="grid gap-6 lg:grid-cols-[1.25fr_1fr]">
            <Modules plan={plan} />
            <div className="space-y-6">
              <Usage plan={plan} />
              <AutoPay plan={plan} />
            </div>
          </div>

          <Invoices invoices={list} loading={invoices.isLoading} onPay={setPaying} />

          <Roles roles={roles.data?.data ?? []} loading={roles.isLoading} />

          {plan.terms_note ? (
            <Card className="p-6">
              <p className="text-sm font-semibold text-ink-900">Agreed terms</p>
              <p className="mt-2 whitespace-pre-line text-[0.9375rem] leading-relaxed text-ink-700">{plan.terms_note}</p>
            </Card>
          ) : null}
        </div>
      )}

      {paying ? <PayDrawer invoice={paying} defaultPhone={plan?.auto_pay_phone ?? null} onClose={() => setPaying(null)} /> : null}
    </div>
  );
}

/* ------------------------------------------------------------ summary */

function PlanSummary({ plan, due, onPay }: { plan: AgreementForCustomer; due: Invoice | null; onPay: (invoice: Invoice) => void }) {
  return (
    <Card className="overflow-hidden">
      <div className="grid gap-8 p-6 sm:p-8 lg:grid-cols-[1.3fr_1fr] lg:items-center">
        <div>
          <div className="flex flex-wrap items-center gap-3">
            <h2 className="text-xl font-semibold tracking-[-0.02em] text-ink-900">{plan.name}</h2>
            <StatusBadge
              status={plan.status === "active" ? "active" : plan.status === "past_due" ? "overdue" : plan.status}
              label={AGREEMENT_STATUS[plan.status]}
            />
          </div>
          <p className="mt-6 flex flex-wrap items-baseline gap-x-2">
            <span className="tabular text-[2.75rem] font-bold leading-none tracking-[-0.04em] text-ink-900">
              {money(plan.monthly_amount, plan.currency)}
            </span>
            <span className="text-ink-500">per month</span>
          </p>
          <p className="mt-3 text-sm text-ink-600">
            Billed on day {plan.billing_day} of each month, since {longDate(plan.starts_on)}.
          </p>
        </div>

        <div className="rounded-2xl bg-surface-sunken p-5 ring-1 ring-hairline">
          {due ? (
            <>
              <p className="flex items-center gap-2 text-sm font-medium text-ink-600">
                <CalendarClock className="h-4 w-4" aria-hidden />
                {due.status === "overdue" ? "Payment overdue" : "Payment due"}
              </p>
              <p className="tabular mt-2 text-2xl font-semibold tracking-[-0.02em] text-ink-900">{money(due.amount, due.currency)}</p>
              <p className={cn("mt-1 text-sm", due.status === "overdue" ? "text-danger-600" : "text-ink-600")}>
                {due.number}, due {longDate(due.due_on)}
              </p>
              <Button variant="accent" fullWidth className="mt-5" onClick={() => onPay(due)}>
                Pay {money(due.amount, due.currency)}
              </Button>
            </>
          ) : (
            <>
              <p className="flex items-center gap-2 text-sm font-medium text-ink-600">
                <CalendarClock className="h-4 w-4" aria-hidden />
                Next payment
              </p>
              <p className="mt-2 text-2xl font-semibold tracking-[-0.02em] text-ink-900">{longDate(plan.next_due_on)}</p>
              <p className="mt-1 text-sm text-ink-600">
                {plan.auto_pay
                  ? `The payment prompt will be sent to ${friendlyPhone(plan.auto_pay_phone)} on that day.`
                  : "You are all paid up. The invoice appears here on that day."}
              </p>
            </>
          )}
        </div>
      </div>
    </Card>
  );
}

/* ------------------------------------------------------------ modules and usage */

function Modules({ plan }: { plan: AgreementForCustomer }) {
  return (
    <Card className="p-6">
      <p className="text-sm font-semibold text-ink-900">What your plan includes</p>
      <ul className="mt-4 divide-y divide-hairline">
        {PLATFORM_MODULES.map((module) => {
          const included = plan.modules.includes(module.key);
          return (
            <li key={module.key} className="flex items-center gap-3 py-3">
              <span
                className={cn(
                  "grid h-7 w-7 shrink-0 place-items-center rounded-full",
                  included ? "bg-success-50 text-success-700" : "bg-ink-100 text-ink-500",
                )}
              >
                {included ? <Check className="h-4 w-4" aria-hidden /> : <Minus className="h-4 w-4" aria-hidden />}
              </span>
              <span className={cn("flex-1 text-[0.9375rem]", included ? "font-medium text-ink-900" : "text-ink-500")}>
                {module.name}
              </span>
              <span className={cn("text-xs font-medium", included ? "text-success-700" : "text-ink-500")}>
                {included ? "Included" : "Not included"}
              </span>
            </li>
          );
        })}
      </ul>
    </Card>
  );
}

function Meter({ label, used, limit }: { label: string; used: number; limit: number | null }) {
  const share = limit ? Math.min(100, Math.round((used / limit) * 100)) : 0;
  return (
    <div>
      <div className="flex items-baseline justify-between text-sm">
        <span className="font-medium text-ink-900">{label}</span>
        <span className="tabular text-ink-600">
          {used} of {limit ?? "unlimited"}
        </span>
      </div>
      <div className="mt-2 h-2 overflow-hidden rounded-full bg-ink-100" aria-hidden>
        <div
          className={cn("h-full rounded-full", share >= 100 ? "bg-danger-solid" : share >= 85 ? "bg-warning-solid" : "bg-primary")}
          style={{ width: limit ? `${share}%` : "6%" }}
        />
      </div>
    </div>
  );
}

function Usage({ plan }: { plan: AgreementForCustomer }) {
  return (
    <Card className="p-6">
      <p className="text-sm font-semibold text-ink-900">Your usage</p>
      <div className="mt-5 space-y-5">
        <Meter label="Projects" used={plan.usage.projects} limit={plan.limits.projects} />
        <Meter label="Users" used={plan.usage.users} limit={plan.limits.users} />
      </div>
    </Card>
  );
}

/* ------------------------------------------------------------ auto-pay */

function AutoPay({ plan }: { plan: AgreementForCustomer }) {
  const toast = useToast();
  const save = useSetAutoPay();
  const [on, setOn] = useState(plan.auto_pay);
  const [phone, setPhone] = useState(friendlyPhone(plan.auto_pay_phone));
  const [error, setError] = useState<string | null>(null);
  const changed = on !== plan.auto_pay || (on && phone !== friendlyPhone(plan.auto_pay_phone));

  const submit = () => {
    setError(null);
    save.mutate(
      { auto_pay: on, phone: on ? phone : undefined },
      {
        onSuccess: () => toast.success(on ? "Automatic payment is on." : "Automatic payment is off."),
        onError: (problem) => {
          const message =
            problem instanceof ApiRequestError
              ? (problem.validationErrors?.phone?.[0] ?? problem.message)
              : "The change could not be saved.";
          setError(message);
        },
      },
    );
  };

  return (
    <Card className="p-6">
      <p className="text-sm font-semibold text-ink-900">Automatic payment</p>
      <p className="mt-1.5 text-sm leading-relaxed text-ink-600">
        On each due date, SASA raises your invoice and sends the mobile money prompt to this number. You approve it with
        your PIN; no money moves without that.
      </p>
      <div className="mt-4">
        <Checkbox label="Send the payment prompt automatically" checked={on} onChange={setOn} />
      </div>
      {on ? (
        <div className="mt-4">
          <Field label="Mobile money number" error={error ?? undefined} htmlFor="auto-pay-phone">
            <Input
              id="auto-pay-phone"
              type="tel"
              inputMode="tel"
              placeholder="0712 345 678"
              value={phone}
              onChange={(event) => setPhone(event.target.value)}
              invalid={Boolean(error)}
            />
          </Field>
        </div>
      ) : error ? (
        <p className="mt-3 text-sm text-danger-600">{error}</p>
      ) : null}
      <Button variant="secondary" size="sm" className="mt-5" disabled={!changed} loading={save.isPending} onClick={submit}>
        Save
      </Button>
    </Card>
  );
}

/* ------------------------------------------------------------ invoices */

function Invoices({ invoices, loading, onPay }: { invoices: Invoice[]; loading: boolean; onPay: (invoice: Invoice) => void }) {
  return (
    <Card className="overflow-hidden">
      <div className="px-6 pb-2 pt-6">
        <p className="text-sm font-semibold text-ink-900">Invoices</p>
      </div>
      {loading ? (
        <LoadingState label="Loading invoices" />
      ) : invoices.length === 0 ? (
        <p className="px-6 pb-6 text-sm text-ink-600">Your first invoice appears here on your first billing day.</p>
      ) : (
        <ul className="divide-y divide-hairline">
          {invoices.map((invoice) => (
            <li key={invoice.public_id} className="flex flex-wrap items-center gap-x-6 gap-y-2 px-6 py-4">
              <div className="min-w-[10rem] flex-1">
                <p className="font-medium text-ink-900">{invoice.number}</p>
                <p className="text-sm text-ink-500">
                  {shortDate(invoice.period_start)} to {shortDate(invoice.period_end)}
                </p>
              </div>
              <p className="tabular w-36 font-semibold text-ink-900">{money(invoice.amount, invoice.currency)}</p>
              <div className="w-40">
                <StatusBadge status={INVOICE_TONE[invoice.status]} label={INVOICE_STATUS[invoice.status]} />
                <p className="mt-1 text-xs text-ink-500">
                  {invoice.status === "paid" && invoice.paid_at ? `Paid ${shortDate(invoice.paid_at)}` : `Due ${shortDate(invoice.due_on)}`}
                </p>
              </div>
              <div className="ml-auto">
                {invoice.status === "open" || invoice.status === "overdue" ? (
                  <Button size="sm" variant="accent" onClick={() => onPay(invoice)}>
                    Pay
                  </Button>
                ) : null}
              </div>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}

/* ------------------------------------------------------------ roles */

function Roles({ roles, loading }: { roles: Array<{ key: string; name: string; description: string | null; permissions: Array<{ key: string; name: string; group: string }> }>; loading: boolean }) {
  const [open, setOpen] = useState<string | null>(null);
  return (
    <Card className="overflow-hidden">
      <div className="px-6 pb-2 pt-6">
        <p className="text-sm font-semibold text-ink-900">Roles in your system</p>
        <p className="mt-1 text-sm text-ink-600">What each role in your organisation can see and do.</p>
      </div>
      {loading ? (
        <LoadingState label="Loading roles" />
      ) : (
        <ul className="divide-y divide-hairline">
          {roles.map((role) => {
            const expanded = open === role.key;
            const groups = role.permissions.reduce<Record<string, string[]>>((all, permission) => {
              (all[permission.group] ??= []).push(permission.name);
              return all;
            }, {});
            return (
              <li key={role.key}>
                <button
                  type="button"
                  aria-expanded={expanded}
                  onClick={() => setOpen(expanded ? null : role.key)}
                  className="flex w-full items-center gap-4 px-6 py-4 text-left transition-colors hover:bg-surface-sunken"
                >
                  <span className="min-w-0 flex-1">
                    <span className="block font-medium text-ink-900">{role.name}</span>
                    {role.description ? <span className="block truncate text-sm text-ink-600">{role.description}</span> : null}
                  </span>
                  <span className="text-xs text-ink-500">{role.permissions.length} permissions</span>
                  <ChevronDown className={cn("h-4 w-4 text-ink-400 transition", expanded && "rotate-180")} aria-hidden />
                </button>
                {expanded ? (
                  <div className="grid gap-5 px-6 pb-6 sm:grid-cols-2 lg:grid-cols-3">
                    {Object.entries(groups).map(([group, names]) => (
                      <div key={group}>
                        <p className="text-xs font-semibold text-ink-500">{group}</p>
                        <ul className="mt-2 space-y-1.5 text-sm text-ink-800">
                          {names.map((name) => (
                            <li key={name} className="flex items-start gap-2">
                              <Check className="mt-0.5 h-3.5 w-3.5 shrink-0 text-brand-600" aria-hidden />
                              {name}
                            </li>
                          ))}
                        </ul>
                      </div>
                    ))}
                  </div>
                ) : null}
              </li>
            );
          })}
        </ul>
      )}
    </Card>
  );
}

/* ------------------------------------------------------------ paying */

function PayDrawer({ invoice, defaultPhone, onClose }: { invoice: Invoice; defaultPhone: string | null; onClose: () => void }) {
  const queryClient = useQueryClient();
  const toast = useToast();
  const [method, setMethod] = useState<PaymentMethod>("mobile_money");
  const [phone, setPhone] = useState(friendlyPhone(defaultPhone));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [payment, setPayment] = useState<Payment | null>(null);

  useEffect(() => {
    if (!payment || payment.status !== "pending" || payment.method !== "mobile_money") return;
    const timer = window.setInterval(async () => {
      try {
        const { data } = await fetchPayment(payment.public_id);
        setPayment(data);
        if (data.status === "succeeded") {
          toast.success(`${invoice.number} is paid. Thank you.`);
          void queryClient.invalidateQueries({ queryKey: ["my-invoices"] });
          void queryClient.invalidateQueries({ queryKey: ["my-agreement"] });
          onClose();
        }
        if (data.status === "failed" || data.status === "cancelled") {
          setError(data.failure_reason ?? "The payment was not completed. You can try again.");
          setPayment(null);
        }
      } catch {
        /* the next poll will tell */
      }
    }, 3000);
    return () => window.clearInterval(timer);
  }, [invoice.number, onClose, payment, queryClient, toast]);

  const pay = async () => {
    setBusy(true);
    setError(null);
    try {
      const { data } = await payInvoice(invoice.public_id, { method, phone: method === "mobile_money" ? phone : undefined });
      if (data.status === "failed") {
        setError(data.failure_reason ?? "The payment could not be started.");
      } else if (method === "card" && data.checkout_url) {
        window.location.assign(data.checkout_url);
        return;
      } else {
        setPayment(data);
      }
    } catch (problem) {
      setError(
        problem instanceof ApiRequestError
          ? (problem.validationErrors?.phone?.[0] ?? problem.message)
          : "The payment could not be started. Please try again.",
      );
    } finally {
      setBusy(false);
    }
  };

  return (
    <Drawer open onClose={onClose} title={`Pay ${invoice.number}`} description={`${money(invoice.amount, invoice.currency)}, due ${longDate(invoice.due_on)}`}>
      {payment && payment.status === "pending" ? (
        <div className="flex flex-col items-center py-10 text-center" aria-live="polite">
          <Scanner label="Waiting for your approval" size={52} />
          <p className="mt-8 text-xl font-semibold text-ink-900">Check your phone</p>
          <p className="mt-2 max-w-[22rem] text-sm text-ink-600">
            A prompt has been sent to {friendlyPhone(payment.phone)}. Enter your mobile money PIN to pay{" "}
            {money(payment.amount, payment.currency)}.
          </p>
        </div>
      ) : (
        <div className="space-y-6">
          <div className="grid gap-3 sm:grid-cols-2" role="radiogroup" aria-label="Payment method">
            {(
              [
                { key: "mobile_money", icon: Smartphone, title: "Mobile money", detail: "Any network" },
                { key: "card", icon: CreditCard, title: "Card", detail: "On a secure page" },
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
                    "flex items-center gap-3 rounded-2xl p-4 text-left ring-1 transition",
                    selected ? "bg-brand-50 ring-2 ring-brand-500" : "ring-hairline-strong hover:bg-surface-sunken",
                  )}
                >
                  <Icon className="h-5 w-5 text-brand-700" aria-hidden />
                  <span>
                    <span className="block font-semibold text-ink-900">{option.title}</span>
                    <span className="block text-xs text-ink-600">{option.detail}</span>
                  </span>
                </button>
              );
            })}
          </div>
          {method === "mobile_money" ? (
            <Field label="Mobile money number" hint="A prompt will appear on this phone." htmlFor="pay-phone">
              <Input id="pay-phone" type="tel" inputMode="tel" placeholder="0712 345 678" value={phone} onChange={(event) => setPhone(event.target.value)} />
            </Field>
          ) : (
            <p className="flex items-start gap-2 rounded-xl bg-surface-sunken p-4 text-sm text-ink-600">
              <Lock className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
              You will enter your card on our payment partner&apos;s secure page. SASA never sees your card number.
            </p>
          )}
          {error ? (
            <p className="rounded-xl bg-danger-50 p-3.5 text-sm text-danger-700" role="alert">
              {error}
            </p>
          ) : null}
          <Button variant="accent" size="lg" fullWidth loading={busy} onClick={pay}>
            {method === "mobile_money" ? `Pay ${money(invoice.amount, invoice.currency)}` : "Continue to card payment"}
          </Button>
        </div>
      )}
    </Drawer>
  );
}

/** Back from the card page: confirm the payment, then tidy the address. */
function useCardReturn() {
  const params = useSearchParams();
  const router = useRouter();
  const toast = useToast();
  const queryClient = useQueryClient();
  const handled = useRef(false);
  const id = params.get("payment");

  useEffect(() => {
    if (!id || handled.current) return;
    handled.current = true;
    let tries = 0;
    const check = async () => {
      try {
        const { data } = await fetchPayment(id);
        if (data.status === "pending" && tries++ < 10) {
          window.setTimeout(check, 3000);
          return;
        }
        if (data.status === "succeeded") toast.success("Your card payment was received. Thank you.");
        else if (data.status !== "pending") toast.error(data.failure_reason ?? "The card payment did not go through.");
        void queryClient.invalidateQueries({ queryKey: ["my-invoices"] });
        void queryClient.invalidateQueries({ queryKey: ["my-agreement"] });
      } catch {
        /* nothing to confirm */
      }
      router.replace("/subscription");
    };
    void check();
  }, [id, queryClient, router, toast]);
}
