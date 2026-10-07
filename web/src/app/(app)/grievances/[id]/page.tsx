"use client";

import { useQueryClient } from "@tanstack/react-query";
import {
  AlertOctagon,
  CheckCircle2,
  EyeOff,
  Lock,
  MessageSquare,
  Phone,
  RotateCcw,
  Send,
  ShieldAlert,
  Sparkles,
  Stethoscope,
  UserPlus,
} from "lucide-react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useState } from "react";
import { AttachmentPanel } from "@/components/app/AttachmentPanel";
import { GrievanceActions } from "@/components/app/GrievanceActions";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { DetailField, DetailGrid, PageHeader, Tabs } from "@/components/ui/DetailLayout";
import { SeverityBadge, StatusBadge } from "@/components/ui/StatusBadge";
import { ErrorState, LoadingState } from "@/components/ui/States";
import { Timeline, type TimelineItem } from "@/components/ui/Timeline";
import { apiRequest } from "@/lib/api/client";
import { useEntityHistory, useGrievance } from "@/lib/api/hooks";
import { daysBetween, formatDate, formatDateTime, formatRelative, humanise } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";
import { useToast } from "@/providers/ToastProvider";
import type { AiSuggestion, Grievance } from "@/types/api";

export default function GrievanceDetailPage() {
  const params = useParams<{ id: string }>();
  const id = Number(params.id);
  const { data, isLoading, isError, error, refetch } = useGrievance(id);
  const { can } = useSession();
  // Only requested when the user may actually read the audit trail.
  const { data: history } = useEntityHistory(can("audit.view") ? "Grievance" : null, id);

  if (isLoading) return <LoadingState label="Opening the case" />;
  if (isError || !data) return <ErrorState error={error} onRetry={() => void refetch()} />;

  const grievance = data.data;

  return (
    <div>
      <PageHeader
        backHref="/grievances"
        eyebrow={`Module 3 · Case ${grievance.reference}`}
        title={grievance.title}
        meta={
          <>
            <StatusBadge status={grievance.status} label={grievance.status_label} />
            <SeverityBadge severity={grievance.severity} label={grievance.severity_label} />
            {grievance.confidentiality !== "normal" ? <StatusBadge status={grievance.confidentiality} /> : null}
            {grievance.is_restricted ? (
              <span className="inline-flex items-center gap-1.5 rounded-full bg-danger-50 px-2.5 py-1 text-xs font-medium text-danger-700 ring-1 ring-inset ring-danger-100">
                <ShieldAlert className="h-3.5 w-3.5" aria-hidden />
                Restricted category
              </span>
            ) : null}
            {grievance.reopen_count > 0 ? (
              <span className="inline-flex items-center gap-1.5 rounded-full bg-warning-50 px-2.5 py-1 text-xs font-medium text-warning-700 ring-1 ring-inset ring-warning-100">
                <RotateCcw className="h-3.5 w-3.5" aria-hidden />
                Reopened {grievance.reopen_count}× · cycle {grievance.resolution_cycle}
              </span>
            ) : null}
            <span className="text-sm text-ink-600">
              {grievance.channel_label} · {formatDate(grievance.received_at)} · {grievance.days_open} days open
            </span>
          </>
        }
      />

      <div className="mb-6">
        <GrievanceActions grievance={grievance} />
      </div>

      {/* -------------------------- SLA strip -------------------------- */}
      <div className="mb-6 grid gap-3 sm:grid-cols-3">
        <SlaTile
          label="Acknowledgement"
          state={grievance.sla.acknowledgement_state}
          dueAt={grievance.sla.acknowledgement_due_at}
          completedAt={grievance.acknowledged_at}
          notPossible={!grievance.acknowledgement_possible}
          notPossibleReason={grievance.acknowledgement_not_possible_reason}
        />
        <SlaTile
          label="Resolution"
          state={grievance.sla.resolution_state}
          dueAt={grievance.sla.resolution_due_at}
          completedAt={grievance.resolved_at}
        />
        <div className="sasa-card p-4">
          <p className="sasa-eyebrow">Owner</p>
          <p className="mt-1.5 text-[0.9375rem] font-medium text-ink-900">
            {grievance.assigned_to?.name ?? <span className="text-warning-700">Not assigned</span>}
          </p>
          <p className="mt-0.5 text-sm text-ink-500">
            {grievance.assigned_at ? `Since ${formatDate(grievance.assigned_at)}` : "Nobody is accountable for this case yet"}
          </p>
        </div>
      </div>

      {grievance.has_ai_suggestions && (grievance.ai_suggestions ?? []).some((s) => s.status === "pending") ? (
        <AiSuggestionCard
          grievance={grievance}
          suggestion={(grievance.ai_suggestions ?? []).find((s) => s.status === "pending")!}
          onReviewed={() => void refetch()}
        />
      ) : null}

      <Tabs
        tabs={[
          {
            key: "overview",
            label: "Overview",
            content: (
              <div className="grid gap-4 lg:grid-cols-[1.5fr_1fr]">
                <div className="space-y-4">
                  <Card>
                    <CardHeader title="What happened" description="Recorded as the complainant told it." />
                    <CardBody className="space-y-5">
                      <p className="whitespace-pre-line leading-relaxed text-ink-800">{grievance.description}</p>
                      {grievance.desired_resolution ? (
                        <div className="rounded-lg bg-surface-sunken p-4">
                          <p className="sasa-eyebrow mb-1.5">What they want done</p>
                          <p className="leading-relaxed text-ink-800">{grievance.desired_resolution}</p>
                        </div>
                      ) : null}
                    </CardBody>
                  </Card>

                  {grievance.investigation_summary || grievance.investigation_findings || grievance.corrective_action ? (
                    <Card>
                      <CardHeader
                        title="Investigation and corrective action"
                        description="Recorded by a person. The AI never writes findings."
                      />
                      <CardBody className="space-y-4">
                        {grievance.investigation_summary ? (
                          <div>
                            <p className="sasa-eyebrow mb-1.5">What was done</p>
                            <p className="whitespace-pre-line leading-relaxed text-ink-800">{grievance.investigation_summary}</p>
                          </div>
                        ) : null}
                        {grievance.investigation_findings ? (
                          <div>
                            <p className="sasa-eyebrow mb-1.5">What was found</p>
                            <p className="whitespace-pre-line leading-relaxed text-ink-800">{grievance.investigation_findings}</p>
                          </div>
                        ) : null}
                        {grievance.corrective_action ? (
                          <div className="rounded-lg bg-brand-50 p-4">
                            <p className="sasa-eyebrow mb-1.5">Corrective action</p>
                            <p className="whitespace-pre-line leading-relaxed text-ink-800">{grievance.corrective_action}</p>
                            {grievance.corrective_action_due ? (
                              <p className="mt-2 text-sm text-ink-600">Due {formatDate(grievance.corrective_action_due)}</p>
                            ) : null}
                          </div>
                        ) : null}
                      </CardBody>
                    </Card>
                  ) : null}

                  {grievance.resolution_summary ? (
                    <Card>
                      <CardHeader title="Resolution" />
                      <CardBody className="space-y-4">
                        <p className="whitespace-pre-line leading-relaxed text-ink-800">{grievance.resolution_summary}</p>
                        <DetailGrid columns={2}>
                          <DetailField label="Resolved">{formatDateTime(grievance.resolved_at)}</DetailField>
                          <DetailField label="Complainant's answer">
                            {grievance.complainant_response ? humanise(grievance.complainant_response) : "Not recorded yet"}
                          </DetailField>
                          {grievance.closed_at ? <DetailField label="Closed">{formatDateTime(grievance.closed_at)}</DetailField> : null}
                          {grievance.closure_notes ? (
                            <DetailField label="Closing note" wide>
                              {grievance.closure_notes}
                            </DetailField>
                          ) : null}
                        </DetailGrid>
                      </CardBody>
                    </Card>
                  ) : null}
                </div>

                <div className="space-y-4">
                  <ComplainantCard grievance={grievance} />

                  <Card>
                    <CardHeader title="Classification" />
                    <CardBody>
                      <DetailGrid columns={2}>
                        <DetailField label="Category">{grievance.category?.name ?? "Not classified"}</DetailField>
                        <DetailField label="Subcategory">{grievance.subcategory?.name ?? "—"}</DetailField>
                        <DetailField label="Severity">
                          <SeverityBadge severity={grievance.severity} label={grievance.severity_label} size="sm" />
                        </DetailField>
                        <DetailField label="Confirmed by a person">
                          {grievance.classification_confirmed ? "Yes" : "Not yet"}
                        </DetailField>
                        <DetailField label="Received via">{grievance.channel_label}</DetailField>
                        <DetailField label="Location">
                          {grievance.location?.path ?? grievance.location_text ?? "Not recorded"}
                        </DetailField>
                        {grievance.escalation_level > 0 ? (
                          <DetailField label="Escalation level">Level {grievance.escalation_level}</DetailField>
                        ) : null}
                        {grievance.source_concern_id ? (
                          <DetailField label="Came from">
                            <Link href={`/concerns/${grievance.source_concern_id}`} className="text-brand-700 hover:underline">
                              A concern raised in an engagement
                            </Link>
                          </DetailField>
                        ) : null}
                      </DetailGrid>
                    </CardBody>
                  </Card>

                  {grievance.cycles && grievance.cycles.length > 1 ? (
                    <Card>
                      <CardHeader
                        title="Resolution cycles"
                        description="The case kept its number when it was reopened, so both attempts are on the record."
                      />
                      <CardBody className="space-y-3">
                        {grievance.cycles.map((cycle) => (
                          <div key={cycle.cycle_number} className="rounded-lg border border-hairline p-3">
                            <div className="flex items-center justify-between gap-2">
                              <p className="font-medium text-ink-900">Cycle {cycle.cycle_number}</p>
                              <span className="text-xs text-ink-500">{formatDate(cycle.opened_at)}</span>
                            </div>
                            {cycle.reopen_reason ? (
                              <p className="mt-1.5 text-sm text-warning-800">Reopened: {cycle.reopen_reason}</p>
                            ) : null}
                            {cycle.resolution_summary ? (
                              <p className="mt-1.5 text-sm text-ink-600">{cycle.resolution_summary}</p>
                            ) : null}
                            {cycle.complainant_response ? (
                              <p className="mt-1.5 text-xs text-ink-500">
                                Complainant: {humanise(cycle.complainant_response)}
                              </p>
                            ) : null}
                          </div>
                        ))}
                      </CardBody>
                    </Card>
                  ) : null}
                </div>
              </div>
            ),
          },
          {
            key: "timeline",
            label: "Case history",
            count: (grievance.follow_ups?.length ?? 0) + (grievance.assignments?.length ?? 0),
            content: (
              <Card>
                <CardHeader title="Everything that happened, in order" />
                <CardBody>
                  <Timeline items={buildTimeline(grievance)} />
                </CardBody>
              </Card>
            ),
          },
          {
            key: "communications",
            label: "Messages",
            count: grievance.communications?.length ?? null,
            content: (
              <Card>
                <CardHeader
                  title="What the complainant was told"
                  description="Every outbound message is recorded, so what was said and when is provable."
                />
                <CardBody>
                  {(grievance.communications ?? []).length === 0 ? (
                    <p className="rounded-lg bg-surface-sunken px-4 py-6 text-center text-sm text-ink-500">
                      {grievance.acknowledgement_possible
                        ? "Nothing has been sent to the complainant yet."
                        : grievance.acknowledgement_not_possible_reason ??
                          "This case arrived through a channel with no return address, so acknowledgement was not possible."}
                    </p>
                  ) : (
                    <ul className="space-y-3">
                      {(grievance.communications ?? []).map((message) => (
                        <li key={message.id} className="rounded-lg border border-hairline p-4">
                          <div className="flex flex-wrap items-center justify-between gap-2">
                            <span className="inline-flex items-center gap-2 text-sm font-medium text-ink-900">
                              <Send className="h-3.5 w-3.5 text-brand-600" aria-hidden />
                              {humanise(message.direction)} · {humanise(message.channel)}
                            </span>
                            <span className="text-xs text-ink-500">
                              {formatDateTime(message.sent_at)}
                              {message.sent_by ? ` · ${message.sent_by}` : ""}
                            </span>
                          </div>
                          <p className="mt-2 whitespace-pre-line text-sm leading-relaxed text-ink-700">{message.body}</p>
                          {message.status === "not_possible" ? (
                            <p className="mt-2 text-xs text-warning-700">
                              Recorded, but not sent — this case has no reachable contact.
                            </p>
                          ) : null}
                        </li>
                      ))}
                    </ul>
                  )}
                </CardBody>
              </Card>
            ),
          },
          {
            key: "attachments",
            label: "Files",
            count: grievance.attachments?.length ?? null,
            content: (
              <Card>
                <CardHeader
                  title="Evidence and documents"
                  description="Photographs, statements and investigation records. Files are kept in private storage and every download is logged."
                />
                <CardBody>
                  <AttachmentPanel
                    entity="grievance"
                    entityUuid={grievance.client_uuid}
                    serverId={grievance.id}
                    attachments={grievance.attachments ?? []}
                    canUpload={can("grievance.investigate")}
                    onChanged={() => void refetch()}
                  />
                </CardBody>
              </Card>
            ),
          },
          ...(can("audit.view")
            ? [{
            key: "audit",
            label: "Audit",
            content: (
              <Card>
                <CardHeader
                  title="Who did what"
                  description="Append-only. Views of a confidential complainant's details appear here too."
                />
                <CardBody>
                  {(history?.data ?? []).length === 0 ? (
                    <p className="text-sm text-ink-500">Nothing recorded yet.</p>
                  ) : (
                    <Timeline
                      items={(history?.data ?? []).map((entry) => ({
                        id: entry.id,
                        tone: entry.is_sensitive_view ? "danger" : "neutral",
                        icon: entry.is_sensitive_view ? <Lock className="h-3.5 w-3.5" aria-hidden /> : undefined,
                        title: entry.summary ?? humanise(entry.action),
                        meta: `${entry.user?.name ?? entry.user_name ?? "System"} · ${entry.action}`,
                        at: formatRelative(entry.created_at),
                      }))}
                    />
                  )}
                </CardBody>
              </Card>
            ),
          }]
            : []),
        ]}
      />
    </div>
  );
}

