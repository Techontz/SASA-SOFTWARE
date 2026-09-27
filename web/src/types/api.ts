/* Typed shapes for the SASA API. `any` is not used anywhere in this file. */

export interface Paginated<T> {
  data: T[];
  links?: { first?: string; last?: string; prev?: string | null; next?: string | null };
  meta?: {
    current_page: number;
    from: number | null;
    last_page: number;
    per_page: number;
    to: number | null;
    total: number;
    summary?: Record<string, number | null>;
  };
}

export interface Envelope<T> {
  data: T;
  meta?: Record<string, unknown>;
  message?: string;
}

export interface ApiError {
  error: string;
  message: string;
  request_id?: string;
  errors?: Record<string, string[]>;
  required_permission?: string[];
}

// ---------------------------------------------------------------- identity

export interface RoleSummary {
  key: string;
  name: string;
  description?: string;
}

export interface ProjectMembershipSummary {
  id: number;
  name: string;
  code: string;
  country: string | null;
  sector: string | null;
  status: string;
  is_default: boolean;
  role: RoleSummary;
  permissions: string[];
  handling_groups: string[];
}

export interface CurrentUser {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  job_title: string | null;
  locale: string;
  is_system_admin: boolean;
  mfa_enabled: boolean;
  organisation: { id: number; name: string; brand_color: string | null } | null;
  notification_preferences: { muted?: boolean; muted_events?: string[] };
  projects: ProjectMembershipSummary[];
}

export interface LoginResponse {
  token: string;
  expires_at: string | null;
  user: CurrentUser;
}

// ------------------------------------------------------------- stakeholders

export interface StakeholderAssessment {
  influence: string | null;
  interest: string | null;
  power: string | null;
  impact: string | null;
  score: number | null;
  calculated_priority: string | null;
  stored_priority: string | null;
  is_overridden: boolean;
  explanation?: string;
  override_reason?: string | null;
}

export interface Stakeholder {
  id: number;
  reference: string;
  name: string;
  alias: string | null;
  type: string;
  type_label: string;
  sub_type: string | null;
  organisation_name: string | null;
  position: string | null;
  phone: string | null;
  alternate_phone: string | null;
  email: string | null;
  preferred_language: string | null;
  preferred_contact_method: string | null;
  physical_address: string | null;
  postal_address: string | null;
  location: {
    id: number | null;
    path?: string | null;
    country: string | null;
    region: string | null;
    district: string | null;
    ward: string | null;
    village: string | null;
    display: string;
    latitude: number | null;
    longitude: number | null;
  };
  assessment: StakeholderAssessment;
  engagement_strategy: string | null;
  communication_frequency: string | null;
  concerns_expectations: string | null;
  notes: string | null;
  is_vulnerable: boolean;
  vulnerability_categories: string[];
  is_indigenous_or_minority: boolean;
  consent_status: string | null;
  consent_basis: string | null;
  consent_date: string | null;
  identification_source: string | null;
  identification_method: string | null;
  demographics: Record<string, string> | null;
  custom_fields: Record<string, unknown> | null;
  status: string;
  review_date: string | null;
  review_due: boolean;
  last_engaged_on: string | null;
  owner: { id: number; name: string } | null;
  client_uuid: string | null;
  captured_at: string | null;
  synced_at: string | null;
  archived_at: string | null;
  created_at: string | null;
  updated_at: string | null;
  counts?: Record<string, number | undefined>;
  contacts?: Array<{ id: number; name: string; role: string | null; phone: string | null; email: string | null; is_primary: boolean }>;
  assessments?: Array<{
    id: number;
    score: number;
    calculated_priority: string;
    stored_priority: string;
    is_override: boolean;
    previous_priority: string | null;
    override_reason: string | null;
    weights: Record<string, number>;
    assessed_by: string | null;
    assessed_at: string | null;
  }>;
  attachments?: Attachment[];
}

// -------------------------------------------------------------- engagement

