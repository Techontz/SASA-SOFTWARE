import { useInfiniteQuery, useQuery } from "@tanstack/react-query";
import { api, type Query } from "./api";
import { useSession } from "./session";
import type {
  Bootstrap,
  Commitment,
  Engagement,
  EngagementPlan,
  Grievance,
  LandingDashboard,
  Paginated,
  Stakeholder,
  TimelineItem,
} from "./types";

/** Every key carries the project, so switching never shows another project's data. */
function useProjectKey(): number {
  return useSession().project?.id ?? 0;
}

function clean(query: Query): Query {
  return Object.fromEntries(Object.entries(query).filter(([, value]) => value !== undefined && value !== null && value !== ""));
}

export function usePagedList<T>(path: string, query: Query, enabled = true) {
  const project = useProjectKey();
  const params = clean(query);
  return useInfiniteQuery({
    queryKey: [project, path, params],
    queryFn: ({ pageParam, signal }) =>
      api<Paginated<T>>(path, { query: { ...params, page: pageParam, per_page: 25 }, signal }),
    initialPageParam: 1,
    getNextPageParam: (last) => (last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined),
    enabled: enabled && project > 0,
  });
}

export const useGrievances = (query: Query) => usePagedList<Grievance>("/grievances", query);
export const useStakeholders = (query: Query, enabled = true) => usePagedList<Stakeholder>("/stakeholders", query, enabled);
export const useEngagements = (query: Query) => usePagedList<Engagement>("/engagements", query);
export const useCommitments = (query: Query) => usePagedList<Commitment>("/commitments", query);

function useRecord<T>(path: string | null) {
  const project = useProjectKey();
  return useQuery({
    queryKey: [project, path],
    queryFn: ({ signal }) => api<{ data: T }>(path ?? "", { signal }).then((response) => response.data),
    enabled: Boolean(path) && project > 0,
  });
}

export const useGrievance = (id: string | undefined) => useRecord<Grievance>(id ? `/grievances/${id}` : null);
export const useStakeholder = (id: string | undefined) => useRecord<Stakeholder>(id ? `/stakeholders/${id}` : null);
export const useEngagement = (id: string | undefined) => useRecord<Engagement>(id ? `/engagements/${id}` : null);
export const useStakeholderTimeline = (id: string | undefined) =>
  useRecord<TimelineItem[]>(id ? `/stakeholders/${id}/timeline` : null);

export function useLanding(enabled: boolean) {
  const project = useProjectKey();
  return useQuery({
    queryKey: [project, "/dashboard/landing"],
    queryFn: ({ signal }) => api<{ data: LandingDashboard }>("/dashboard/landing", { signal }).then((r) => r.data),
    enabled: enabled && project > 0,
  });
}

/** Option lists, locations, members and the mandatory fields for each form. */
export function useBootstrap() {
  const project = useProjectKey();
  return useQuery({
    queryKey: [project, "/sync/bootstrap"],
    queryFn: ({ signal }) => api<{ data: Bootstrap }>("/sync/bootstrap", { signal }).then((r) => r.data),
    enabled: project > 0,
    staleTime: 10 * 60_000,
  });
}

export function usePlannedEngagements() {
  const project = useProjectKey();
  return useQuery({
    queryKey: [project, "/engagement-plans", "planned"],
    queryFn: ({ signal }) =>
      api<Paginated<EngagementPlan>>("/engagement-plans", { query: { status: "planned", per_page: 50 }, signal }).then(
        (r) => r.data,
      ),
    enabled: project > 0,
    staleTime: 5 * 60_000,
  });
}