/* -------------------------------------------------------------------------- */

function SlaTile({
  label,
  state,
  dueAt,
  completedAt,
  notPossible,
  notPossibleReason,
}: {
  label: string;
  state: string | null;
  dueAt: string | null;
  completedAt: string | null;
  notPossible?: boolean;
  notPossibleReason?: string | null;
}) {
  if (notPossible) {
    return (
      <div className="sasa-card p-4">
        <p className="sasa-eyebrow">{label}</p>
        <p className="mt-1.5 text-[0.9375rem] font-medium text-ink-900">Not possible</p>
        <p className="mt-0.5 text-sm text-ink-500">
          {notPossibleReason ?? "This case has no reachable contact, so this is recorded rather than left blank."}
        </p>
      </div>
    );
  }

  const days = daysBetween(dueAt);

  return (
    <div className="sasa-card p-4">
      <div className="flex items-center justify-between gap-2">
        <p className="sasa-eyebrow">{label}</p>
        <StatusBadge status={state} size="sm" />
      </div>
      <p className="mt-1.5 text-[0.9375rem] font-medium text-ink-900">
        {completedAt ? formatDate(completedAt) : dueAt ? `Due ${formatDate(dueAt)}` : "No standard set"}
      </p>
      <p className="mt-0.5 text-sm text-ink-500">
        {completedAt
          ? "Done"
          : days === null
            ? "—"
            : days >= 0
              ? `${days} ${days === 1 ? "day" : "days"} left`
              : `${Math.abs(days)} ${Math.abs(days) === 1 ? "day" : "days"} over`}
      </p>
    </div>
  );
}

