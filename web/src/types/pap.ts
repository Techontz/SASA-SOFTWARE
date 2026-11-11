import type { Attachment } from "@/types/api";

/** PAP Master Register — shapes returned by /paps and /pap-consultations. */

export interface PapOption {
  value: string;
  label: string;
}

export interface PapOptionsPayload {
  currency: string;
  lists: Record<string, PapOption[]>;
}

export interface Pap {
  id: number;
  reference: string;
  household_id: string | null;
  project_name: string | null;
  full_name: string;
  /** Present only for viewers with pap.view_sensitive. */
  national_id?: string | null;
  sensitive_withheld: boolean;
  gender: string | null;
  gender_label: string | null;
  date_of_birth: string | null;
  stated_age: number | null;
  age: number | null;
  age_is_derived: boolean;
  marital_status: string | null;
  marital_status_label: string | null;
  phone: string | null;
  alternative_contact: string | null;
  physical_address: string | null;
  location: {
    id: number | null;
    path?: string | null;
    region: string | null;
    district: string | null;
    ward: string | null;
    village: string | null;
    kitongoji: string | null;
    display: string;
  };
  gps: { latitude: number | null; longitude: number | null };
  next_of_kin: string | null;
  next_of_kin_contact: string | null;
  vulnerability_category: string | null;
  vulnerability_label: string | null;
  is_vulnerable: boolean;
  remarks: string | null;
  status: string;
  stakeholder: { id: number; reference?: string | null } | null;
  photo?: Attachment | null;
  attachments?: Attachment[];
  money?: { amount_paid: number; outstanding: number };
  client_uuid: string | null;
  captured_at: string | null;
  archived_at: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface PapDetailMeta {
  totals: {
    approved_valuation: number | null;
    entitlements_value: number;
    crop_compensation: number;
    affected_land_ha: number;
    amount_approved: number;
    amount_paid: number;
    outstanding: number;
  };
  counts: Record<PapSectionKey, number>;
}

export type PapSectionKey =
  | "household-surveys"
  | "land"
  | "assets"
  | "crops"
  | "valuations"
  | "entitlements"
  | "payments"
  | "relocations"
  | "livelihoods"
  | "documents";

export interface MonitoringVisit {
  visit_date: string;
  visited_by: string | null;
  findings: string | null;
}

export type PapRecordValue = string | number | boolean | null | MonitoringVisit[] | undefined;

/** A child record of any section. Field keys vary by section. */
export interface PapRecord {
  id: number;
  section: PapSectionKey;
  reference: string | null;
  pap_id: number;
  status: string;
  sensitive_withheld: boolean;
  archived_at: string | null;
  created_at: string | null;
  updated_at: string | null;
  [field: string]: PapRecordValue;
}

export interface PapKpi {
  key: string;
  label: string;
  definition: string;
  unit: "count" | "percent" | "currency";
  value: number | null;
  delta_percent: number | null;
  context: Record<string, number | string>;
  drill: string | null;
}

export interface PapSummary {
  currency: string;
  kpis: PapKpi[];
}

export interface PapGrievance {
  id: number;
  reference: string;
  title: string | null;
  date_logged: string | null;
  category: string | null;
  status: string;
  status_label: string;
  is_open: boolean;
  assigned_officer: string | null;
  investigation_findings: string | null;
  resolution_agreed: string | null;
  resolution_date: string | null;
  closure_date: string | null;
  comments: string | null;
  href: string;
}

export interface PapConsultation {
  id: number;
  reference: string;
  consultation_date: string | null;
  location: { id: number | null; path?: string | null };
  village: string | null;
  meeting_type: string | null;
  meeting_type_label: string | null;
  topic_discussed: string;
  facilitator: string | null;
  number_male: number;
  number_female: number;
  number_vulnerable: number;
  total_attendance: number;
  attendance_sheet_reference: string | null;
  key_concerns_raised: string | null;
  actions_agreed: string | null;
  status: string;
  archived_at: string | null;
  created_at: string | null;
  updated_at: string | null;
}

/** The stakeholder resource carries this when the record is a PAP's mirror. */
export interface StakeholderPapLink {
  managed_by_pap?: { id: number; reference: string } | null;
}
