import type { Attachment } from "@/types/api";

export type IncidentStatus = "submitted" | "under_review" | "closed" | "archived";

export interface IncidentCategoryOption {
  key: string;
  label: string;
}

export interface IncidentTypeOption {
  key: string;
  label: string;
  /** The category this type naturally belongs to, or null when it spans several. */
  category: string | null;
  serious: boolean;
}

export interface ConsequenceLevelOption {
  level: number;
  label: string;
}

export interface IncidentOptions {
  categories: IncidentCategoryOption[];
  types: IncidentTypeOption[];
  consequence_levels: ConsequenceLevelOption[];
  serious_potential_level: number;
  companies: string[];
  contractor_managers: string[];
  resident_engineers: string[];
  statuses: IncidentStatus[];
}

export interface Incident {
  id: number;
  reference: string;
  occurred_at: string | null;
  company: string | null;
  location: { id: number | null; path?: string | null };
  site_detail: string | null;
  category: string;
  category_label: string | null;
  incident_type: string;
  incident_type_label: string | null;
  actual_consequence: number;
  actual_consequence_label: string | null;
  potential_consequence: number;
  potential_consequence_label: string | null;
  is_serious: boolean;
  summary: string;
  reported_by?: { id: number; name: string } | null;
  contractor_manager: string | null;
  resident_engineer: string | null;
  status: IncidentStatus;
  status_label: string;
  /** Present only for people who hold incident.manage. */
  review_note?: string | null;
  reviewed_at: string | null;
  reviewed_by?: string | null;
  closed_at: string | null;
  closed_by?: string | null;
  attachments?: Attachment[];
  client_uuid: string | null;
  archived_at: string | null;
  created_at: string | null;
  updated_at: string | null;
  permissions: { can_manage: boolean; can_add_evidence: boolean };
}

export interface IncidentCount {
  key: string;
  label: string;
  count: number;
}

export interface IncidentLevelCount {
  level: number;
  label: string;
  count: number;
}

export interface IncidentSummary {
  total: number;
  open: number;
  serious: number;
  serious_open: number;
  last_30_days: number;
  near_misses: number;
  near_miss_ratio: number | null;
  by_status: IncidentCount[];
  by_category: IncidentCount[];
  by_actual_consequence: IncidentLevelCount[];
  by_potential_consequence: IncidentLevelCount[];
  definitions: { serious: string; near_miss_ratio: string; last_30_days: string };
}

export interface IncidentListsInput {
  companies?: string[];
  contractor_managers?: string[];
  resident_engineers?: string[];
}