/** Identity is shown only if the API sent it. Absent means absent. */
function ComplainantCard({ grievance }: { grievance: Grievance }) {
  if (!grievance.identity_visible) {
    return (
      <Card>
        <CardHeader title="Complainant" />
        <CardBody>
          <div className="flex items-start gap-3 rounded-lg bg-ink-50 p-4">
            <span className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-ink-200 text-ink-600">
              {grievance.is_anonymous ? <EyeOff className="h-4 w-4" aria-hidden /> : <Lock className="h-4 w-4" aria-hidden />}
            </span>
            <div>
              <p className="font-medium text-ink-900">
                {grievance.is_anonymous ? "Submitted anonymously" : "Details are withheld"}
              </p>
              <p className="mt-1 text-sm leading-relaxed text-ink-600">{grievance.identity_withheld_reason}</p>
            </div>
          </div>
        </CardBody>
      </Card>
    );
  }

  return (
    <Card>
      <CardHeader
        title="Complainant"
        description={
          grievance.confidentiality === "confidential"
            ? "Confidential — your view of these details has been recorded in the audit trail."
            : undefined
        }
      />
      <CardBody>
        <DetailGrid columns={2}>
          <DetailField label="Name">{grievance.complainant?.name ?? "Not recorded"}</DetailField>
          <DetailField label="Phone">
            {grievance.complainant?.phone ? (
              <a href={`tel:${grievance.complainant.phone}`} className="inline-flex items-center gap-1.5 text-brand-700 hover:underline">
                <Phone className="h-3.5 w-3.5" aria-hidden />
                {grievance.complainant.phone}
              </a>
            ) : (
              "Not recorded"
            )}
          </DetailField>
          <DetailField label="Email">{grievance.complainant?.email ?? "Not recorded"}</DetailField>
          <DetailField label="They are a">{humanise(grievance.complainant_type)}</DetailField>
          <DetailField label="Language">{humanise(grievance.complainant_language)}</DetailField>
          <DetailField label="Best contact">{humanise(grievance.preferred_contact_method)}</DetailField>
          {grievance.precise_location ? (
            <DetailField label="Exact location" wide>
              {grievance.precise_location}
            </DetailField>
          ) : null}
          {grievance.stakeholder ? (
            <DetailField label="In the register" wide>
              <Link href={`/stakeholders/${grievance.stakeholder.id}`} className="text-brand-700 hover:underline">
                {grievance.stakeholder.reference} — {grievance.stakeholder.name}
              </Link>
            </DetailField>
          ) : null}
        </DetailGrid>
      </CardBody>
    </Card>
  );
}

