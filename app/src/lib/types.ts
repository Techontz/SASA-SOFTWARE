/**
 * The parts of the SASA API this app reads. Field names follow the API
 * resources exactly (backend/app/Http/Resources); anything the app does not
 * use is left out rather than typed loosely.
 */

export interface Role {
  key: string;
  name: string;
  description?: string | null;
}

export interface ProjectMembership {
  id: number;
  name: string;
  code: string;
  country: string | null;
  sector: string | null;
  status: string;
  is_default: boolean;
  role: Role;
  permissions: string[];
  handling_groups: string[];
}

export interface CurrentUser {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  job_title: string | null;
  is_system_admin: boolean;
  organisation: { id: number; name: string } | null;
  projects: ProjectMembership[];
}

export interface LoginResponse {
  token: string;
  expires_at: string | null;
  user: CurrentUser;
}

export interface PageMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface Paginated<T> {
  data: T[];
  meta: PageMeta;
}

export interface CriticalAction {
  kind: string;
  severity: "danger" | "warning" | "info" | string;
  label: string;
  href: string | null;
  count: number;
}

export interface Kpi {
  key: string;
  label: string;
  unit: string;
  value: number | null;
}

export interface LandingDashboard {
  role: string;
  question: string;
  kpis: Kpi[];
  critical_actions: CriticalAction[];
}

export interface LocationRef {
  id: number | null;
  path?: string | null;
  display?: string | null;
  text?: string | null;
}

export interface Grievance {
  id: number;
  reference: string;
  title: string | null;
  description: string;
  desired_resolution: string | null;
  channel: string;
  channel_label: string;
  received_at: string | null;
  occurred_at: string | null;
  confidentiality: "normal" | "confidential" | "anonymous";
  is_restricted: boolean;
  is_anonymous: boolean;
  identity_visible: boolean;
  identity_withheld_reason: string | null;
  status: string;
  status_label: string;
  severity: number | null;
  severity_label: string | null;
  category: { id: number; name: string } | null;
  location: LocationRef | null;
  location_text: string | null;
  complainant_type: string | null;
  complainant_language: string | null;
  preferred_contact_method: string | null;
  assigned_to: { id: number; name: string } | null;
  assigned_team: string | null;
  acknowledged_at: string | null;
  resolution_summary: string | null;
  resolved_at: string | null;
  closed_at: string | null;
  reopen_count: number;
  sla: {
    acknowledgement_due_at: string | null;
    acknowledgement_state: string | null;
    resolution_due_at: string | null;
    resolution_state: string | null;
  };
  days_open: number | null;
  created_at: string;
  /** Present only when the viewer may see who raised the case. */
  complainant?: { name: string | null; phone: string | null; email: string | null; address: string | null };
  stakeholder?: { id: number; reference: string; name: string } | null;
  follow_ups?: { id: number; type: string | null; body: string | null; author?: string | null; created_at: string | null }[];
  assignments?: { id: number; assigned_to: string | null; assigned_team: string | null; assigned_by: string | null; assigned_at: string | null; reason: string | null }[];
  cycles?: {
    cycle_number: number;
    opened_at: string | null;
    reopen_reason: string | null;
    resolution_summary: string | null;
    resolved_at: string | null;
    complainant_response: string | null;
    closed_at: string | null;
  }[];
  communications?: { id: number; direction: string; channel: string; template_key: string | null; status: string; sent_at: string | null; sent_by: string | null }[];
  escalations?: { id: number; to_level: number; reason: string | null; escalated_to: string | null; escalated_at: string | null }[];
}

export interface Stakeholder {
  id: number;
  reference: string;
  name: string;
  type: string;
  type_label: string;
  organisation_name: string | null;
  position: string | null;
  phone: string | null;
  email: string | null;
  preferred_language: string | null;
  preferred_contact_method: string | null;
  physical_address: string | null;
  location: LocationRef | null;
  assessment: {
    influence: string | null;
    interest: string | null;
    power: string | null;
    impact: string | null;
    score: number | null;
    calculated_priority: string | null;
    stored_priority: string | null;
  } | null;
  engagement_strategy: string | null;
  communication_frequency: string | null;
  concerns_expectations: string | null;
  notes: string | null;
  is_vulnerable: boolean;
  vulnerability_categories: string[];
  consent_status: string | null;
  status: string;
  review_date: string | null;
  review_due: boolean;
  last_engaged_on: string | null;
  owner: { id: number; name: string } | null;
  counts?: { engagements: number; concerns: number; grievances: number; commitments: number };
}

export interface TimelineItem {
  type: string;
  id: number;
  reference: string;
  title: string;
  subtitle: string | null;
  status: string;
  at: string | null;
}

export interface Engagement {
  id: number;
  reference: string;
  topic: string;
  project_phase: string | null;
  held_at: string | null;
  method: string | null;
  venue: string | null;
  organised_by: string | null;
  aim: string | null;
  discussion_points: string | null;
  outcomes: string | null;
  status: string;
  location: LocationRef | null;
  attendance: {
    total: number | null;
    female: number | null;
    male: number | null;
    youth: number | null;
    elderly: number | null;
    disability: number | null;
    vulnerable: number | null;
  };
  planned_vs_actual: string | null;
  planned_vs_actual_label: string | null;
  plan: { id: number; reference: string; title?: string | null } | null;
  facilitator: { id: number; name: string } | null;
  stakeholders?: { id: number; reference: string; name: string; type?: string }[];
  counts?: { concerns: number; commitments: number; participants: number; attachments: number };
}

export interface EngagementPlan {
  id: number;
  reference: string;
  title: string;
  target_date: string | null;
  status: string;
  method: string | null;
  project_phase: string | null;
  location: LocationRef | null;
}

export interface Commitment {
  id: number;
  reference: string;
  commitment_text: string;
  due_date: string | null;
  days_until_due: number | null;
  is_overdue: boolean;
  risk_level: string | null;
  status: string;
  status_label: string;
  owner: { id: number; name: string } | null;
  stakeholders: { id: number; reference: string; name: string }[];
}

export interface DisaggregationDimension {
  key: string;
  label: string;
  enabled: boolean;
  options: string[];
}

export interface Bootstrap {
  project: { id: number; name: string; code: string };
  locations: { id: number; parent_id: number | null; level: string; name: string; path: string }[];
  members: { id: number; name: string; role: string }[];
  lists: {
    stakeholder_types: string[];
    engagement_methods: string[];
    project_phases: string[];
    languages: string[];
    disaggregation_dimensions: DisaggregationDimension[];
  };
  required_fields: Record<string, string[]>;
  stakeholder_types: string[];
  channels: string[];
}
