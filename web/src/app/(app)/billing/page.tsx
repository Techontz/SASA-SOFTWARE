"use client";

import { Archive, ArchiveRestore, CreditCard, Pencil, Plus, Smartphone } from "lucide-react";
import { useState } from "react";
import { Button } from "@/components/ui/Button";
import { PageHeader } from "@/components/ui/DetailLayout";
import { Drawer } from "@/components/ui/Drawer";
import { Checkbox, Field, FieldRow, Input, Select, Textarea } from "@/components/ui/Form";
import { StatusBadge } from "@/components/ui/StatusBadge";
import { EmptyState, LoadingState, PermissionDenied } from "@/components/ui/States";
import { ApiRequestError } from "@/lib/api/client";
import {
  formatMoney,
  useAdminPlans,
  useAdminSubscriptions,
  useArchivePlan,
  useRestorePlan,
  useSavePlan,
  useUpdateSubscription,
} from "@/lib/api/billing";
import { cn, formatRelative } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";
import { useToast } from "@/providers/ToastProvider";
import type { Plan, PlanInput, SubscriptionRequest, SubscriptionStatus } from "@/types/billing";

const STATUS_LABEL: Record<SubscriptionStatus, string> = {
  pending_payment: "Awaiting payment",
  paid: "Paid",
  quote_requested: "Quote requested",
  active: "Active",
  rejected: "Rejected",
  cancelled: "Cancelled",
};

const STATUS_FILTERS: Array<{ value: string; label: string }> = [
  { value: "", label: "All" },
  { value: "paid", label: "Paid" },
  { value: "pending_payment", label: "Awaiting payment" },
  { value: "quote_requested", label: "Quotes" },
  { value: "active", label: "Active" },
];

export default function BillingPage() {
  const { can } = useSession();
  const [tab, setTab] = useState<"subscriptions" | "plans">("subscriptions");

  if (!can("billing.manage")) return <PermissionDenied what="plans and billing" />;

  return (
    <div>
      <PageHeader
        eyebrow="Administration"
        title="Plans & billing"
        description="The plans people can subscribe to, and every subscription request with its payment."
      />

      <div role="tablist" aria-label="Billing" className="mb-8 inline-flex rounded-full bg-ink-100 p-1 text-sm font-semibold">
        {(
          [
            { key: "subscriptions", label: "Subscriptions" },
            { key: "plans", label: "Plans" },
          ] as const
        ).map((item) => (
          <button
            key={item.key}
            type="button"
            role="tab"
            aria-selected={tab === item.key}
            onClick={() => setTab(item.key)}
            className={cn(
              "rounded-full px-5 py-2 transition-all duration-[var(--duration-base)] ease-[var(--ease-spring)]",
              tab === item.key ? "bg-surface text-ink-900 shadow-[var(--shadow-raised)]" : "text-ink-600 hover:text-ink-900",
            )}
          >
            {item.label}
          </button>
        ))}
      </div>

      {tab === "subscriptions" ? <Subscriptions /> : <Plans />}
    </div>
  );
}

/* ------------------------------------------------------------ subscriptions */

