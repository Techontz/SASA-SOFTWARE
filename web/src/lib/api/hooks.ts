"use client";

import {
  useMutation,
  useQuery,
  useQueryClient,
  type UseQueryOptions,
} from "@tanstack/react-query";
import { apiRequest } from "./client";
import type {
  ApiError,
  AuditEntry,
  BootstrapPayload,
  Commitment,
  Concern,
  ConfigurationBundle,
  DisaggregationDashboard,
  Engagement,
  EngagementDashboard,
  EngagementPlan,
  ExecutiveDashboard,
  Grievance,
  GrievanceCategory,
  Kpi,
  LandingDashboard,
  LocationRow,
  Paginated,
  ReportRecord,
  SavedView,
  SeverityDashboard,
  Stakeholder,
  SyncConflictRecord,
  TimelinessDashboard,
} from "@/types/api";

type Query = Record<string, string | number | boolean | null | undefined>;

/** Strip empty filters so the query key and the request URL stay stable. */
function clean(query: Query = {}): Query {
  return Object.fromEntries(
    Object.entries(query).filter(([, value]) => value !== "" && value !== null && value !== undefined),
  );
}

function list<T>(path: string, key: string) {
  return (query: Query = {}, options?: Partial<UseQueryOptions<Paginated<T>>>) =>
    useQuery<Paginated<T>>({
      queryKey: [key, clean(query)],
      queryFn: () => apiRequest<Paginated<T>>(path, { query: clean(query) }),
      ...options,
    });
}

function detail<T>(path: (id: number | string) => string, key: string) {
  return (id: number | string | null | undefined, options?: Partial<UseQueryOptions<{ data: T }>>) =>
    useQuery<{ data: T }>({
      queryKey: [key, id],
      queryFn: () => apiRequest<{ data: T }>(path(id as number)),
      enabled: Boolean(id),
      ...options,
    });
}

// ---------------------------------------------------------------- register

export const useStakeholders = list<Stakeholder>("/stakeholders", "stakeholders");
export const useStakeholder = detail<Stakeholder>((id) => `/stakeholders/${id}`, "stakeholder");

export function useStakeholderTimeline(id: number | null) {
  return useQuery({
    queryKey: ["stakeholder-timeline", id],
    queryFn: () =>
      apiRequest<{
        data: Array<{
          type: string; id: number; reference: string; title: string;
          subtitle: string | null; status: string; at: string | null; href: string;
        }>;
      }>(`/stakeholders/${id}/timeline`),
    enabled: Boolean(id),
  });
}

export function usePriorityModel() {
  return useQuery({
    queryKey: ["priority-model"],
    queryFn: () =>
      apiRequest<{
        data: {
          settings: {
            weights: Record<string, number>;
            levels: Record<string, number>;
            thresholds: { high: number; medium: number };
            bands: Record<string, { strategy: string; frequency: string }>;
          };
          formula: string;
          note: string;
        };
      }>("/stakeholders/priority-model"),
    staleTime: 10 * 60_000,
  });
}

// -------------------------------------------------------------- engagement

export const useEngagementPlans = list<EngagementPlan>("/engagement-plans", "engagement-plans");
export const useEngagementPlan = detail<EngagementPlan>((id) => `/engagement-plans/${id}`, "engagement-plan");
export const useEngagements = list<Engagement>("/engagements", "engagements");
export const useEngagement = detail<Engagement>((id) => `/engagements/${id}`, "engagement");
export const useConcerns = list<Concern>("/concerns", "concerns");
export const useConcern = detail<Concern>((id) => `/concerns/${id}`, "concern");
export const useCommitments = list<Commitment>("/commitments", "commitments");
export const useCommitment = detail<Commitment>((id) => `/commitments/${id}`, "commitment");

export function useEngagementCalendar(from?: string, to?: string) {
  return useQuery({
    queryKey: ["engagement-calendar", from, to],
    queryFn: () =>
      apiRequest<{
        data: Array<{
          id: number; reference: string; title: string; date: string | null;
          status: string; priority: string; method: string | null;
          stakeholder: string | null; location: string | null; owner: string | null; href: string;
        }>;
      }>("/engagement-plans/calendar", { query: clean({ from, to }) }),
  });
}

// -------------------------------------------------------------- grievances

export const useGrievances = list<Grievance>("/grievances", "grievances");
export const useGrievance = detail<Grievance>((id) => `/grievances/${id}`, "grievance");

export function useGrievanceCategories() {
  return useQuery({
    queryKey: ["grievance-categories"],
    queryFn: () => apiRequest<{ data: GrievanceCategory[] }>("/configuration/categories"),
    staleTime: 10 * 60_000,
  });
}

// -------------------------------------------------------------- dashboards

export function useLandingDashboard(query: Query = {}) {
  return useQuery({
    queryKey: ["dashboard", "landing", clean(query)],
    queryFn: () => apiRequest<{ data: LandingDashboard }>("/dashboard/landing", { query: clean(query) }),
  });
}

export function useExecutiveDashboard(query: Query = {}) {
  return useQuery({
    queryKey: ["dashboard", "executive", clean(query)],
    queryFn: () => apiRequest<{ data: ExecutiveDashboard }>("/dashboard/executive", { query: clean(query) }),
  });
}

export function useTimelinessDashboard(query: Query = {}) {
  return useQuery({
    queryKey: ["dashboard", "timeliness", clean(query)],
    queryFn: () => apiRequest<{ data: TimelinessDashboard }>("/dashboard/timeliness", { query: clean(query) }),
  });
}

export function useDisaggregationDashboard(query: Query = {}) {
  return useQuery({
    queryKey: ["dashboard", "disaggregation", clean(query)],
    queryFn: () => apiRequest<{ data: DisaggregationDashboard }>("/dashboard/disaggregation", { query: clean(query) }),
  });
}

