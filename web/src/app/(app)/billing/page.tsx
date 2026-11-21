"use client";

import { Building2, FileText, Plus } from "lucide-react";
import { useState } from "react";
import { Button } from "@/components/ui/Button";
import { PageHeader } from "@/components/ui/DetailLayout";
import { Drawer } from "@/components/ui/Drawer";
import { Field, FieldRow, Input, Select, Textarea } from "@/components/ui/Form";
import { StatusBadge } from "@/components/ui/StatusBadge";
import { EmptyState, LoadingState, PermissionDenied } from "@/components/ui/States";
import { ApiRequestError, apiRequest } from "@/lib/api/client";
import {
  AGREEMENT_STATUS,
  INVOICE_STATUS,
  INVOICE_TONE,
  useAdminInvoices,
  useIssueInvoice,
  useMarkInvoicePaid,
  useOrganisations,
  useSaveAgreement,
  useVoidInvoice,
  type AgreementInput,
  type AgreementStatus,
  type Invoice,
  type OrganisationRow,
} from "@/lib/api/agreements";
import { formatMoney } from "@/lib/api/billing";
import { PLATFORM_MODULES } from "@/lib/content/modules";
import { cn } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";
import { useToast } from "@/providers/ToastProvider";
import type { Agreement } from "@/lib/api/agreements";

const tzs = (amount: number | null | undefined) => formatMoney(amount ?? null, "TZS");
const shortDate = (iso: string) => new Date(iso).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" });

function problemText(problem: unknown, fallback: string) {
  return problem instanceof ApiRequestError ? problem.message : fallback;
}

