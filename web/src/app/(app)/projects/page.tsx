"use client";

import { Check, FolderKanban } from "lucide-react";
import { Card, CardBody } from "@/components/ui/Card";
import { PageHeader } from "@/components/ui/DetailLayout";
import { StatusBadge } from "@/components/ui/StatusBadge";
import { cn } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";

export default function ProjectsPage() {
  const { projects, project, switchProject } = useSession();

  return (
    <div className="mx-auto max-w-3xl">
      <PageHeader
        title="My projects"
        description="SASA scopes everything to a project. Switching here changes every list, dashboard and report."
      />

      <div className="grid gap-3">
        {projects.map((candidate) => {
          const active = candidate.id === project?.id;

          return (
            <button
              key={candidate.id}
              type="button"
              onClick={() => void switchProject(candidate.id)}
              className={cn(
                "sasa-card flex items-start gap-4 p-5 text-left transition",
                active ? "border-brand-500 ring-2 ring-brand-500/20" : "hover:border-brand-300",
              )}
            >
              <span
                className={cn(
                  "flex h-11 w-11 shrink-0 items-center justify-center rounded-lg",
                  active ? "bg-primary text-on-primary" : "bg-ink-100 text-ink-600",
                )}
              >
                <FolderKanban className="h-5 w-5" aria-hidden />
              </span>
              <span className="min-w-0 flex-1">
                <span className="flex flex-wrap items-center gap-2">
                  <span className="font-semibold text-ink-900">{candidate.name}</span>
                  <StatusBadge status={candidate.status} size="sm" />
                </span>
                <span className="mt-1 block text-sm text-ink-600">
                  {candidate.code}
                  {candidate.sector ? ` · ${candidate.sector}` : ""}
                  {candidate.country ? ` · ${candidate.country}` : ""}
                </span>
                <span className="mt-2 block text-sm">
                  <span className="rounded-full bg-brand-50 px-2.5 py-1 text-xs font-medium text-brand-800">
                    {candidate.role.name}
                  </span>
                </span>
              </span>
              {active ? <Check className="h-5 w-5 shrink-0 text-brand-600" aria-hidden /> : null}
            </button>
          );
        })}
      </div>

      {projects.length === 0 ? (
        <Card>
          <CardBody>
            <p className="text-sm text-ink-600">
              You are not on any project yet. Ask your project administrator to add you.
            </p>
          </CardBody>
        </Card>
      ) : null}
    </div>
  );
}
