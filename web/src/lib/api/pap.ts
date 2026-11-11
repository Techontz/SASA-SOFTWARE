"use client";

import { useQuery } from "@tanstack/react-query";
import { apiRequest } from "@/lib/api/client";
import { clean, detail, list, type Query } from "@/lib/api/hooks";
import type {
  Pap,
  PapConsultation,
  PapDetailMeta,
  PapGrievance,
  PapOptionsPayload,
  PapRecord,
  PapSectionKey,
  PapSummary,
} from "@/types/pap";

/** The PAP register is online-only: these call the API directly, never the offline queue. */

export const usePaps = list<Pap>("/paps", "paps");
export const usePapConsultations = list<PapConsultation>("/pap-consultations", "pap-consultations");
export const usePapConsultation = detail<PapConsultation>((id) => `/pap-consultations/${id}`, "pap-consultation");

export function usePap(id: number | null) {
  return useQuery({
    queryKey: ["pap", id],
    queryFn: () => apiRequest<{ data: Pap; meta: PapDetailMeta }>(`/paps/${id}`),
    enabled: Boolean(id),
  });
}

export function usePapOptions() {
  return useQuery({
    queryKey: ["pap-options"],
    queryFn: () => apiRequest<{ data: PapOptionsPayload }>("/paps/options"),
    staleTime: 10 * 60_000,
  });
}

export function usePapSummary() {
  return useQuery({
    queryKey: ["pap-summary"],
    queryFn: () => apiRequest<{ data: PapSummary }>("/paps/summary"),
  });
}

export function usePapRecords(papId: number | null, section: PapSectionKey, query: Query = {}) {
  return useQuery({
    queryKey: ["pap-records", papId, section, clean(query)],
    queryFn: () => apiRequest<{ data: PapRecord[] }>(`/paps/${papId}/${section}`, { query: clean(query) }),
    enabled: Boolean(papId),
  });
}

export function usePapGrievances(papId: number | null) {
  return useQuery({
    queryKey: ["pap-grievances", papId],
    queryFn: () => apiRequest<{ data: PapGrievance[]; meta: { can_view: boolean } }>(`/paps/${papId}/grievances`),
    enabled: Boolean(papId),
  });
}
