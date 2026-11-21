"use client";

import { useQuery } from "@tanstack/react-query";
import { apiRequest } from "./client";
import { clean, useApiMutation, type Query } from "./hooks";
import type { Paginated } from "@/types/api";

export type EnquiryType = "interest" | "demo" | "quote";
export type EnquiryStatus = "new" | "contacted" | "closed";

export interface EnquiryInput {
  type: EnquiryType;
  full_name: string;
  email: string;
  organization: string;
  sector: string;
  phone?: string;
  modules?: string[];
  message?: string;
  /** Left empty by people; filled by bots. */
  website?: string;
  /* Quote requests only. */
  company_size?: string;
  projects_count?: number;
  users_count?: number;
  project_location?: string;
  project_stage?: string;
  start_timeframe?: string;
}

export interface QuoteDetails {
  company_size?: string;
  projects_count?: number;
  users_count?: number;
  project_location?: string | null;
  project_stage?: string | null;
  start_timeframe?: string | null;
}

export interface ContactDetails {
  email: string | null;
  phone: string | null;
  whatsapp: string | null;
}

export function usePublicContact() {
  return useQuery({
    queryKey: ["public-contact"],
    queryFn: () => apiRequest<{ data: ContactDetails }>("/public/contact", { withoutProject: true, allowUnauthenticated: true }),
    staleTime: 10 * 60_000,
    retry: false,
  });
}

export interface Enquiry {
  id: number;
  public_id: string;
  reference: string;
  type: EnquiryType;
  full_name: string;
  email: string;
  organization: string;
  sector: string;
  phone: string | null;
  modules: string[] | null;
  message: string | null;
  status: EnquiryStatus;
  admin_notes: string | null;
  handled_by: { id: number; name: string } | null;
  handled_at: string | null;
  created_at: string;
  details?: QuoteDetails | null;
  /** The latest demo workspace given for this enquiry. */
  demo?: { public_id: string; status: "active" | "expired" | "ended" | "converted"; ends_at: string } | null;
}

export function submitEnquiry(body: EnquiryInput) {
  return apiRequest<{ data: { reference: string; type: EnquiryType } }>("/public/enquiries", {
    method: "POST",
    body,
    withoutProject: true,
    allowUnauthenticated: true,
  });
}

export function useEnquiries(query: Query = {}) {
  return useQuery({
    queryKey: ["admin-enquiries", clean(query)],
    queryFn: () => apiRequest<Paginated<Enquiry>>("/admin/enquiries", { withoutProject: true, query: clean(query) }),
  });
}

export function useUpdateEnquiry() {
  return useApiMutation(
    ({ publicId, status, admin_notes }: { publicId: string; status: EnquiryStatus; admin_notes: string | null }) =>
      apiRequest<{ data: Enquiry }>(`/admin/enquiries/${publicId}`, {
        method: "PATCH",
        withoutProject: true,
        body: { status, admin_notes },
      }),
    ["admin-enquiries"],
  );
}
