"use client";

import { useQuery } from "@tanstack/react-query";
import { apiRequest } from "./client";
import { detail, list, useApiMutation } from "./hooks";
import type { Incident, IncidentListsInput, IncidentOptions, IncidentSummary } from "@/types/incident";

export const useIncidents = list<Incident>("/incidents", "incidents");
export const useIncident = detail<Incident>((id) => `/incidents/${id}`, "incident");

export function useIncidentSummary() {
  return useQuery({
    queryKey: ["incident-summary"],
    queryFn: () => apiRequest<{ data: IncidentSummary }>("/incidents/summary"),
  });
}

export function useIncidentOptions() {
  return useQuery({
    queryKey: ["incident-options"],
    queryFn: () => apiRequest<{ data: IncidentOptions }>("/incidents/options"),
    staleTime: 5 * 60_000,
  });
}

export interface IncidentInput {
  client_uuid: string;
  occurred_at: string;
  company: string | null;
  location_id: number | null;
  site_detail: string | null;
  category: string;
  incident_type: string;
  actual_consequence: number;
  potential_consequence: number;
  summary: string;
  contractor_manager: string | null;
  resident_engineer: string | null;
}

export function useReportIncident() {
  return useApiMutation(
    (body: IncidentInput) => apiRequest<{ data: Incident }>("/incidents", { method: "POST", body: { ...body } }),
    ["incidents", "incident-summary"],
  );
}

export function useIncidentStatus(id: number) {
  return useApiMutation(
    (body: { status: "under_review" | "closed"; review_note: string | null }) =>
      apiRequest<{ data: Incident }>(`/incidents/${id}/status`, { method: "POST", body: { ...body } }),
    ["incidents", "incident", "incident-summary"],
  );
}

export function useArchiveIncident(id: number) {
  return useApiMutation(
    () => apiRequest<{ message: string }>(`/incidents/${id}`, { method: "DELETE" }),
    ["incidents", "incident", "incident-summary"],
  );
}

export function useUpdateIncidentLists() {
  return useApiMutation(
    (body: IncidentListsInput) =>
      apiRequest<{ data: IncidentOptions }>("/incidents/settings", { method: "PUT", body: { ...body } }),
    ["incident-options"],
  );
}