/** AI proposes; a person confirms. The case says so until they do. */
function AiSuggestionCard({
  grievance,
  suggestion,
  onReviewed,
}: {
  grievance: Grievance;
  suggestion: AiSuggestion;
  onReviewed: () => void;
}) {
  const toast = useToast();
  const queryClient = useQueryClient();
  const { can } = useSession();
  const [busy, setBusy] = useState(false);

  if (!can("ai.review")) return null;

  const review = async (decision: "accepted" | "rejected") => {
    setBusy(true);
    try {
      await apiRequest(`/ai/suggestions/${suggestion.id}/review`, { method: "POST", body: { decision } });

      if (decision === "accepted" && can("grievance.classify")) {
        await apiRequest(`/grievances/${grievance.id}/classify`, {
          method: "POST",
          body: {
            category_id: suggestion.suggestion.category_id ?? grievance.category?.id,
            subcategory_id: suggestion.suggestion.subcategory_id ?? grievance.subcategory?.id ?? null,
            severity: suggestion.suggestion.severity ?? grievance.severity,
          },
        });
      }

      await queryClient.invalidateQueries({ queryKey: ["grievance", grievance.id] });
      toast.success(decision === "accepted" ? "Classification confirmed" : "Suggestion rejected");
      onReviewed();
    } catch (error) {
      toast.error("That did not go through", error instanceof Error ? error.message : undefined);
    } finally {
      setBusy(false);
    }
  };

  const confidence = suggestion.confidence !== null ? Math.round(suggestion.confidence * 100) : null;

  return (
    <div className="mb-6 rounded-xl border border-brand-200 bg-brand-50 p-5">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="flex gap-3">
          <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary text-on-primary">
            <Sparkles className="h-5 w-5" aria-hidden />
          </span>
          <div className="min-w-0">
            <p className="font-semibold text-brand-900">AI suggested — please confirm</p>
            <p className="mt-1 text-sm leading-relaxed text-brand-800">
              {suggestion.suggestion.rationale ?? "A classification has been proposed for this case."}
              {confidence !== null ? ` Confidence ${confidence}%.` : ""}
            </p>
            <dl className="mt-3 flex flex-wrap gap-x-6 gap-y-2 text-sm">
              {suggestion.suggestion.severity ? (
                <div>
                  <dt className="text-brand-600">Severity</dt>
                  <dd className="font-medium text-brand-900">Level {suggestion.suggestion.severity}</dd>
                </div>
              ) : null}
              {suggestion.suggestion.suggested_routing_role ? (
                <div>
                  <dt className="text-brand-600">Suggested owner</dt>
                  <dd className="font-medium text-brand-900">{humanise(suggestion.suggestion.suggested_routing_role)}</dd>
                </div>
              ) : null}
              {suggestion.suggestion.summary ? (
                <div className="w-full">
                  <dt className="text-brand-600">Summary</dt>
                  <dd className="text-brand-900">{suggestion.suggestion.summary}</dd>
                </div>
              ) : null}
            </dl>
          </div>
        </div>

        <div className="flex shrink-0 gap-2">
          <Button variant="secondary" loading={busy} onClick={() => void review("rejected")}>
            Reject
          </Button>
          <Button variant="primary" loading={busy} onClick={() => void review("accepted")} icon={<CheckCircle2 className="h-4 w-4" />}>
            Confirm
          </Button>
        </div>
      </div>
    </div>
  );
}

