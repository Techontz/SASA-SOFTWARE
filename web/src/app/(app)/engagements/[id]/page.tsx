"use client";

import { ArrowRight, Handshake, MapPin, MessageSquareWarning, Users } from "lucide-react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { AttachmentPanel } from "@/components/app/AttachmentPanel";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { DetailField, DetailGrid, PageHeader, Tabs } from "@/components/ui/DetailLayout";
import { PriorityBadge, StatusBadge } from "@/components/ui/StatusBadge";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/States";
import { useEngagement } from "@/lib/api/hooks";
import { formatDate, formatDateTime, formatNumber, humanise } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";

export default function EngagementDetailPage() {
  const params = useParams<{ id: string }>();
  const id = Number(params.id);
  const { can } = useSession();
  const { data, isLoading, isError, error, refetch } = useEngagement(id);

  if (isLoading) return <LoadingState label="Opening the engagement" />;
  if (isError || !data) return <ErrorState error={error} onRetry={() => void refetch()} />;

  const engagement = data.data;
  const attendance = engagement.attendance;

  return (
    <div>
      <PageHeader
        backHref="/engagements"
        eyebrow={`Module 2 · ${engagement.reference}`}
        title={engagement.topic}
        meta={
          <>
            <StatusBadge status={engagement.planned_vs_actual} label={engagement.planned_vs_actual_label} />
            <span className="text-sm text-ink-600">
              {formatDateTime(engagement.held_at)} · {humanise(engagement.method)}
            </span>
            {engagement.location.path || engagement.location.text ? (
              <span className="inline-flex items-center gap-1.5 text-sm text-ink-600">
                <MapPin className="h-3.5 w-3.5" aria-hidden />
                {engagement.location.path ?? engagement.location.text}
              </span>
            ) : null}
          </>
        }
      />

      {/* --------------------- planned vs actual --------------------- */}
      <div className="mb-6 grid gap-3 sm:grid-cols-3">
        <div className="sasa-card p-4">
          <p className="sasa-eyebrow">Planned</p>
          {engagement.plan ? (
            <>
              <Link href={`/engagements/plans/${engagement.plan.id}`} className="mt-1.5 block font-medium text-brand-700 hover:underline">
                {engagement.plan.reference}
              </Link>
              <p className="mt-0.5 text-sm text-ink-600">for {formatDate(engagement.plan.target_date)}</p>
            </>
          ) : (
            <>
              <p className="mt-1.5 font-medium text-ink-900">Not planned</p>
              <p className="mt-0.5 text-sm text-ink-600">This engagement was unscheduled.</p>
            </>
          )}
        </div>
        <div className="sasa-card p-4">
          <p className="sasa-eyebrow">Actual</p>
          <p className="mt-1.5 font-medium text-ink-900">{formatDate(engagement.held_at)}</p>
          <p className="mt-0.5 text-sm text-ink-600">
            {engagement.variance_days
              ? engagement.variance_days > 0
                ? `${engagement.variance_days} days after the planned window`
                : `${Math.abs(engagement.variance_days)} days before the planned window`
              : "Inside the planned window"}
          </p>
        </div>
        <div className="sasa-card p-4">
          <p className="sasa-eyebrow">People reached</p>
          <p className="tabular mt-1.5 text-2xl font-semibold text-ink-900">{formatNumber(attendance.total)}</p>
          <p className="mt-0.5 text-sm text-ink-600">
            {attendance.female} women · {attendance.vulnerable} in vulnerable situations
          </p>
        </div>
      </div>

      <Tabs
        tabs={[
          {
            key: "record",
            label: "The record",
            content: (
              <div className="grid gap-4 lg:grid-cols-[1.5fr_1fr]">
                <div className="min-w-0 space-y-4">
                  <Card>
                    <CardHeader title="What was discussed" />
                    <CardBody className="space-y-5">
                      {engagement.aim ? (
                        <div>
                          <p className="sasa-eyebrow mb-1.5">Aim</p>
                          <p className="leading-relaxed text-ink-800">{engagement.aim}</p>
                        </div>
                      ) : null}
                      {engagement.discussion_points ? (
                        <div>
                          <p className="sasa-eyebrow mb-1.5">Discussion points</p>
                          <p className="whitespace-pre-line leading-relaxed text-ink-800">{engagement.discussion_points}</p>
                        </div>
                      ) : (
                        <p className="text-sm text-ink-500">No minutes were recorded for this engagement.</p>
                      )}
                      {engagement.outcomes ? (
                        <div className="rounded-lg bg-surface-sunken p-4">
                          <p className="sasa-eyebrow mb-1.5">Outcomes</p>
                          <p className="whitespace-pre-line leading-relaxed text-ink-800">{engagement.outcomes}</p>
                        </div>
                      ) : null}
                    </CardBody>
                  </Card>

                  <Card>
                    <CardHeader
                      title="Concerns raised"
                      description="Anything raised here can become a formal grievance without being retyped."
                      action={
                        <Link href="/concerns" className="text-sm font-medium text-brand-700 hover:text-brand-900">
                          All concerns
                        </Link>
                      }
                    />
                    <CardBody>
                      {(engagement.concerns ?? []).length === 0 ? (
                        <p className="rounded-lg bg-surface-sunken px-4 py-5 text-center text-sm text-ink-500">
                          No concerns were recorded in this engagement.
                        </p>
                      ) : (
                        <ul className="space-y-3">
                          {(engagement.concerns ?? []).map((concern) => (
                            <li key={concern.id}>
                              <Link
                                href={`/concerns/${concern.id}`}
                                className="group flex items-start gap-3 rounded-lg border border-hairline p-4 transition hover:border-brand-300 hover:bg-brand-50/40"
                              >
                                <span className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-warning-50 text-warning-700">
                                  <MessageSquareWarning className="h-4 w-4" aria-hidden />
                                </span>
                                <span className="min-w-0 flex-1">
                                  <span className="flex flex-wrap items-center gap-2">
                                    <span className="font-mono text-xs text-ink-500">{concern.reference}</span>
                                    <StatusBadge status={concern.status} size="sm" />
                                    {concern.grievance ? (
                                      <span className="text-xs text-danger-700">
                                        Escalated to {concern.grievance.reference}
                                      </span>
                                    ) : null}
                                  </span>
                                  <span className="mt-1 block font-medium text-ink-900">{concern.title}</span>
                                  <span className="mt-0.5 block text-sm text-ink-600">{concern.description}</span>
                                </span>
                                <ArrowRight className="h-4 w-4 shrink-0 text-ink-400 transition group-hover:translate-x-0.5" aria-hidden />
                              </Link>
                            </li>
                          ))}
                        </ul>
                      )}
                    </CardBody>
                  </Card>

                  <Card>
                    <CardHeader
                      title="Commitments made"
                      description="The accountability tail of this engagement."
                      action={
                        <Link href="/commitments" className="text-sm font-medium text-brand-700 hover:text-brand-900">
                          Register
                        </Link>
                      }
                    />
                    <CardBody>
                      {(engagement.commitments ?? []).length === 0 ? (
                        <p className="rounded-lg bg-surface-sunken px-4 py-5 text-center text-sm text-ink-500">
                          Nothing was promised in this engagement.
                        </p>
                      ) : (
                        <ul className="space-y-3">
                          {(engagement.commitments ?? []).map((commitment) => (
                            <li key={commitment.id}>
                              <Link
                                href={`/commitments/${commitment.id}`}
                                className="group flex items-start gap-3 rounded-lg border border-hairline p-4 transition hover:border-brand-300 hover:bg-brand-50/40"
                              >
                                <span className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700">
                                  <Handshake className="h-4 w-4" aria-hidden />
                                </span>
                                <span className="min-w-0 flex-1">
                                  <span className="flex flex-wrap items-center gap-2">
                                    <span className="font-mono text-xs text-ink-500">{commitment.reference}</span>
                                    <StatusBadge status={commitment.status} size="sm" />
                                    <PriorityBadge priority={commitment.risk_level} prefix="Risk" size="sm" />
                                  </span>
                                  <span className="mt-1 block text-ink-800">{commitment.commitment_text}</span>
                                  <span className="mt-0.5 block text-sm text-ink-500">
                                    {commitment.owner?.name ?? "Unassigned"} ·{" "}
                                    {commitment.due_date ? `due ${formatDate(commitment.due_date)}` : "no due date"}
                                  </span>
                                </span>
                                <ArrowRight className="h-4 w-4 shrink-0 text-ink-400 transition group-hover:translate-x-0.5" aria-hidden />
                              </Link>
                            </li>
                          ))}
                        </ul>
                      )}
                    </CardBody>
                  </Card>
                </div>

                <div className="min-w-0 space-y-4">
                  <Card>
                    <CardHeader title="Attendance" description="Who was reached, in the categories the reporting needs." />
                    <CardBody>
                      <dl className="grid grid-cols-2 gap-3">
                        {[
                          ["Total", attendance.total],
                          ["Women", attendance.female],
                          ["Men", attendance.male],
                          ["Young people", attendance.youth],
                          ["Elderly", attendance.elderly],
                          ["With a disability", attendance.disability],
                          ["Vulnerable", attendance.vulnerable],
                        ].map(([label, value]) => (
                          <div key={String(label)} className="rounded-lg border border-hairline p-3">
                            <dt className="sasa-eyebrow">{label}</dt>
                            <dd className="tabular mt-1 text-lg font-semibold text-ink-900">{formatNumber(value as number)}</dd>
                          </div>
                        ))}
                      </dl>
                    </CardBody>
                  </Card>

                  <Card>
                    <CardHeader title="Details" />
                    <CardBody>
                      <DetailGrid columns={2}>
                        <DetailField label="Method">{humanise(engagement.method)}</DetailField>
                        <DetailField label="Venue">{engagement.venue ?? "—"}</DetailField>
                        <DetailField label="Organised by">{engagement.organised_by ?? "—"}</DetailField>
                        <DetailField label="Facilitator">{engagement.facilitator?.name ?? "—"}</DetailField>
                        <DetailField label="Project phase">{humanise(engagement.project_phase)}</DetailField>
                        <DetailField label="Recorded">{formatDate(engagement.created_at)}</DetailField>
                      </DetailGrid>
                    </CardBody>
                  </Card>

                  {(engagement.stakeholders ?? []).length > 0 ? (
                    <Card>
                      <CardHeader title="Stakeholders present" />
                      <CardBody>
                        <ul className="space-y-2">
                          {(engagement.stakeholders ?? []).map((stakeholder) => (
                            <li key={stakeholder.id}>
                              <Link
                                href={`/stakeholders/${stakeholder.id}`}
                                className="flex items-center gap-2.5 rounded-md px-2 py-1.5 transition hover:bg-brand-50"
                              >
                                <Users className="h-4 w-4 shrink-0 text-ink-400" aria-hidden />
                                <span className="min-w-0 flex-1 truncate text-sm text-ink-800">{stakeholder.name}</span>
                                <span className="shrink-0 font-mono text-xs text-ink-500">{stakeholder.reference}</span>
                              </Link>
                            </li>
                          ))}
                        </ul>
                      </CardBody>
                    </Card>
                  ) : null}
                </div>
              </div>
            ),
          },
          {
            key: "attendance",
            label: "Attendance list",
            count: engagement.participants?.length ?? null,
            content: (
              <Card>
                <CardHeader title="Who signed the attendance sheet" />
                <CardBody>
                  {(engagement.participants ?? []).length === 0 ? (
                    <EmptyState
                      icon={Users}
                      title="No named attendees"
                      description="Only the headcount was recorded for this engagement."
                    />
                  ) : (
                    <ul className="divide-y divide-hairline">
                      {(engagement.participants ?? []).map((participant, index) => (
                        <li key={participant.id ?? index} className="flex flex-wrap items-center gap-3 py-3">
                          <span className="min-w-0 flex-1">
                            <span className="block font-medium text-ink-900">{participant.name ?? "Unnamed"}</span>
                            <span className="block text-sm text-ink-500">
                              {[participant.position, participant.organisation_name].filter(Boolean).join(" · ") || "—"}
                            </span>
                          </span>
                          {participant.category ? (
                            <span className="rounded-full bg-ink-100 px-2 py-0.5 text-xs text-ink-600">
                              {humanise(participant.category)}
                            </span>
                          ) : null}
                          {participant.signed_attendance ? (
                            <span className="rounded-full bg-success-50 px-2 py-0.5 text-xs text-success-700">Signed</span>
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
            key: "files",
            label: "Files",
            count: engagement.attachments?.length ?? null,
            content: (
              <Card>
                <CardHeader
                  title="Minutes, attendance sheets and photographs"
                  description="Files are kept in private storage. Every download is recorded."
                />
                <CardBody>
                  <AttachmentPanel
                    entity="engagement"
                    entityUuid={engagement.client_uuid}
                    serverId={engagement.id}
                    attachments={engagement.attachments ?? []}
                    canUpload={can("engagement.log")}
                    onChanged={() => void refetch()}
                  />
                </CardBody>
              </Card>
            ),
          },
        ]}
      />
    </div>
  );
}
