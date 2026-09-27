"use client";

import { useQuery } from "@tanstack/react-query";
import { useEffect, useState } from "react";
import { apiRequest } from "@/lib/api/client";
import { localStore } from "@/lib/offline/dexieStore";
import { useSession } from "@/providers/SessionProvider";
import type { BootstrapPayload, GrievanceCategory, LocationRow } from "@/types/api";

/**
 * Everything a form needs to be fillable — category lists, locations, project
 * members, severity descriptions — from the network when there is one, and
 * from the copy cached on this device when there is not.
 *
 * Without this, a field officer with no signal gets a form full of empty
 * dropdowns, which is the same as having no form at all.
 */
export function useFieldReference() {
  const { project } = useSession();
  const [cached, setCached] = useState<BootstrapPayload | null>(null);

  const query = useQuery({
    queryKey: ["bootstrap", project?.id],
    queryFn: () => apiRequest<{ data: BootstrapPayload }>("/sync/bootstrap"),
    enabled: Boolean(project),
    staleTime: 10 * 60_000,
    retry: 1,
  });

  /* Read the local copy immediately, so the form is usable before (or without)
     the network answering. */
  useEffect(() => {
    if (!project) return;

    void localStore()
      .getReference<BootstrapPayload>("bootstrap", project.id)
      .then((payload) => {
        if (payload) setCached(payload);
      });
  }, [project]);

  /* Whenever the network does answer, refresh the local copy. */
  useEffect(() => {
    if (!project || !query.data) return;

    void localStore().putReference({
      key: "bootstrap",
      projectId: project.id,
      payload: query.data.data,
      fetchedAt: new Date().toISOString(),
    });
  }, [project, query.data]);

  const data = query.data?.data ?? cached;
  const configuration = (data?.configuration ?? {}) as Record<string, unknown>;

  const list = (key: string, fallback: string[]): string[] => {
    const value = configuration[key];
    return Array.isArray(value) && value.length > 0 ? (value as string[]) : fallback;
  };

  return {
    /** True once the form has enough to be filled in, from either source. */
    ready: Boolean(data),
    fromCache: !query.data && Boolean(cached),
    categories: (data?.categories ?? []) as GrievanceCategory[],
    locations: (data?.locations ?? []) as LocationRow[],
    members: data?.members ?? [],
    severityLevels: data?.severity_levels ?? [],
    channels: data?.channels ?? ["voice", "whatsapp", "sms", "web", "in_person", "email", "leader", "suggestion_box"],
    stakeholderTypes: data?.stakeholder_types ?? [
      "individual", "household", "community_group", "cso", "government",
      "traditional_leader", "contractor", "project_staff", "business", "vulnerable_group",
    ],
    engagementMethods: list("engagement_methods", [
      "community_meeting", "focus_group", "household_visit", "public_hearing",
      "radio_programme", "phone_call", "written_disclosure", "workshop", "site_walkover",
    ]),
    projectPhases: list("project_phases", ["pre_construction", "construction", "operation", "decommissioning"]),
    languages: list("languages", ["sw", "en"]),
    vulnerabilityCategories: list("vulnerability_categories", [
      "elderly", "disability", "female_headed_household", "child_headed_household",
      "landless", "chronically_ill", "displaced", "indigenous", "linguistic_minority", "low_income",
    ]),
  };
}