function buildTimeline(grievance: Grievance): TimelineItem[] {
  const items: TimelineItem[] = [];

  items.push({
    id: "received",
    tone: "brand",
    icon: <MessageSquare className="h-3.5 w-3.5" aria-hidden />,
    title: `Case opened via ${grievance.channel_label.toLowerCase()}`,
    meta: `${grievance.reference} · ${grievance.confidentiality === "normal" ? "Standard" : humanise(grievance.confidentiality)}`,
    at: formatDateTime(grievance.received_at),
  });

  (grievance.assignments ?? [])
    .slice()
    .reverse()
    .forEach((assignment) => {
      items.push({
        id: `assignment-${assignment.id}`,
        tone: "brand",
        icon: <UserPlus className="h-3.5 w-3.5" aria-hidden />,
        title: assignment.assigned_to ? `Assigned to ${assignment.assigned_to}` : "Unassigned",
        meta: assignment.assigned_by ? `by ${assignment.assigned_by}` : undefined,
        body: assignment.reason,
        at: formatDateTime(assignment.assigned_at),
      });
    });

  if (grievance.acknowledged_at) {
    items.push({
      id: "acknowledged",
      tone: "success",
      icon: <Send className="h-3.5 w-3.5" aria-hidden />,
      title: "Complainant acknowledged",
      meta: humanise(grievance.acknowledgement_method),
      at: formatDateTime(grievance.acknowledged_at),
    });
  }

  if (grievance.investigation_started_at) {
    items.push({
      id: "investigation-start",
      tone: "brand",
      icon: <Stethoscope className="h-3.5 w-3.5" aria-hidden />,
      title: "Investigation started",
      at: formatDateTime(grievance.investigation_started_at),
    });
  }

  (grievance.follow_ups ?? []).forEach((followUp) => {
    items.push({
      id: `follow-up-${followUp.id}`,
      tone: "neutral",
      icon: <MessageSquare className="h-3.5 w-3.5" aria-hidden />,
      title: humanise(followUp.type),
      meta: `${followUp.author ?? "Unknown"}${followUp.resolution_cycle > 1 ? ` · cycle ${followUp.resolution_cycle}` : ""}`,
      body: <p className="whitespace-pre-line">{followUp.body}</p>,
      at: formatDateTime(followUp.created_at),
    });
  });

  (grievance.escalations ?? []).forEach((escalation) => {
    items.push({
      id: `escalation-${escalation.id}`,
      tone: "danger",
      icon: <AlertOctagon className="h-3.5 w-3.5" aria-hidden />,
      title: `Escalated to level ${escalation.to_level}`,
      meta: `${humanise(escalation.trigger)}${escalation.escalated_to ? ` · to ${escalation.escalated_to}` : ""}`,
      body: escalation.reason,
      at: formatDateTime(escalation.escalated_at),
    });
  });

  if (grievance.resolved_at) {
    items.push({
      id: "resolved",
      tone: "success",
      icon: <CheckCircle2 className="h-3.5 w-3.5" aria-hidden />,
      title: "Resolution recorded",
      body: grievance.resolution_summary,
      at: formatDateTime(grievance.resolved_at),
    });
  }

  if (grievance.last_reopened_at) {
    items.push({
      id: "reopened",
      tone: "warning",
      icon: <RotateCcw className="h-3.5 w-3.5" aria-hidden />,
      title: `Reopened — cycle ${grievance.resolution_cycle}`,
      body: grievance.cycles?.find((cycle) => cycle.cycle_number === grievance.resolution_cycle)?.reopen_reason,
      at: formatDateTime(grievance.last_reopened_at),
    });
  }

  if (grievance.closed_at) {
    items.push({
      id: "closed",
      tone: "neutral",
      icon: <CheckCircle2 className="h-3.5 w-3.5" aria-hidden />,
      title: "Case closed",
      body: grievance.closure_notes,
      at: formatDateTime(grievance.closed_at),
    });
  }

  return items.sort((a, b) => String(a.at).localeCompare(String(b.at)));
}
