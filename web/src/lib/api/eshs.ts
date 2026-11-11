"use client";

import { useQuery } from "@tanstack/react-query";
import { apiRequest } from "./client";
import { clean, detail, list, useApiMutation, type Query } from "./hooks";
import type { Attachment } from "@/types/api";
import type { EshsAnomaly, EshsAnomalyInput, EshsOptions, EshsSettings, EshsSummary } from "@/types/eshs";

/*
 * ESHS assurance is online-only for now: every write goes straight to the
 * server through apiRequest, so "saved" always means saved on the server.
 */

export const useEshsAnomalies = list<EshsAnomaly>("/eshs", "eshs-anomalies");
export const useEshsAnomaly = detail<EshsAnomaly>((id) => `/eshs/${id}`, "eshs-anomaly");

export function useEshsSummary(query: Query = {}, enabled = true) {
  return useQuery({
    enabled,
    queryKey: ["eshs-summary", clean(query)],
    queryFn: () => apiRequest<{ data: EshsSummary }>("/eshs/summary", { query: clean(query) }),
  });
}

export function useEshsOptions(enabled = true) {
  return useQuery({
    enabled,
    queryKey: ["eshs-options"],
    queryFn: () => apiRequest<{ data: EshsOptions }>("/eshs/options"),
    staleTime: 5 * 60_000,
  });
}

export function useEshsSettings() {
  return useQuery({
    queryKey: ["eshs-settings"],
    queryFn: () => apiRequest<{ data: EshsSettings }>("/eshs/settings"),
  });
}

const INVALIDATE = ["eshs-anomalies", "eshs-anomaly", "eshs-summary"];

export function useRaiseEshsAnomaly() {
  return useApiMutation<{ data: EshsAnomaly }, EshsAnomalyInput>(
    (body) => apiRequest<{ data: EshsAnomaly }>("/eshs", { method: "POST", body }),
    INVALIDATE,
  );
}

export type EshsAction = "progress" | "submit" | "close" | "return" | "archive";

export function useEshsAction(id: number) {
  return useApiMutation<{ data: EshsAnomaly }, { action: EshsAction; body?: Record<string, string | null> }>(
    ({ action, body }) => apiRequest<{ data: EshsAnomaly }>(`/eshs/${id}/${action}`, { method: "POST", body: body ?? {} }),
    INVALIDATE,
  );
}

export function useUpdateEshsSettings() {
  return useApiMutation<{ data: EshsSettings }, EshsSettings>(
    (body) => apiRequest<{ data: EshsSettings }>("/eshs/settings", { method: "PUT", body }),
    ["eshs-settings", "eshs-options"],
  );
}

/** Straight to the server — no device queue — so success means it is on the server. */
export async function uploadEshsFile(anomalyId: number, file: File, kind: "evidence" | "closure_evidence"): Promise<Attachment> {
  const form = new FormData();
  form.append("attachable_type", "eshs_anomaly");
  form.append("attachable_id", String(anomalyId));
  form.append("kind", kind);
  form.append("file", file);

  const response = await apiRequest<{ data: Attachment }>("/attachments", { method: "POST", formData: form });
  return response.data;
}
