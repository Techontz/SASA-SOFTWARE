"use client";

import { useQuery } from "@tanstack/react-query";
import { apiRequest } from "./client";
import { clean, useApiMutation, type Query } from "./hooks";
import type { Paginated } from "@/types/api";
import type {
  Currency,
  Payment,
  PaymentMethod,
  Plan,
  PlanInput,
  SubscriptionInput,
  SubscriptionRequest,
  SubscriptionStatus,
} from "@/types/billing";

/* ------------------------------------------------------------ public */

/* Public endpoints carry no project: plans and subscriptions belong to the
   platform, not to a tenant. */
const PUBLIC = { withoutProject: true, allowUnauthenticated: true } as const;

export function usePublicPlans() {
  return useQuery({
    queryKey: ["public-plans"],
    queryFn: () => apiRequest<{ data: Plan[] }>("/public/plans", PUBLIC),
    staleTime: 5 * 60_000,
  });
}

export function createSubscription(body: SubscriptionInput) {
  return apiRequest<{ data: SubscriptionRequest }>("/public/subscriptions", { ...PUBLIC, method: "POST", body });
}

export function fetchSubscription(publicId: string) {
  return apiRequest<{ data: SubscriptionRequest }>(`/public/subscriptions/${publicId}`, PUBLIC);
}

export function startPayment(publicId: string, body: { method: PaymentMethod; phone?: string }) {
  return apiRequest<{ data: Payment }>(`/public/subscriptions/${publicId}/pay`, { ...PUBLIC, method: "POST", body });
}

export function fetchPayment(publicId: string) {
  return apiRequest<{ data: Payment }>(`/public/payments/${publicId}`, PUBLIC);
}

export function completeSandboxPayment(publicId: string, outcome: "succeeded" | "failed") {
  return apiRequest<{ data: Payment }>(`/public/payments/${publicId}/sandbox-complete`, {
    ...PUBLIC,
    method: "POST",
    body: { outcome },
  });
}

/* ------------------------------------------------------------ admin */

const ADMIN = { withoutProject: true } as const;

export function useAdminPlans(includeArchived: boolean) {
  return useQuery({
    queryKey: ["admin-plans", includeArchived],
    queryFn: () =>
      apiRequest<{ data: Plan[] }>("/admin/plans", { ...ADMIN, query: { include_archived: includeArchived ? 1 : undefined } }),
  });
}

export function useSavePlan() {
  return useApiMutation(
    ({ id, body }: { id: number | null; body: PlanInput }) =>
      id
        ? apiRequest<{ data: Plan }>(`/admin/plans/${id}`, { ...ADMIN, method: "PATCH", body })
        : apiRequest<{ data: Plan }>("/admin/plans", { ...ADMIN, method: "POST", body }),
    ["admin-plans", "public-plans"],
  );
}

export function useArchivePlan() {
  return useApiMutation(
    (id: number) => apiRequest<unknown>(`/admin/plans/${id}`, { ...ADMIN, method: "DELETE" }),
    ["admin-plans", "public-plans"],
  );
}

export function useRestorePlan() {
  return useApiMutation(
    (id: number) => apiRequest<unknown>(`/admin/plans/${id}/restore`, { ...ADMIN, method: "POST" }),
    ["admin-plans", "public-plans"],
  );
}

export function useAdminSubscriptions(query: Query = {}) {
  return useQuery({
    queryKey: ["admin-subscriptions", clean(query)],
    queryFn: () => apiRequest<Paginated<SubscriptionRequest>>("/admin/subscriptions", { ...ADMIN, query: clean(query) }),
  });
}

export function useUpdateSubscription() {
  return useApiMutation(
    ({ publicId, status, admin_notes }: { publicId: string; status: SubscriptionStatus; admin_notes: string | null }) =>
      apiRequest<{ data: SubscriptionRequest }>(`/admin/subscriptions/${publicId}`, {
        ...ADMIN,
        method: "PATCH",
        body: { status, admin_notes },
      }),
    ["admin-subscriptions"],
  );
}

/* ------------------------------------------------------------ formatting */

export function planPrice(plan: Plan, currency: Currency): number | null {
  return currency === "USD" ? plan.price_usd : plan.price_tzs;
}

export function formatMoney(amount: number | null | undefined, currency: Currency): string {
  if (amount === null || amount === undefined) return "";
  return new Intl.NumberFormat(currency === "USD" ? "en-US" : "en-TZ", {
    style: "currency",
    currency,
    maximumFractionDigits: currency === "TZS" ? 0 : 2,
    minimumFractionDigits: 0,
  }).format(amount);
}