export interface EngagementPlan {
  id: number;
  reference: string;
  title: string;
  project_phase: string | null;
  purpose: string | null;
  method: string | null;
  target_date: string | null;
  window_start: string | null;
  window_end: string | null;
  status: string;
  status_label: string;
  status_reason: string | null;
  priority: string;
  recurrence: string;
  recurrence_until: string | null;
  stakeholder: { id: number; reference: string; name: string } | null;
  stakeholder_group: string | null;
  location: { id: number | null; path?: string | null; text: string | null };
  vulnerable_group_accommodation: boolean;
  accommodation_notes: string | null;
  fpic_required: boolean;
  fpic_notes: string | null;
  grievance_channel_available: boolean;
  owner: { id: number; name: string } | null;
  responsible_team: string | null;
  budget_amount: number | null;
  budget_currency: string | null;
  resources_required: string | null;
  engagements?: Engagement[];
  counts?: { engagements?: number };
  client_uuid: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface EngagementParticipant {
  id?: number;
  stakeholder_id?: number | null;
  name: string | null;
  category: string | null;
  organisation_name?: string | null;
  position?: string | null;
  phone?: string | null;
  is_vulnerable?: boolean;
  demographics?: Record<string, string> | null;
  signed_attendance?: boolean;
}

export interface Engagement {
  id: number;
  reference: string;
  topic: string;
  project_phase: string | null;
  held_at: string | null;
  ended_at: string | null;
  method: string | null;
  venue: string | null;
  organised_by: string | null;
  aim: string | null;
  discussion_points: string | null;
  outcomes: string | null;
  status: string;
  location: { id: number | null; path?: string | null; text: string | null; latitude: number | null; longitude: number | null };
  attendance: {
    total: number; female: number; male: number; youth: number;
    elderly: number; disability: number; vulnerable: number;
    breakdown: Record<string, number> | null;
  };
  vulnerable_groups_present: boolean;
  vulnerable_groups: string[];
  planned_vs_actual: string;
  planned_vs_actual_label: string;
  variance_days: number | null;
  plan: { id: number; reference: string; title: string; target_date: string | null; status: string } | null;
  facilitator: { id: number; name: string } | null;
  stakeholders?: Array<{ id: number; reference: string; name: string; type: string }>;
  participants?: EngagementParticipant[];
  concerns?: Concern[];
  commitments?: Commitment[];
  attachments?: Attachment[];
  counts?: Record<string, number | undefined>;
  client_uuid: string | null;
  captured_at: string | null;
  synced_at: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface Concern {
  id: number;
  reference: string;
  title: string;
  description: string;
  raised_by: string | null;
  raised_on: string | null;
  severity_hint: string | null;
  status: string;
  response: string | null;
  engagement: { id: number; reference: string; topic: string; held_at: string | null } | null;
  stakeholder: { id: number; reference: string; name: string } | null;
  category: { id: number; name: string } | null;
  grievance: { id: number; reference: string; status: string } | null;
  grievance_id: number | null;
  escalated_at: string | null;
  location: { id: number | null; path?: string | null };
  owner: { id: number; name: string } | null;
  commitments?: Commitment[];
  attachments?: Attachment[];
  client_uuid: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface Commitment {
  id: number;
  reference: string;
  commitment_text: string;
  source_type: string;
  source_date: string | null;
  due_date: string | null;
  days_until_due: number | null;
  is_overdue: boolean;
  priority: string;
  risk_level: string;
  status: string;
  status_label: string;
  completed_on: string | null;
  evidence_notes: string | null;
  verification_status: string;
  verified_at: string | null;
  verified_by?: string | null;
  notes: string | null;
  owner: { id: number; name: string } | null;
  owner_team: string | null;
  engagement: { id: number; reference: string; topic: string; held_at: string | null } | null;
  grievance: { id: number; reference: string } | null;
  concern_id: number | null;
  stakeholders?: Array<{ id: number; reference: string; name: string }>;
  location: { id: number | null; path?: string | null };
  attachments?: Attachment[];
  client_uuid: string | null;
  created_at: string | null;
  updated_at: string | null;
}

// -------------------------------------------------------------- grievances

export interface GrievanceCategory {
  id: number;
  key: string;
  name: string;
  description?: string | null;
  default_severity: number | null;
  is_restricted: boolean;
  aggregate_reporting_only?: boolean;
  retired_at?: string | null;
  subcategories?: GrievanceCategory[];
}

export interface Grievance {
  id: number;
  reference: string;
  title: string;
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
  classification_confirmed: boolean;
  has_ai_suggestions: boolean;
  category: { id: number; name: string; is_restricted: boolean } | null;
  subcategory: { id: number; name: string } | null;
  location: { id: number; name: string; path: string | null; level: string } | null;
  location_text: string | null;
  complainant_type: string | null;
  complainant_language: string | null;
  preferred_contact_method: string | null;
  assigned_to: { id: number; name: string } | null;
  assigned_team: string | null;
  assigned_at: string | null;
  acknowledged_at: string | null;
  acknowledgement_method: string | null;
  acknowledgement_possible: boolean;
  acknowledgement_not_possible_reason: string | null;
  investigation_started_at: string | null;
  investigation_summary: string | null;
  investigation_findings: string | null;
  investigation_completed_at: string | null;
  corrective_action: string | null;
  corrective_action_due: string | null;
  resolution_summary: string | null;
  resolved_at: string | null;
  complainant_response: string | null;
  closed_at: string | null;
  closure_notes: string | null;
  resolution_cycle: number;
  reopen_count: number;
  last_reopened_at: string | null;
  escalation_level: number;
  sla: {
    acknowledgement_due_at: string | null;
    acknowledgement_state: string | null;
    resolution_due_at: string | null;
    resolution_state: string | null;
  };
  days_open: number;
  demographics: Record<string, string> | null;
  custom_fields: Record<string, unknown> | null;
  source_concern_id: number | null;
  voice_call_id: number | null;
  client_uuid: string | null;
  captured_at: string | null;
  synced_at: string | null;
  created_at: string | null;
  updated_at: string | null;
  counts?: Record<string, number | undefined>;

  /* Present ONLY for a viewer in the handling group. */
  complainant?: { name: string | null; phone: string | null; email: string | null; address: string | null };
  precise_location?: string | null;
  coordinates?: { latitude: number; longitude: number } | null;
  stakeholder?: { id: number; reference: string; name: string } | null;

  follow_ups?: GrievanceFollowUp[];
  assignments?: Array<{
    id: number; assigned_to: string | null; assigned_team: string | null;
    assigned_by: string | null; assigned_at: string | null; unassigned_at: string | null;
    reason: string | null; is_current: boolean;
  }>;
  cycles?: Array<{
    cycle_number: number; opened_at: string | null; reopen_reason: string | null;
    resolution_summary: string | null; resolved_at: string | null;
    complainant_response: string | null; closed_at: string | null;
  }>;
  escalations?: Array<{
    id: number; from_level: number; to_level: number; trigger: string;
    reason: string | null; escalated_to: string | null; escalated_at: string | null;
  }>;
  communications?: Array<{
    id: number; direction: string; channel: string; template_key: string | null;
    subject: string | null; body: string; status: string; sent_at: string | null; sent_by: string | null;
  }>;
  attachments?: Attachment[];
  ai_suggestions?: AiSuggestion[];
}

export interface GrievanceFollowUp {
  id: number;
  type: string;
  body: string;
  is_sensitive: boolean;
  occurred_on: string | null;
  resolution_cycle: number;
  author: string | null;
  attachments?: Attachment[];
  created_at: string | null;
}

export interface AiSuggestion {
  id: number;
  kind: string;
  suggestion: {
    category_id?: number | null;
    subcategory_id?: number | null;
    severity?: number | null;
    summary?: string | null;
    suggested_routing_role?: string | null;
    rationale?: string | null;
  };
  confidence: number | null;
  provider: string | null;
  status: "pending" | "accepted" | "modified" | "rejected";
  reviewed_by?: string | null;
  reviewed_at?: string | null;
}

export interface Attachment {
  id: number;
  kind: string;
  original_name: string;
  mime_type: string;
  size_bytes: number;
  size_label: string;
  caption: string | null;
  is_sensitive: boolean;
  is_image: boolean;
  scan_status: string;
  download_url: string;
  uploaded_by?: string | null;
  captured_at: string | null;
  created_at: string | null;
}

// -------------------------------------------------------------- dashboards

export interface Kpi {
  key: string;
  label: string;
  definition: string;
  unit: "count" | "percent" | "days" | "hours" | "ratio";
  value: number | null;
  delta_percent: number | null;
  context: Record<string, number | string>;
  drill: string | null;
}

export interface CriticalAction {
  kind: string;
  severity: "danger" | "warning" | "info";
  label: string;
  href: string;
  count: number;
}

export interface ActivityEntry {
  id: number;
  action: string;
  entity_type: string | null;
  entity_id: number | null;
  reference: string | null;
  summary: string | null;
  user: string | null;
  at: string | null;
}

export interface PeriodMeta {
  project_id: number;
  from: string;
  to: string;
  label: string;
  location_id: number | null;
  category_id: number | null;
  severity: number | null;
  channel: string | null;
  owner_id: number | null;
  project_phase: string | null;
}

export interface ExecutiveDashboard {
  period: PeriodMeta;
  kpis: Kpi[];
  trend: Array<{ bucket: string; received: number; closed: number }>;
  by_category: Array<{ category_id: number | null; category: string; total: number }>;
  by_location: Array<{ location_id: number | null; location: string; total: number }>;
  critical_actions: CriticalAction[];
  recent_activity: ActivityEntry[];
}

export interface LandingDashboard {
  role: string | null;
  question: string;
  primary_dashboard: string;
  period: PeriodMeta;
  kpis: Kpi[];
  critical_actions: CriticalAction[];
  recent_activity: ActivityEntry[];
  trend: Array<{ bucket: string; received: number; closed: number }>;
  my_work: Record<string, number>;
}

export interface TimelinessDashboard {
  period: PeriodMeta;
  kpis: Kpi[];
  clock_states: Record<string, Record<string, number>>;
  open_clocks: number;
  paused_clocks: number;
  paused_detail: Array<{ subject_id: number; clock: string; pause_reason: string | null; paused_at: string | null }>;
  by_clock: Array<{ clock: string; on_time: number; total: number; percent: number | null }>;
  by_category: SlaBreakdownRow[];
  by_severity: SlaBreakdownRow[];
  by_assignee: SlaBreakdownRow[];
  trend: Array<{ bucket: string; compliance_percent: number | null; total: number }>;
}

export interface SlaBreakdownRow {
  bucket: string;
  on_time: number;
  breached: number;
  total: number;
  compliance_percent: number | null;
}

export interface DisaggregationDashboard {
  period: PeriodMeta;
  dimension: string;
  against: string;
  minimum_cell_size: number;
  suppressed_cells: number;
  suppression_note: string;
  columns: string[];
  rows: Array<{ dimension_value: string; cells: Record<string, number | null>; total: number }>;
  uptake_equity: Array<{
    group: string;
    population_share_percent: number | null;
    grievance_share_percent: number | null;
    engagement_share_percent: number | null;
    grievance_gap: number | null;
    engagement_gap: number | null;
    note: string;
  }>;
}

export interface SeverityDashboard {
  period: PeriodMeta;
  kpis: Kpi[];
  distribution: Array<{ level: number; label: string; description: string; total: number }>;
  escalation_reasons: Array<{ trigger: string; total: number }>;
  open_critical: Array<{
    id: number; reference: string; title: string; severity: number | null;
    severity_label: string; status: string; category: string | null;
    assignee: string | null; days_open: number; resolution_sla_state: string | null;
  }>;
  time_to_escalation_days: number | null;
  resolution_status_by_severity: Array<{ severity: number; closed: number; open: number }>;
}

export interface EngagementDashboard {
  period: PeriodMeta;
  kpis: Kpi[];
  planned_vs_actual: Record<string, number>;
  plan_status: Record<string, number>;
  commitment_status: Record<string, number>;
  engagement_methods: Array<{ method: string; total: number; attendance: number }>;
  overdue_commitments: Array<{
    id: number; reference: string; commitment_text: string; due_date: string | null;
    days_overdue: number | null; risk_level: string; owner: string | null; stakeholders: string[];
  }>;
  commitments_with_open_grievances: Array<{
    id: number; reference: string; commitment_text: string; due_date: string | null;
    stakeholders: Array<{ id: number; name: string; reference: string }>;
  }>;
  trend: Array<{ bucket: string; completed: number; planned: number; attendance: number }>;
}

// ------------------------------------------------------------------ system

export interface SearchResult {
  id: number;
  reference: string;
  title: string;
  subtitle: string | null;
  status: string | null;
  badge: string | null;
  location: string | null;
  href: string;
}

export interface SearchResponse {
  query: string;
  groups: Array<{ key: string; label: string; results: SearchResult[] }>;
}

export interface NotificationItem {
  id: string;
  event: string | null;
  subject: string | null;
  body: string | null;
  severity: "info" | "warning" | "danger";
  url: string | null;
  reference: string | null;
  read_at: string | null;
  created_at: string;
}

export interface LocationNode {
  id: number;
  name: string;
  level: string;
  path: string | null;
  children: LocationNode[];
}

export interface LocationRow {
  id: number;
  parent_id: number | null;
  level: string;
  name: string;
  code: string | null;
  path: string | null;
  latitude: number | null;
  longitude: number | null;
  estimated_population: number | null;
}

export interface SavedView {
  id: number;
  entity: string;
  name: string;
  filters: Record<string, string | number | boolean>;
  columns: string[] | null;
  sort: string | null;
  visibility: "private" | "role" | "project";
  is_pinned: boolean;
  user?: { id: number; name: string };
}

export interface SyncConflictRecord {
  id: number;
  entity: string;
  entity_id: number;
  entity_reference: string | null;
  conflicting_fields: Record<string, {
    local: unknown;
    server: unknown;
    base: unknown;
    server_changed_by: string | null;
    server_changed_at: string | null;
  }>;
  device_id: string | null;
  status: string;
  resolution: string | null;
  resolved_at: string | null;
  created_at: string;
}

export interface AuditEntry {
  id: number;
  action: string;
  entity_type: string | null;
  entity_id: number | null;
  entity_reference: string | null;
  summary: string | null;
  before: Record<string, unknown> | null;
  after: Record<string, unknown> | null;
  is_sensitive_view: boolean;
  ip_address: string | null;
  user: { id: number; name: string } | null;
  user_name: string | null;
  created_at: string;
}

export interface ReportRecord {
  id: number;
  reference: string;
  name: string;
  template: string;
  format: string;
  filters: Record<string, unknown> | null;
  period_start: string | null;
  period_end: string | null;
  status: string;
  size_bytes: number | null;
  metrics: Record<string, unknown> | null;
  generated_at: string | null;
  generator?: { id: number; name: string } | null;
  created_at: string;
}

export interface ConfigurationBundle {
  catalogue: Array<{ key: string; label: string; group: string; description: string }>;
  values: Record<string, unknown>;
  defaults: Record<string, unknown>;
  lists: {
    stakeholder_types: string[];
    engagement_methods: string[];
    project_phases: string[];
    languages: string[];
    vulnerability_categories: string[];
    disaggregation_dimensions: Array<{
      key: string; label: string; sensitive: boolean; enabled: boolean; options: string[];
    }>;
  };
}

export interface BootstrapPayload {
  project: { id: number; name: string; code: string; country: string | null; timezone: string };
  server_time: string;
  categories: GrievanceCategory[];
  locations: LocationRow[];
  members: Array<{ id: number; name: string }>;
  severity_levels: Array<{ level: number; label: string; description: string }>;
  stakeholder_types: string[];
  channels: string[];
  configuration: Record<string, unknown>;
}