function Subscriptions() {
  const [status, setStatus] = useState("");
  const { data, isLoading } = useAdminSubscriptions({ status });
  const [editing, setEditing] = useState<SubscriptionRequest | null>(null);
  const rows = data?.data ?? [];

  return (
    <div>
      <div className="mb-5 flex flex-wrap gap-2">
        {STATUS_FILTERS.map((filter) => (
          <button
            key={filter.value}
            type="button"
            onClick={() => setStatus(filter.value)}
            aria-pressed={status === filter.value}
            className={cn(
              "rounded-full px-4 py-1.5 text-sm font-medium transition",
              status === filter.value ? "bg-ink-900 text-surface" : "bg-ink-100 text-ink-700 hover:bg-ink-200",
            )}
          >
            {filter.label}
          </button>
        ))}
      </div>

      {isLoading ? (
        <LoadingState label="Loading subscriptions" />
      ) : rows.length === 0 ? (
        <EmptyState title="No subscriptions yet" description="Requests made from the pricing page appear here." />
      ) : (
        <ul className="sasa-card divide-y divide-hairline overflow-hidden">
          {rows.map((row) => {
            const payment = row.latest_payment;
            const PayIcon = payment?.method === "card" ? CreditCard : Smartphone;
            return (
              <li key={row.public_id} className="grid gap-4 p-5 sm:p-6 lg:grid-cols-[1.4fr_1fr_1fr_auto] lg:items-center">
                <div className="min-w-0">
                  <p className="text-xs text-ink-500">{row.reference}</p>
                  <p className="mt-0.5 truncate font-semibold text-ink-900">{row.organisation_name}</p>
                  <p className="mt-0.5 truncate text-sm text-ink-600">
                    {row.contact_name} · {row.email} · {row.phone}
                  </p>
                </div>
                <div>
                  <p className="font-medium text-ink-900">{row.plan.name}</p>
                  <p className="tabular text-sm text-ink-600">
                    {row.amount === null ? "Custom quote" : formatMoney(row.amount, row.currency)}
                  </p>
                </div>
                <div className="flex flex-col items-start gap-1.5">
                  <StatusBadge status={row.status} label={STATUS_LABEL[row.status]} />
                  {payment ? (
                    <span className="inline-flex items-center gap-1.5 text-xs text-ink-500">
                      <PayIcon className="h-3.5 w-3.5" aria-hidden />
                      {payment.method === "card" ? "Card" : "Mobile money"}, {payment.status}
                    </span>
                  ) : null}
                  <span className="text-xs text-ink-500">{formatRelative(row.created_at)}</span>
                </div>
                <div>
                  <Button size="sm" variant="secondary" onClick={() => setEditing(row)}>
                    Review
                  </Button>
                </div>
              </li>
            );
          })}
        </ul>
      )}

      {editing ? <ReviewDrawer row={editing} onClose={() => setEditing(null)} /> : null}
    </div>
  );
}

function ReviewDrawer({ row, onClose }: { row: SubscriptionRequest; onClose: () => void }) {
  const toast = useToast();
  const update = useUpdateSubscription();
  const [status, setStatus] = useState<SubscriptionStatus>(row.status === "paid" ? "active" : row.status);
  const [notes, setNotes] = useState(row.admin_notes ?? "");

  const save = () => {
    update.mutate(
      { publicId: row.public_id, status, admin_notes: notes.trim() || null },
      {
        onSuccess: () => {
          toast.success(`${row.reference} is now ${STATUS_LABEL[status].toLowerCase()}.`);
          onClose();
        },
        onError: (error) => toast.error(error instanceof ApiRequestError ? error.message : "The change could not be saved."),
      },
    );
  };

  return (
    <Drawer
      open
      onClose={onClose}
      title={row.organisation_name}
      description={`${row.reference} · ${row.plan.name}`}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button variant="primary" loading={update.isPending} onClick={save}>
            Save
          </Button>
        </div>
      }
    >
      <dl className="grid grid-cols-2 gap-4 text-sm">
        <div>
          <dt className="text-ink-500">Contact</dt>
          <dd className="font-medium text-ink-900">{row.contact_name}</dd>
        </div>
        <div>
          <dt className="text-ink-500">Amount</dt>
          <dd className="tabular font-medium text-ink-900">{row.amount === null ? "Custom quote" : formatMoney(row.amount, row.currency)}</dd>
        </div>
        <div>
          <dt className="text-ink-500">E-mail</dt>
          <dd className="break-all font-medium text-ink-900">{row.email}</dd>
        </div>
        <div>
          <dt className="text-ink-500">Phone</dt>
          <dd className="font-medium text-ink-900">{row.phone}</dd>
        </div>
        {row.message ? (
          <div className="col-span-2">
            <dt className="text-ink-500">Their message</dt>
            <dd className="text-ink-800">{row.message}</dd>
          </div>
        ) : null}
      </dl>

      <div className="mt-6 space-y-5">
        <Field label="Status" htmlFor="sub-status">
          <Select id="sub-status" value={status} onChange={(event) => setStatus(event.target.value as SubscriptionStatus)}>
            {(["active", "rejected", "cancelled"] as SubscriptionStatus[]).map((value) => (
              <option key={value} value={value}>
                {STATUS_LABEL[value]}
              </option>
            ))}
            {!["active", "rejected", "cancelled"].includes(row.status) ? (
              <option value={row.status}>{STATUS_LABEL[row.status]} (unchanged)</option>
            ) : null}
          </Select>
        </Field>
        <Field label="Notes" optional hint="Kept with the request. The customer does not see them." htmlFor="sub-notes">
          <Textarea id="sub-notes" rows={4} value={notes} onChange={(event) => setNotes(event.target.value)} />
        </Field>
      </div>
    </Drawer>
  );
}

