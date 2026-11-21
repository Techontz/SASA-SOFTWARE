"use client";

import { useQuery } from "@tanstack/react-query";
import { apiRequest } from "./client";
import { clean, useApiMutation, type Query } from "./hooks";
import type { Paginated } from "@/types/api";
import type { Payment, PaymentMethod } from "@/types/billing";

/*
 * B2B billing: each organisation has one agreed plan (an agreement) and is
 * invoiced monthly. Prices are never public; they are set per customer.
 */

export type AgreementStatus = "active" | "past_due" | "suspended" | "cancelled";
export type InvoiceStatus = "open" | "paid" | "overdue" | "void";

export interface AgreementLimits {
  projects: number | null;
  users: number | null;
}

export interface AgreementForCustomer {
  public_id: string;
  name: string;
  currency: string;
  monthly_amount: number;
  billing_day: number;
  starts_on: string;
  next_due_on: string;
  status: AgreementStatus;
  modules: string[];
  limits: AgreementLimits;
  terms_note: string | null;
  auto_pay: boolean;
  auto_pay_phone: string | null;
  usage: { projects: number; users: number };
}

export interface Agreement extends AgreementForCustomer {
  organisation: { id: number; name: string };
  internal_note: string | null;
  enquiry_id?: number | null;
}

export interface Invoice {
  public_id: string;
  number: string;
  period_start: string;
  period_end: string;
  amount: number;
  currency: string;
  due_on: string;
  status: InvoiceStatus;
  paid_at: string | null;
  latest_payment: Payment | null;
  organisation?: { id: number; name: string };
}

export interface OrganisationRow {
  id: number;
  name: string;
  projects_count: number;
  users_count: number;
  agreement: { public_id: string; name: string; monthly_amount: number; currency: string; status: AgreementStatus; next_due_on: string } | null;
}

export interface RoleSummary {
  key: string;
  name: string;
  description: string | null;
  permissions: Array<{ key: string; name: string; group: string }>;
}

export interface AgreementInput {
  organisation_id?: number;
  enquiry_id?: number | null;
  name: string;
  monthly_amount: number;
  billing_day: number;
  starts_on: string;
  modules: string[];
  limits: AgreementLimits;
  terms_note: string | null;
  internal_note: string | null;
  status?: AgreementStatus;
}

const PLATFORM = { withoutProject: true } as const;

/* ------------------------------------------------------------ platform admin */

export function useOrganisations() {
  return useQuery({
    queryKey: ["admin-organisations"],
    queryFn: () => apiRequest<{ data: OrganisationRow[] }>("/admin/organisations", PLATFORM),
  });
}

export function useAgreements(query: Query = {}) {
  return useQuery({
    queryKey: ["admin-agreements", clean(query)],
    queryFn: () => apiRequest<Paginated<Agreement>>("/admin/agreements", { ...PLATFORM, query: clean(query) }),
  });
}

export function useSaveAgreement() {
  return useApiMutation(
    ({ publicId, body }: { publicId: string | null; body: AgreementInput }) =>
      publicId
        ? apiRequest<{ data: Agreement }>(`/admin/agreements/${publicId}`, { ...PLATFORM, method: "PATCH", body })
        : apiRequest<{ data: Agreement }>("/admin/agreements", { ...PLATFORM, method: "POST", body }),
    ["admin-agreements", "admin-organisations", "admin-invoices"],
  );
}

export function useIssueInvoice() {
  return useApiMutation(
    (publicId: string) => apiRequest<{ data: Invoice }>(`/admin/agreements/${publicId}/invoices`, { ...PLATFORM, method: "POST" }),
    ["admin-invoices", "admin-agreements", "admin-organisations"],
  );
}

export function useAdminInvoices(query: Query = {}) {
  return useQuery({
    queryKey: ["admin-invoices", clean(query)],
    queryFn: () => apiRequest<Paginated<Invoice>>("/admin/invoices", { ...PLATFORM, query: clean(query) }),
  });
}

export function useMarkInvoicePaid() {
  return useApiMutation(
    ({ publicId, note }: { publicId: string; note: string | null }) =>
      apiRequest<{ data: Invoice }>(`/admin/invoices/${publicId}/mark-paid`, { ...PLATFORM, method: "POST", body: { note } }),
    ["admin-invoices", "admin-agreements"],
  );
}

export function useVoidInvoice() {
  return useApiMutation(
    ({ publicId, reason }: { publicId: string; reason: string }) =>
      apiRequest<{ data: Invoice }>(`/admin/invoices/${publicId}/void`, { ...PLATFORM, method: "POST", body: { reason } }),
    ["admin-invoices", "admin-agreements"],
  );
}

/* ------------------------------------------------------------ the customer */

export function useMyAgreement(enabled = true) {
  return useQuery({
    queryKey: ["my-agreement"],
    queryFn: () => apiRequest<{ data: AgreementForCustomer | null }>("/organisation/agreement"),
    enabled,
  });
}

export function useMyInvoices(enabled = true) {
  return useQuery({
    queryKey: ["my-invoices"],
    queryFn: () => apiRequest<{ data: Invoice[] }>("/organisation/invoices"),
    enabled,
  });
}

export function useOrganisationRoles(enabled = true) {
  return useQuery({
    queryKey: ["organisation-roles"],
    queryFn: () => apiRequest<{ data: RoleSummary[] }>("/organisation/roles"),
    enabled,
    staleTime: 5 * 60_000,
  });
}

export function payInvoice(publicId: string, body: { method: PaymentMethod; phone?: string }) {
  return apiRequest<{ data: Payment }>(`/organisation/invoices/${publicId}/pay`, { method: "POST", body });
}

export function useSetAutoPay() {
  return useApiMutation(
    (body: { auto_pay: boolean; phone?: string }) =>
      apiRequest<{ data: AgreementForCustomer }>("/organisation/agreement/auto-pay", { method: "PATCH", body }),
    ["my-agreement"],
  );
}

/* ------------------------------------------------------------ labels */

export const AGREEMENT_STATUS: Record<AgreementStatus, string> = {
  active: "Active",
  past_due: "Payment overdue",
  suspended: "Suspended",
  cancelled: "Cancelled",
};

export const INVOICE_STATUS: Record<InvoiceStatus, string> = {
  open: "Due",
  paid: "Paid",
  overdue: "Overdue",
  void: "Void",
};

/** StatusBadge keys that carry the right tone for each state. */
export const INVOICE_TONE: Record<InvoiceStatus, string> = {
  open: "open",
  paid: "closed",
  overdue: "overdue",
  void: "archived",
};