export function useSeverityDashboard(query: Query = {}) {
  return useQuery({
    queryKey: ["dashboard", "severity", clean(query)],
    queryFn: () => apiRequest<{ data: SeverityDashboard }>("/dashboard/severity", { query: clean(query) }),
  });
}

export function useEngagementDashboard(query: Query = {}) {
  return useQuery({
    queryKey: ["dashboard", "engagement", clean(query)],
    queryFn: () => apiRequest<{ data: EngagementDashboard }>("/dashboard/engagement", { query: clean(query) }),
  });
}

export function useMetricDefinitions() {
  return useQuery({
    queryKey: ["metric-definitions"],
    queryFn: () =>
      apiRequest<{ data: Array<{ key: string; label: string; definition: string; unit: string }> }>(
        "/dashboard/definitions",
      ),
    staleTime: 60 * 60_000,
  });
}

// ------------------------------------------------------------------ system

export function useLocations(query: Query = {}) {
  return useQuery({
    queryKey: ["locations", clean(query)],
    queryFn: () => apiRequest<{ data: LocationRow[] }>("/locations", { query: clean(query) }),
    staleTime: 10 * 60_000,
  });
}

export function useProjectMembers() {
  return useQuery({
    queryKey: ["project-members"],
    queryFn: () =>
      apiRequest<{
        data: Array<{
          id: number;
          user: { id: number; name: string; email: string; status: string; job_title: string | null; last_login_at: string | null; phone: string | null };
          role: { id: number; key: string; name: string };
          handling_groups: string[];
          status: string;
          joined_at: string | null;
        }>;
      }>("/members"),
  });
}

export function useConfiguration() {
  return useQuery({
    queryKey: ["configuration"],
    queryFn: () => apiRequest<{ data: ConfigurationBundle }>("/configuration"),
    staleTime: 5 * 60_000,
  });
}

export function useBootstrap() {
  return useQuery({
    queryKey: ["bootstrap"],
    queryFn: () => apiRequest<{ data: BootstrapPayload }>("/sync/bootstrap"),
    staleTime: 10 * 60_000,
  });
}

export function useSavedViews(entity?: string) {
  return useQuery({
    queryKey: ["saved-views", entity],
    queryFn: () => apiRequest<{ data: SavedView[] }>("/saved-views", { query: clean({ entity }) }),
  });
}

export function useAudit(query: Query = {}) {
  return useQuery({
    queryKey: ["audit", clean(query)],
    queryFn: () => apiRequest<{ data: Paginated<AuditEntry> }>("/audit", { query: clean(query) }),
  });
}

export function useEntityHistory(type: string | null, id: number | null) {
  return useQuery({
    queryKey: ["audit", type, id],
    queryFn: () => apiRequest<{ data: AuditEntry[] }>(`/audit/${type}/${id}`),
    enabled: Boolean(type && id),
  });
}

export function useReports(query: Query = {}) {
  return useQuery({
    queryKey: ["reports", clean(query)],
    queryFn: () => apiRequest<{ data: Paginated<ReportRecord> }>("/reports", { query: clean(query) }),
  });
}

export function useReportTemplates() {
  return useQuery({
    queryKey: ["report-templates"],
    queryFn: () =>
      apiRequest<{ data: Array<{ key: string; name: string; formats: string[] }> }>("/reports/templates"),
    staleTime: 60 * 60_000,
  });
}

export function useSyncConflicts(status = "open") {
  return useQuery({
    queryKey: ["sync-conflicts", status],
    queryFn: () => apiRequest<{ data: Paginated<SyncConflictRecord> }>("/sync/conflicts", { query: { status } }),
  });
}

export function useAiStatus() {
  return useQuery({
    queryKey: ["ai-status"],
    queryFn: () =>
      apiRequest<{
        data: { provider: string; available: boolean; model: string; never_owns: string[]; note: string };
      }>("/ai/status"),
    staleTime: 10 * 60_000,
  });
}

export function useAiAccuracy() {
  return useQuery({
    queryKey: ["ai-accuracy"],
    queryFn: () =>
      apiRequest<{
        data: { reviewed: number; accepted: number; modified: number; rejected: number; acceptance_rate: number | null; pending: number };
      }>("/ai/accuracy"),
  });
}

export function useVoiceCalls(needsReview = false) {
  return useQuery({
    queryKey: ["voice-calls", needsReview],
    queryFn: () =>
      apiRequest<{
        data: Paginated<{
          id: number; external_id: string; provider: string; language: string | null;
          status: string; consent_granted: boolean; duration_seconds: number;
          was_truncated: boolean; needs_human_review: boolean; callback_requested: boolean;
          transcript: string | null;
          grievance: { id: number; reference: string; status: string } | null;
          started_at: string | null; ended_at: string | null;
        }>;
      }>("/ai/voice-calls", { query: clean({ needs_review: needsReview ? 1 : undefined }) }),
  });
}

// --------------------------------------------------------------- mutations

/** A mutation that also invalidates the lists the change would have moved. */
export function useApiMutation<TResult, TVariables>(
  request: (variables: TVariables) => Promise<TResult>,
  invalidate: string[] = [],
) {
  const queryClient = useQueryClient();

  return useMutation<TResult, ApiError, TVariables>({
    mutationFn: request,
    onSuccess: () => {
      invalidate.forEach((key) => {
        void queryClient.invalidateQueries({ queryKey: [key] });
      });
    },
  });
}

export function useKpiMap(kpis: Kpi[] | undefined): Record<string, Kpi> {
  return Object.fromEntries((kpis ?? []).map((kpi) => [kpi.key, kpi]));
}