/* ------------------------------------------------------------ plans */

const BLANK: PlanInput = {
  code: "",
  name: "",
  tagline: "",
  description: "",
  price_usd: null,
  price_tzs: null,
  billing_period: "monthly",
  features: [],
  limits: { projects: null, users: null },
  is_featured: false,
  is_custom_quote: false,
  sort_order: 10,
};

function Plans() {
  const [showArchived, setShowArchived] = useState(false);
  const { data, isLoading } = useAdminPlans(showArchived);
  const archive = useArchivePlan();
  const restore = useRestorePlan();
  const toast = useToast();
  const [editing, setEditing] = useState<{ id: number | null; input: PlanInput } | null>(null);
  const plans = data?.data ?? [];

  const open = (plan: Plan | null) =>
    setEditing(
      plan
        ? {
            id: plan.id,
            input: {
              code: plan.code,
              name: plan.name,
              tagline: plan.tagline,
              description: plan.description,
              price_usd: plan.price_usd,
              price_tzs: plan.price_tzs,
              billing_period: plan.billing_period,
              features: plan.features,
              limits: plan.limits,
              is_featured: plan.is_featured,
              is_custom_quote: plan.is_custom_quote,
              sort_order: plan.sort_order,
            },
          }
        : { id: null, input: { ...BLANK, sort_order: (plans.at(-1)?.sort_order ?? 0) + 10 } },
    );

  return (
    <div>
      <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
        <Checkbox
          label="Show archived plans"
          checked={showArchived}
          onChange={(value) => setShowArchived(value)}
        />
        <Button variant="accent" icon={<Plus className="h-4 w-4" />} onClick={() => open(null)}>
          New plan
        </Button>
      </div>

      {isLoading ? (
        <LoadingState label="Loading plans" />
      ) : plans.length === 0 ? (
        <EmptyState title="No plans" description="Create the first plan people can subscribe to." />
      ) : (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {plans.map((plan) => (
            <article key={plan.id} className={cn("sasa-card flex flex-col p-6", plan.archived_at && "opacity-70")}>
              <div className="flex items-start justify-between gap-3">
                <div>
                  <h3 className="text-lg font-semibold text-ink-900">{plan.name}</h3>
                  <p className="text-sm text-ink-500">{plan.tagline}</p>
                </div>
                <div className="flex flex-wrap justify-end gap-1.5">
                  {plan.is_featured ? <StatusBadge status="featured" label="Featured" size="sm" /> : null}
                  {plan.archived_at ? <StatusBadge status="archived" label="Archived" size="sm" /> : null}
                </div>
              </div>
              <p className="tabular mt-5 text-sm text-ink-700">
                {plan.is_custom_quote
                  ? "Custom quote"
                  : `${formatMoney(plan.price_usd, "USD")} · ${formatMoney(plan.price_tzs, "TZS")} per ${
                      plan.billing_period === "yearly" ? "year" : "month"
                    }`}
              </p>
              <p className="mt-1 text-sm text-ink-500">
                {plan.limits.projects ?? "Unlimited"} projects · {plan.limits.users ?? "unlimited"} users ·{" "}
                {plan.features.length} features
              </p>
              <div className="mt-6 flex gap-2 pt-1">
                <Button size="sm" variant="secondary" icon={<Pencil className="h-3.5 w-3.5" />} onClick={() => open(plan)}>
                  Edit
                </Button>
                {plan.archived_at ? (
                  <Button
                    size="sm"
                    variant="ghost"
                    icon={<ArchiveRestore className="h-3.5 w-3.5" />}
                    loading={restore.isPending && restore.variables === plan.id}
                    onClick={() =>
                      restore.mutate(plan.id, { onSuccess: () => toast.success(`${plan.name} is offered again.`) })
                    }
                  >
                    Restore
                  </Button>
                ) : (
                  <Button
                    size="sm"
                    variant="ghost"
                    icon={<Archive className="h-3.5 w-3.5" />}
                    loading={archive.isPending && archive.variables === plan.id}
                    onClick={() => {
                      if (!window.confirm(`Archive ${plan.name}? It will no longer be offered. Existing subscriptions keep it.`)) return;
                      archive.mutate(plan.id, { onSuccess: () => toast.success(`${plan.name} was archived.`) });
                    }}
                  >
                    Archive
                  </Button>
                )}
              </div>
            </article>
          ))}
        </div>
      )}

      {editing ? <PlanDrawer id={editing.id} initial={editing.input} onClose={() => setEditing(null)} /> : null}
    </div>
  );
}

