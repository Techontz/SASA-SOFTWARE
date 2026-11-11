import type { Attachment, Kpi } from "@/types/api";

export type EshsStatus = "open" | "in_progress" | "closed";
export type EshsStage = "open" | "in_progress" | "awaiting_verification" | "closed";
export type EshsRisk = "low" | "medium" | "high" | "critical";

export interface EshsHistoryEntry {
  id: number;
  action: string;
  summary: string | null;
  user: string | null;
  at: string | null;
  detail: string | null;
}

export interface EshsAnomaly {
  id: number;
  reference: string;
  raised_on: string | null;
  location: { id: number | null; path?: string | null };
  contractor: string;
  subcontractor: string | null;
  category: string;
  category_label: string | null;
  subcategory: string;
  description: string;
  anomaly_type: string;
  risk_rating: EshsRisk;
  risk_label: string;
  root_cause_group: string | null;
  root_cause: string | null;
  contractor_action: string | null;
  action_owner_name: string | null;
  /** Absent for read-only viewers — never null-for-hidden. */
  action_owner_email?: string | null;
  action_owner_phone?: string | null;
  target_date: string | null;
  days_until_target: number | null;
  is_overdue: boolean;
  progress_notes: string | null;
  status: EshsStatus;
  status_label: string;
  stage: EshsStage;
  stage_label: string;
  evidence_submitted_at: string | null;
  closure_notes: string | null;
  returned_at: string | null;
  return_reason: string | null;
  closed_on: string | null;
  closed_by?: { id: number; name: string } | null;
  verification_notes: string | null;
  raised_by?: { id: number; name: string } | null;
  attachments?: Attachment[];
  history?: EshsHistoryEntry[];
  can?: { edit: boolean; upload: boolean; respond: boolean; verify: boolean; archive: boolean };
  archived_at: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface EshsCategoryOption {
  key: string;
  label: string;
  subcategories: string[];
}

export interface EshsOptions {
  contractors: string[];
  subcontractors: string[];
  categories: EshsCategoryOption[];
  anomaly_types: string[];
  risk_ratings: Array<{ key: EshsRisk; label: string }>;
  root_causes: Array<{ group: string; causes: string[] }>;
  statuses: Array<{ key: EshsStage; label: string }>;
}

export interface EshsContractorPerformance {
  contractor: string;
  raised: number;
  open: number;
  overdue: number;
  closed: number;
  critical_open: number;
  closure_rate: number | null;
  avg_days_to_close: number | null;
}

export interface EshsSummary {
  kpis: Kpi[];
  totals: {
    total: number;
    open: number;
    in_progress: number;
    awaiting_verification: number;
    overdue: number;
    closed: number;
    critical_open: number;
    high_open: number;
    closure_rate: number | null;
    avg_days_to_close: number | null;
  };
  by_category: Array<{ category: string; label: string | null; total: number; open: number; closed: number }>;
  by_risk: Array<{ risk_rating: EshsRisk; label: string; total: number; open: number }>;
  contractor_performance: EshsContractorPerformance[];
  definitions: Record<string, string>;
}

export interface EshsSettings {
  contractors: string[];
  subcontractors: string[];
}

export interface EshsAnomalyInput {
  raised_on: string;
  location_id: number | null;
  contractor: string;
  subcontractor: string | null;
  category: string;
  subcategory: string;
  description: string;
  anomaly_type: string;
  risk_rating: string;
  root_cause_group: string | null;
  root_cause: string | null;
  contractor_action: string | null;
  action_owner_name: string | null;
  action_owner_email: string | null;
  action_owner_phone: string | null;
  target_date: string | null;
}
