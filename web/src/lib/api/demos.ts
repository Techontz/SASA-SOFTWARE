"use client";

import { useQuery } from "@tanstack/react-query";
import { apiRequest } from "./client";
import { clean, useApiMutation, type Query } from "./hooks";
import type { Paginated } from "@/types/api";

/* A time-limited demo workspace, set up for a company in one action. */

export type DemoStatus = "active" | "expired" | "ended" | "converted";

export interface Demo {
  public_id: string;
  status: DemoStatus;
  starts_at: string;
  ends_at: string;
  organisation: { id: number; name: string };
  project: { id: number; name: string; code: string };
  user: { id: number; name: string; email: string };
  enquiry: { public_id: string; reference: string } | null;
  /** Only when the demo is created or the link is sent again. */
  invite_url?: string;
  email_sent?: boolean;
  created_at: string;
}

export interface DemoInput {
  days: number;
  sample_data: boolean;
  name?: string;
  email?: string;
  project_name?: string;
}

const PLATFORM = { withoutProject: true } as const;
const REFRESH = ["admin-demos", "admin-enquiries"];

export function useDemos(query: Query = {}) {
  return useQuery({
    queryKey: ["admin-demos", clean(query)],
    queryFn: () => apiRequest<Paginated<Demo>>("/admin/demos", { ...PLATFORM, query: clean(query) }),
  });
}

export function useGiveDemo() {
  return useApiMutation(
    ({ enquiryId, body }: { enquiryId: string; body: DemoInput }) =>
      apiRequest<{ data: Demo }>(`/admin/enquiries/${enquiryId}/demo`, { ...PLATFORM, method: "POST", body }),
    REFRESH,
  );
}

export function useExtendDemo() {
  return useApiMutation(
    ({ publicId, days }: { publicId: string; days: number }) =>
      apiRequest<{ data: Demo }>(`/admin/demos/${publicId}/extend`, { ...PLATFORM, method: "POST", body: { days } }),
    REFRESH,
  );
}

export function useEndDemo() {
  return useApiMutation(
    (publicId: string) => apiRequest<{ data: Demo }>(`/admin/demos/${publicId}/end`, { ...PLATFORM, method: "POST" }),
    REFRESH,
  );
}

export function useResendInvite() {
  return useApiMutation(
    (publicId: string) => apiRequest<{ data: Demo }>(`/admin/demos/${publicId}/resend-invite`, { ...PLATFORM, method: "POST" }),
    REFRESH,
  );
}

export const DEMO_STATUS: Record<DemoStatus, string> = {
  active: "Running",
  expired: "Expired",
  ended: "Ended",
  converted: "Became a customer",
};