function numberOrNull(value: string): number | null {
  const trimmed = value.replace(/,/g, "").trim();
  if (trimmed === "") return null;
  const parsed = Number(trimmed);
  return Number.isFinite(parsed) ? parsed : null;
}

function PlanDrawer({ id, initial, onClose }: { id: number | null; initial: PlanInput; onClose: () => void }) {
  const toast = useToast();
  const save = useSavePlan();
  const [form, setForm] = useState({
    ...initial,
    tagline: initial.tagline ?? "",
    description: initial.description ?? "",
    priceUsd: initial.price_usd?.toString() ?? "",
    priceTzs: initial.price_tzs?.toString() ?? "",
    projects: initial.limits.projects?.toString() ?? "",
    users: initial.limits.users?.toString() ?? "",
    featureText: initial.features.join("\n"),
  });
  const [errors, setErrors] = useState<Record<string, string>>({});

  const set = <K extends keyof typeof form>(key: K, value: (typeof form)[K]) => setForm((current) => ({ ...current, [key]: value }));

  const submit = () => {
    const body: PlanInput = {
      code: form.code.trim() || form.name.trim().toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/(^-|-$)/g, ""),
      name: form.name.trim(),
      tagline: form.tagline.trim() || null,
      description: form.description.trim() || null,
      price_usd: form.is_custom_quote ? null : numberOrNull(form.priceUsd),
      price_tzs: form.is_custom_quote ? null : numberOrNull(form.priceTzs),
      billing_period: form.billing_period,
      features: form.featureText
        .split("\n")
        .map((line) => line.trim())
        .filter(Boolean),
      limits: { projects: numberOrNull(form.projects), users: numberOrNull(form.users) },
      is_featured: form.is_featured,
      is_custom_quote: form.is_custom_quote,
      sort_order: form.sort_order,
    };

    const next: Record<string, string> = {};
    if (!body.name) next.name = "Give the plan a name.";
    if (!body.is_custom_quote && (body.price_usd === null || body.price_tzs === null)) {
      next.price = "Set a price in both USD and TZS, or mark the plan as a custom quote.";
    }
    setErrors(next);
    if (Object.keys(next).length) return;

    save.mutate(
      { id, body },
      {
        onSuccess: () => {
          toast.success(id ? `${body.name} was updated.` : `${body.name} was created.`);
          onClose();
        },
        onError: (error) => {
          if (error instanceof ApiRequestError && error.validationErrors) {
            setErrors(Object.fromEntries(Object.entries(error.validationErrors).map(([key, value]) => [key, value[0] ?? ""])));
          }
          toast.error(error instanceof ApiRequestError ? error.message : "The plan could not be saved.");
        },
      },
    );
  };

  return (
    <Drawer
      open
      onClose={onClose}
      width="lg"
      title={id ? `Edit ${initial.name}` : "New plan"}
      description="Changes apply to new subscriptions. Existing ones keep the price they paid."
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button variant="primary" loading={save.isPending} onClick={submit}>
            {id ? "Save changes" : "Create plan"}
          </Button>
        </div>
      }
    >
      <div className="space-y-5">
        <FieldRow>
          <Field label="Name" required error={errors.name} htmlFor="plan-name">
            <Input id="plan-name" value={form.name} onChange={(event) => set("name", event.target.value)} invalid={Boolean(errors.name)} />
          </Field>
          <Field label="Code" hint="Used in links. Left blank, it is made from the name." error={errors.code} htmlFor="plan-code">
            <Input id="plan-code" value={form.code} onChange={(event) => set("code", event.target.value)} />
          </Field>
        </FieldRow>
        <Field label="Tagline" optional htmlFor="plan-tagline">
          <Input id="plan-tagline" value={form.tagline} onChange={(event) => set("tagline", event.target.value)} />
        </Field>

        <Checkbox
          label="Custom quote (no online payment; people request a proposal)"
          checked={form.is_custom_quote}
          onChange={(value) => set("is_custom_quote", value)}
        />

        {!form.is_custom_quote ? (
          <>
            <FieldRow columns={3}>
              <Field label="Price in USD" error={errors.price_usd} htmlFor="plan-usd">
                <Input id="plan-usd" inputMode="decimal" value={form.priceUsd} onChange={(event) => set("priceUsd", event.target.value)} />
              </Field>
              <Field label="Price in TZS" error={errors.price_tzs} htmlFor="plan-tzs">
                <Input id="plan-tzs" inputMode="numeric" value={form.priceTzs} onChange={(event) => set("priceTzs", event.target.value)} />
              </Field>
              <Field label="Billed" htmlFor="plan-period">
                <Select
                  id="plan-period"
                  value={form.billing_period}
                  onChange={(event) => set("billing_period", event.target.value as PlanInput["billing_period"])}
                >
                  <option value="monthly">Monthly</option>
                  <option value="yearly">Yearly</option>
                </Select>
              </Field>
            </FieldRow>
            {errors.price ? (
              <p className="text-sm text-danger-600" role="alert">
                {errors.price}
              </p>
            ) : null}
          </>
        ) : null}

        <FieldRow columns={3}>
          <Field label="Projects" hint="Blank means unlimited." htmlFor="plan-projects">
            <Input id="plan-projects" inputMode="numeric" value={form.projects} onChange={(event) => set("projects", event.target.value)} />
          </Field>
          <Field label="Users" hint="Blank means unlimited." htmlFor="plan-users">
            <Input id="plan-users" inputMode="numeric" value={form.users} onChange={(event) => set("users", event.target.value)} />
          </Field>
          <Field label="Order on the page" htmlFor="plan-order">
            <Input
              id="plan-order"
              inputMode="numeric"
              value={String(form.sort_order)}
              onChange={(event) => set("sort_order", Number(event.target.value) || 0)}
            />
          </Field>
        </FieldRow>

        <Field label="What is included" hint="One feature per line." error={errors.features} htmlFor="plan-features">
          <Textarea id="plan-features" rows={8} value={form.featureText} onChange={(event) => set("featureText", event.target.value)} />
        </Field>
        <Field label="Description" optional htmlFor="plan-description">
          <Textarea id="plan-description" rows={3} value={form.description} onChange={(event) => set("description", event.target.value)} />
        </Field>
        <Checkbox
          label="Feature this plan (highlighted on the pricing page)"
          checked={form.is_featured}
          onChange={(value) => set("is_featured", value)}
        />
      </div>
    </Drawer>
  );
}