export default function BillingPage() {
  const { can } = useSession();
  const [tab, setTab] = useState<"customers" | "invoices">("customers");

  if (!can("billing.manage")) return <PermissionDenied what="customers and billing" />;

  return (
    <div>
      <PageHeader
        eyebrow="Administration"
        title="Customers & billing"
        description="The plan agreed with each customer, and every monthly invoice. Prices are set per customer and never shown publicly."
      />

      <div role="tablist" aria-label="Billing" className="mb-8 inline-flex rounded-full bg-ink-100 p-1 text-sm font-semibold">
        {(
          [
            { key: "customers", label: "Customers" },
            { key: "invoices", label: "Invoices" },
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

      {tab === "customers" ? <Customers /> : <Invoices />}
    </div>
  );
}

/* ------------------------------------------------------------ customers */

function Customers() {
  const { data, isLoading } = useOrganisations();
  const issue = useIssueInvoice();
  const toast = useToast();
  const [editing, setEditing] = useState<{ organisation: OrganisationRow; agreement: Agreement | null } | null>(null);
  const rows = data?.data ?? [];

  const openEditor = async (organisation: OrganisationRow) => {
    if (!organisation.agreement) {
      setEditing({ organisation, agreement: null });
      return;
    }
    try {
      const list = await apiRequest<{ data: Agreement[] }>("/admin/agreements", { withoutProject: true, query: { per_page: 100 } });
      setEditing({ organisation, agreement: list.data.find((item) => item.public_id === organisation.agreement?.public_id) ?? null });
    } catch (problem) {
      toast.error(problemText(problem, "The agreement could not be opened."));
    }
  };

  if (isLoading) return <LoadingState label="Loading customers" />;
  if (rows.length === 0) return <EmptyState title="No customers yet" description="Organisations appear here once they are set up on SASA." />;

  return (
    <>
      <ul className="sasa-card divide-y divide-hairline overflow-hidden">
        {rows.map((row) => (
          <li key={row.id} className="grid gap-4 p-5 sm:p-6 lg:grid-cols-[1.3fr_1fr_auto] lg:items-center">
            <div className="flex min-w-0 items-start gap-3.5">
              <span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-600">
                <Building2 className="h-5 w-5" aria-hidden />
              </span>
              <div className="min-w-0">
                <p className="truncate font-semibold text-ink-900">{row.name}</p>
                <p className="text-sm text-ink-600">
                  {row.projects_count} projects · {row.users_count} users
                </p>
              </div>
            </div>
            <div>
              {row.agreement ? (
                <>
                  <p className="tabular font-semibold text-ink-900">{tzs(row.agreement.monthly_amount)} per month</p>
                  <p className="mt-0.5 flex items-center gap-2 text-sm text-ink-600">
                    <StatusBadge
                      size="sm"
                      status={row.agreement.status === "past_due" ? "overdue" : row.agreement.status}
                      label={AGREEMENT_STATUS[row.agreement.status]}
                    />
                    next due {shortDate(row.agreement.next_due_on)}
                  </p>
                </>
              ) : (
                <p className="text-sm text-ink-500">No agreement yet</p>
              )}
            </div>
            <div className="flex flex-wrap gap-2">
              <Button size="sm" variant={row.agreement ? "secondary" : "accent"} icon={row.agreement ? undefined : <Plus className="h-3.5 w-3.5" />} onClick={() => void openEditor(row)}>
                {row.agreement ? "Edit agreement" : "Set agreement"}
              </Button>
              {row.agreement ? (
                <Button
                  size="sm"
                  variant="ghost"
                  icon={<FileText className="h-3.5 w-3.5" />}
                  loading={issue.isPending && issue.variables === row.agreement.public_id}
                  onClick={() =>
                    issue.mutate(row.agreement!.public_id, {
                      onSuccess: ({ data: invoice }) => toast.success(`${invoice.number} issued to ${row.name}.`),
                      onError: (problem) => toast.error(problemText(problem, "The invoice could not be issued.")),
                    })
                  }
                >
                  Issue invoice now
                </Button>
              ) : null}
            </div>
          </li>
        ))}
      </ul>
      {editing ? <AgreementDrawer organisation={editing.organisation} agreement={editing.agreement} onClose={() => setEditing(null)} /> : null}
    </>
  );
}

function today() {
  return new Date().toISOString().slice(0, 10);
}

function AgreementDrawer({
  organisation,
  agreement,
  onClose,
}: {
  organisation: OrganisationRow;
  agreement: Agreement | null;
  onClose: () => void;
}) {
  const toast = useToast();
  const save = useSaveAgreement();
  const [form, setForm] = useState({
    name: agreement?.name ?? `SASA for ${organisation.name}`,
    amount: agreement ? String(agreement.monthly_amount) : "",
    billingDay: String(agreement?.billing_day ?? 1),
    startsOn: agreement?.starts_on?.slice(0, 10) ?? today(),
    modules: agreement?.modules ?? PLATFORM_MODULES.map((module) => module.key as string),
    projects: agreement?.limits.projects?.toString() ?? "",
    users: agreement?.limits.users?.toString() ?? "",
    terms: agreement?.terms_note ?? "",
    internal: agreement?.internal_note ?? "",
    status: (agreement?.status ?? "active") as AgreementStatus,
  });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const set = <K extends keyof typeof form>(key: K, value: (typeof form)[K]) => setForm((current) => ({ ...current, [key]: value }));
  const num = (value: string) => (value.trim() === "" ? null : Number(value.replace(/,/g, "")));

  const submit = () => {
    const amount = num(form.amount);
    const day = Number(form.billingDay);
    const next: Record<string, string> = {};
    if (!form.name.trim()) next.name = "Give the agreement a name.";
    if (amount === null || !Number.isFinite(amount) || amount <= 0) next.monthly_amount = "Enter the agreed monthly amount in shillings.";
    if (!Number.isInteger(day) || day < 1 || day > 28) next.billing_day = "Choose a day from 1 to 28.";
    if (form.modules.length === 0) next.modules = "Include at least one module.";
    setErrors(next);
    if (Object.keys(next).length) return;

    const body: AgreementInput = {
      ...(agreement ? { status: form.status } : { organisation_id: organisation.id }),
      name: form.name.trim(),
      monthly_amount: amount as number,
      billing_day: day,
      starts_on: form.startsOn,
      modules: form.modules,
      limits: { projects: num(form.projects), users: num(form.users) },
      terms_note: form.terms.trim() || null,
      internal_note: form.internal.trim() || null,
    };
    save.mutate(
      { publicId: agreement?.public_id ?? null, body },
      {
        onSuccess: () => {
          toast.success(agreement ? "The agreement was updated." : `${organisation.name} now has an agreed plan.`);
          onClose();
        },
        onError: (problem) => {
          if (problem instanceof ApiRequestError && problem.validationErrors) {
            setErrors(Object.fromEntries(Object.entries(problem.validationErrors).map(([key, value]) => [key, value[0] ?? ""])));
          }
          toast.error(problemText(problem, "The agreement could not be saved."));
        },
      },
    );
  };

  return (
    <Drawer
      open
      onClose={onClose}
      width="lg"
      title={agreement ? `Agreement with ${organisation.name}` : `Set an agreement for ${organisation.name}`}
      description="What the customer sees in Your plan: the monthly amount, the modules and the limits. Changes apply to invoices not yet issued."
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button variant="primary" loading={save.isPending} onClick={submit}>
            {agreement ? "Save changes" : "Create agreement"}
          </Button>
        </div>
      }
    >
      <div className="space-y-5">
        <Field label="Name" required error={errors.name} htmlFor="agreement-name">
          <Input id="agreement-name" value={form.name} onChange={(event) => set("name", event.target.value)} invalid={Boolean(errors.name)} />
        </Field>
        <FieldRow columns={3}>
          <Field label="Monthly amount (TZS)" required error={errors.monthly_amount} htmlFor="agreement-amount">
            <Input
              id="agreement-amount"
              inputMode="numeric"
              placeholder="1,150,000"
              value={form.amount}
              onChange={(event) => set("amount", event.target.value)}
              invalid={Boolean(errors.monthly_amount)}
            />
          </Field>
          <Field label="Billing day" required error={errors.billing_day} hint="1 to 28" htmlFor="agreement-day">
            <Input id="agreement-day" inputMode="numeric" value={form.billingDay} onChange={(event) => set("billingDay", event.target.value)} />
          </Field>
          <Field label="Starts on" error={errors.starts_on} htmlFor="agreement-start">
            <Input id="agreement-start" type="date" value={form.startsOn} onChange={(event) => set("startsOn", event.target.value)} />
          </Field>
        </FieldRow>

        <fieldset>
          <legend className="mb-2 text-sm font-medium text-ink-800">Modules included</legend>
          <div className="flex flex-wrap gap-2">
            {PLATFORM_MODULES.map((module) => {
              const on = form.modules.includes(module.key);
              return (
                <button
                  key={module.key}
                  type="button"
                  role="checkbox"
                  aria-checked={on}
                  onClick={() => set("modules", on ? form.modules.filter((key) => key !== module.key) : [...form.modules, module.key])}
                  className={cn(
                    "rounded-full px-3.5 py-2 text-sm font-medium transition-colors",
                    on ? "bg-primary text-on-primary" : "bg-ink-100 text-ink-700 hover:bg-ink-200",
                  )}
                >
                  {module.name}
                </button>
              );
            })}
          </div>
          {errors.modules ? <p className="mt-1.5 text-sm text-danger-600">{errors.modules}</p> : null}
        </fieldset>

        <FieldRow>
          <Field label="Project limit" hint="Blank means unlimited." htmlFor="agreement-projects">
            <Input id="agreement-projects" inputMode="numeric" value={form.projects} onChange={(event) => set("projects", event.target.value)} />
          </Field>
          <Field label="User limit" hint="Blank means unlimited." htmlFor="agreement-users">
            <Input id="agreement-users" inputMode="numeric" value={form.users} onChange={(event) => set("users", event.target.value)} />
          </Field>
        </FieldRow>

        {agreement ? (
          <Field label="Status" htmlFor="agreement-status">
            <Select id="agreement-status" value={form.status} onChange={(event) => set("status", event.target.value as AgreementStatus)}>
              {(["active", "suspended", "cancelled"] as AgreementStatus[]).map((value) => (
                <option key={value} value={value}>
                  {AGREEMENT_STATUS[value]}
                </option>
              ))}
              {form.status === "past_due" ? <option value="past_due">{AGREEMENT_STATUS.past_due}</option> : null}
            </Select>
          </Field>
        ) : null}

        <Field label="Agreed terms" optional hint="Shown to the customer on Your plan." htmlFor="agreement-terms">
          <Textarea id="agreement-terms" rows={3} value={form.terms} onChange={(event) => set("terms", event.target.value)} />
        </Field>
        <Field label="Internal note" optional hint="For the SASA team only. Never shown to the customer." htmlFor="agreement-internal">
          <Textarea id="agreement-internal" rows={3} value={form.internal} onChange={(event) => set("internal", event.target.value)} />
        </Field>
      </div>
    </Drawer>
  );
}

/* ------------------------------------------------------------ invoices */

function Invoices() {
  const [status, setStatus] = useState("");
  const { data, isLoading } = useAdminInvoices({ status });
  const markPaid = useMarkInvoicePaid();
  const voidInvoice = useVoidInvoice();
  const toast = useToast();
  const rows = data?.data ?? [];

  const onMarkPaid = (invoice: Invoice) => {
    const note = window.prompt(`Mark ${invoice.number} as paid outside SASA (for example by bank transfer). Add a note:`, "");
    if (note === null) return;
    markPaid.mutate(
      { publicId: invoice.public_id, note: note.trim() || null },
      {
        onSuccess: () => toast.success(`${invoice.number} is marked paid.`),
        onError: (problem) => toast.error(problemText(problem, "The invoice could not be updated.")),
      },
    );
  };

  const onVoid = (invoice: Invoice) => {
    const reason = window.prompt(`Why is ${invoice.number} being voided?`, "");
    if (!reason?.trim()) return;
    voidInvoice.mutate(
      { publicId: invoice.public_id, reason: reason.trim() },
      {
        onSuccess: () => toast.success(`${invoice.number} was voided.`),
        onError: (problem) => toast.error(problemText(problem, "The invoice could not be voided.")),
      },
    );
  };

  return (
    <div>
      <div className="mb-5 flex flex-wrap gap-2">
        {[
          { value: "", label: "All" },
          { value: "open", label: "Due" },
          { value: "overdue", label: "Overdue" },
          { value: "paid", label: "Paid" },
        ].map((filter) => (
          <button
            key={filter.value}
            type="button"
            aria-pressed={status === filter.value}
            onClick={() => setStatus(filter.value)}
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
        <LoadingState label="Loading invoices" />
      ) : rows.length === 0 ? (
        <EmptyState title="No invoices" description="Invoices are raised on each customer's billing day, or when you issue one." />
      ) : (
        <ul className="sasa-card divide-y divide-hairline overflow-hidden">
          {rows.map((invoice) => (
            <li key={invoice.public_id} className="grid gap-3 p-5 sm:p-6 lg:grid-cols-[1.3fr_1fr_1fr_auto] lg:items-center">
              <div className="min-w-0">
                <p className="text-xs text-ink-500">{invoice.number}</p>
                <p className="truncate font-semibold text-ink-900">{invoice.organisation?.name ?? "Customer"}</p>
                <p className="text-sm text-ink-600">
                  {shortDate(invoice.period_start)} to {shortDate(invoice.period_end)}
                </p>
              </div>
              <p className="tabular font-semibold text-ink-900">{tzs(invoice.amount)}</p>
              <div>
                <StatusBadge status={INVOICE_TONE[invoice.status]} label={INVOICE_STATUS[invoice.status]} />
                <p className="mt-1 text-xs text-ink-500">
                  {invoice.status === "paid" && invoice.paid_at ? `Paid ${shortDate(invoice.paid_at)}` : `Due ${shortDate(invoice.due_on)}`}
                </p>
              </div>
              <div className="flex gap-2">
                {invoice.status === "open" || invoice.status === "overdue" ? (
                  <>
                    <Button size="sm" variant="secondary" onClick={() => onMarkPaid(invoice)}>
                      Mark paid
                    </Button>
                    <Button size="sm" variant="ghost" onClick={() => onVoid(invoice)}>
                      Void
                    </Button>
                  </>
                ) : null}
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
