"use client";

import {
  Archive,
  CalendarClock,
  ClipboardCheck,
  Handshake,
  Mail,
  MapPin,
  MessageSquareWarning,
  Pencil,
  Phone,
  Scale,
  Users,
} from "lucide-react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useState } from "react";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { DetailField, DetailGrid, PageHeader, Tabs } from "@/components/ui/DetailLayout";
import { ConfirmDialog, Drawer } from "@/components/ui/Drawer";
import { Field, Select, Textarea } from "@/components/ui/Form";
import { PriorityBadge, StatusBadge } from "@/components/ui/StatusBadge";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/States";
import { Timeline } from "@/components/ui/Timeline";
import { apiRequest } from "@/lib/api/client";
import { useEntityHistory, useStakeholder, useStakeholderTimeline } from "@/lib/api/hooks";
import { formatDate, formatDateTime, formatRelative, humanise, languageName } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";
import { useToast } from "@/providers/ToastProvider";

const TIMELINE_ICON = {
  engagement: CalendarClock,
  concern: MessageSquareWarning,
  grievance: ClipboardCheck,
  commitment: Handshake,
};

export default function StakeholderDetailPage() {
  const params = useParams<{ id: string }>();
  const id = Number(params.id);
  const router = useRouter();
  const toast = useToast();
  const { can } = useSession();

  const { data, isLoading, isError, refetch } = useStakeholder(id);
  const { data: timeline } = useStakeholderTimeline(id);
  const { data: history } = useEntityHistory("Stakeholder", id);

  const [overrideOpen, setOverrideOpen] = useState(false);
  const [archiveOpen, setArchiveOpen] = useState(false);
  const [priority, setPriority] = useState("high");
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);

  if (isLoading) return <LoadingState label="Opening the record" />;
  if (isError || !data) return <ErrorState onRetry={() => void refetch()} />;

  const stakeholder = data.data;
  const assessment = stakeholder.assessment;

  const override = async () => {
    setBusy(true);
    try {
      await apiRequest(`/stakeholders/${id}/priority`, {
        method: "POST",
        body: { priority, reason },
      });
      toast.success("Priority updated", "The change, the previous value and your reason are in the audit trail.");
      setOverrideOpen(false);
      setReason("");
      void refetch();
    } catch (error) {
      toast.error("We could not change the priority", error instanceof Error ? error.message : undefined);
    } finally {
      setBusy(false);
    }
  };

  const archive = async () => {
    setBusy(true);
    try {
      await apiRequest(`/stakeholders/${id}`, { method: "DELETE" });
      toast.success(`${stakeholder.reference} archived`, "It stays on historical records and in the audit trail.");
      router.push("/stakeholders");
    } catch {
      toast.error("We could not archive this record");
    } finally {
      setBusy(false);
    }
  };

  return (
    <div>
      <PageHeader
        backHref="/stakeholders"
        eyebrow={`Module 1 · Register · ${stakeholder.reference}`}
        title={stakeholder.name}
        description={stakeholder.concerns_expectations ?? undefined}
        meta={
          <>
            <StatusBadge status={stakeholder.status} />
            <PriorityBadge priority={assessment.stored_priority} />
            <span className="text-sm text-ink-600">{stakeholder.type_label}</span>
            {stakeholder.is_vulnerable ? (
              <span className="rounded-full bg-warning-50 px-2.5 py-1 text-xs font-medium text-warning-700 ring-1 ring-inset ring-warning-100">
                Vulnerable
              </span>
            ) : null}
            {stakeholder.review_due ? (
              <span className="rounded-full bg-warning-50 px-2.5 py-1 text-xs font-medium text-warning-700 ring-1 ring-inset ring-warning-100">
                Review overdue
              </span>
            ) : null}
          </>
        }
        actions={
          <>
            {can("stakeholder.update") ? (
              <Link href={`/stakeholders/${id}/edit`}>
                <Button variant="secondary" icon={<Pencil className="h-4 w-4" />}>
                  Edit
                </Button>
              </Link>
            ) : null}
            {can("stakeholder.override_priority") ? (
              <Button variant="secondary" icon={<Scale className="h-4 w-4" />} onClick={() => setOverrideOpen(true)}>
                Set priority
              </Button>
            ) : null}
            {can("stakeholder.archive") && !stakeholder.archived_at ? (
              <Button variant="ghost" icon={<Archive className="h-4 w-4" />} onClick={() => setArchiveOpen(true)}>
                Archive
              </Button>
            ) : null}
          </>
        }
      />

      {/* --------------------- contact strip --------------------- */}
      <div className="mb-6 grid gap-3 sm:grid-cols-3">
        <ContactTile icon={Phone} label="Phone" value={stakeholder.phone} href={stakeholder.phone ? `tel:${stakeholder.phone}` : undefined} />
        <ContactTile icon={Mail} label="Email" value={stakeholder.email} href={stakeholder.email ? `mailto:${stakeholder.email}` : undefined} />
        <ContactTile icon={MapPin} label="Location" value={stakeholder.location.display} />
      </div>

      <Tabs
        tabs={[
          {
            key: "overview",
            label: "Overview",
            content: (
              <div className="grid gap-4 lg:grid-cols-[1.4fr_1fr]">
                <div className="space-y-4">
                  <Card>
                    <CardHeader title="Identity" />
                    <CardBody>
                      <DetailGrid>
                        <DetailField label="Stakeholder ID">
                          <span className="font-mono">{stakeholder.reference}</span>
                        </DetailField>
                        <DetailField label="Type">{stakeholder.type_label}</DetailField>
                        <DetailField label="Also known as">{stakeholder.alias ?? "—"}</DetailField>
                        <DetailField label="Organisation">{stakeholder.organisation_name ?? "—"}</DetailField>
                        <DetailField label="Position">{stakeholder.position ?? "—"}</DetailField>
                        <DetailField label="Preferred language">
                          {languageName(stakeholder.preferred_language)}
                        </DetailField>
                        <DetailField label="Preferred contact">
                          {humanise(stakeholder.preferred_contact_method)}
                        </DetailField>
                        <DetailField label="Record owner">{stakeholder.owner?.name ?? "Unassigned"}</DetailField>
                        <DetailField label="Added">{formatDate(stakeholder.created_at)}</DetailField>
                        {stakeholder.physical_address ? (
                          <DetailField label="Address" wide>
                            {stakeholder.physical_address}
                          </DetailField>
                        ) : null}
                      </DetailGrid>
                    </CardBody>
                  </Card>

                  {stakeholder.concerns_expectations || stakeholder.notes ? (
                    <Card>
                      <CardHeader title="What they have told us" />
                      <CardBody className="space-y-4">
                        {stakeholder.concerns_expectations ? (
                          <div>
                            <p className="sasa-eyebrow mb-1.5">Concerns and expectations</p>
                            <p className="whitespace-pre-line leading-relaxed text-ink-800">
                              {stakeholder.concerns_expectations}
                            </p>
                          </div>
                        ) : null}
                        {stakeholder.notes ? (
                          <div>
                            <p className="sasa-eyebrow mb-1.5">Internal notes</p>
                            <p className="whitespace-pre-line leading-relaxed text-ink-700">{stakeholder.notes}</p>
                          </div>
                        ) : null}
                      </CardBody>
                    </Card>
                  ) : null}

                  <Card>
                    <CardHeader
                      title="Vulnerability, consent and review"
                      description="Only what the stakeholder agreed to share."
                    />
                    <CardBody>
                      <DetailGrid columns={2}>
                        <DetailField label="Vulnerable">
                          {stakeholder.is_vulnerable
                            ? stakeholder.vulnerability_categories.map(humanise).join(", ") || "Yes"
                            : "Not flagged"}
                        </DetailField>
                        <DetailField label="Indigenous or minority">
                          {stakeholder.is_indigenous_or_minority ? "Yes" : "Not flagged"}
                        </DetailField>
                        <DetailField label="Consent">
                          {humanise(stakeholder.consent_status)}
                          {stakeholder.consent_date ? ` · ${formatDate(stakeholder.consent_date)}` : ""}
                        </DetailField>
                        <DetailField label="Legal basis">{humanise(stakeholder.consent_basis)}</DetailField>
                        <DetailField label="How they were identified">
                          {humanise(stakeholder.identification_source)}
                        </DetailField>
                        <DetailField label="Next review">
                          {stakeholder.review_date ? (
                            <span className={stakeholder.review_due ? "font-medium text-warning-700" : ""}>
                              {formatDate(stakeholder.review_date)}
                              {stakeholder.review_due ? " (overdue)" : ""}
                            </span>
                          ) : (
                            "Not set"
                          )}
                        </DetailField>
                      </DetailGrid>
                    </CardBody>
                  </Card>
                </div>

                <div className="space-y-4">
                  <PriorityCard
                    assessment={assessment}
                    strategy={stakeholder.engagement_strategy}
                    frequency={stakeholder.communication_frequency}
                  />

                  <Card>
                    <CardHeader title="On the chain" description="Everything linked to this stakeholder." />
                    <CardBody className="grid grid-cols-2 gap-3">
                      {[
                        ["Engagements", stakeholder.counts?.engagements, CalendarClock, `/engagements?stakeholder_id=${id}`],
                        ["Concerns", stakeholder.counts?.concerns, MessageSquareWarning, `/concerns?stakeholder_id=${id}`],
                        ["Grievances", stakeholder.counts?.grievances, ClipboardCheck, `/grievances?stakeholder_id=${id}`],
                        ["Commitments", stakeholder.counts?.commitments, Handshake, `/commitments?stakeholder_id=${id}`],
                      ].map(([label, count, Icon, href]) => {
                        const IconComponent = Icon as typeof CalendarClock;

                        return (
                          <Link
                            key={String(label)}
                            href={href as string}
                            className="group rounded-lg border border-hairline p-3 transition hover:border-brand-300 hover:bg-brand-50/50"
                          >
                            <IconComponent className="h-4 w-4 text-ink-400 group-hover:text-brand-600" aria-hidden />
                            <p className="tabular mt-2 text-xl font-semibold text-ink-900">{(count as number) ?? 0}</p>
                            <p className="text-xs text-ink-600">{label as string}</p>
                          </Link>
                        );
                      })}
                    </CardBody>
                  </Card>
                </div>
              </div>
            ),
          },
          {
            key: "timeline",
            label: "Timeline",
            count: timeline?.data.length ?? null,
            content: (
              <Card>
                <CardHeader
                  title="Everything on the chain"
                  description="Engagements, concerns, grievances and commitments involving this stakeholder, newest first."
                />
                <CardBody>
                  {(timeline?.data ?? []).length === 0 ? (
                    <EmptyState
                      icon={Users}
                      title="Nothing linked yet"
                      description="Once you log an engagement or a case involving this stakeholder, it will appear here."
                    />
                  ) : (
                    <Timeline
                      items={(timeline?.data ?? []).map((entry) => {
                        const Icon = TIMELINE_ICON[entry.type as keyof typeof TIMELINE_ICON] ?? CalendarClock;

                        return {
                          id: `${entry.type}-${entry.id}`,
                          icon: <Icon className="h-3.5 w-3.5" aria-hidden />,
                          tone:
                            entry.type === "grievance"
                              ? "danger"
                              : entry.type === "commitment"
                                ? "warning"
                                : entry.type === "concern"
                                  ? "warning"
                                  : "brand",
                          title: (
                            <Link href={entry.href} className="hover:text-brand-700">
                              <span className="font-mono text-xs text-ink-500">{entry.reference}</span> {entry.title}
                            </Link>
                          ),
                          meta: (
                            <span className="flex flex-wrap items-center gap-2">
                              <StatusBadge status={entry.status} size="sm" />
                              {entry.subtitle ? <span>{entry.subtitle}</span> : null}
                            </span>
                          ),
                          at: formatDate(entry.at),
                        };
                      })}
                    />
                  )}
                </CardBody>
              </Card>
            ),
          },
          {
            key: "priority-history",
            label: "Priority history",
            count: stakeholder.assessments?.length ?? null,
            content: (
              <Card>
                <CardHeader
                  title="How the priority has moved"
                  description="Every recalculation and every override, with the weights that were in force at the time."
                />
                <CardBody>
                  {(stakeholder.assessments ?? []).length === 0 ? (
                    <p className="text-sm text-ink-500">No assessments recorded yet.</p>
                  ) : (
                    <Timeline
                      items={(stakeholder.assessments ?? []).map((entry) => ({
                        id: entry.id,
                        tone: entry.is_override ? "warning" : "brand",
                        icon: <Scale className="h-3.5 w-3.5" aria-hidden />,
                        title: entry.is_override
                          ? `Overridden to ${humanise(entry.stored_priority)}`
                          : `Calculated as ${humanise(entry.calculated_priority)}`,
                        meta: (
                          <span className="tabular">
                            Score {entry.score}
                            {entry.previous_priority ? ` · was ${humanise(entry.previous_priority)}` : ""} ·{" "}
                            {entry.assessed_by ?? "System"}
                          </span>
                        ),
                        body: entry.override_reason ? (
                          <p className="rounded-lg bg-warning-50 p-3 text-warning-800">{entry.override_reason}</p>
                        ) : null,
                        at: formatDateTime(entry.assessed_at),
                      }))}
                    />
                  )}
                </CardBody>
              </Card>
            ),
          },
          {
            key: "history",
            label: "Record history",
            content: (
              <Card>
                <CardHeader title="Who changed what" description="The audit trail for this record." />
                <CardBody>
                  {(history?.data ?? []).length === 0 ? (
                    <p className="text-sm text-ink-500">No changes recorded yet.</p>
                  ) : (
                    <Timeline
                      items={(history?.data ?? []).map((entry) => ({
                        id: entry.id,
                        tone: entry.is_sensitive_view ? "danger" : "neutral",
                        title: entry.summary ?? humanise(entry.action),
                        meta: `${entry.user?.name ?? entry.user_name ?? "System"} · ${humanise(entry.action)}`,
                        at: formatRelative(entry.created_at),
                      }))}
                    />
                  )}
                </CardBody>
              </Card>
            ),
          },
        ]}
      />

      {/* --------------------- priority override --------------------- */}
      <Drawer
        open={overrideOpen}
        onClose={() => setOverrideOpen(false)}
        title="Set the priority yourself"
        description="Use this when the score does not capture something real. The calculated value stays visible, and your reason is recorded."
        width="sm"
        footer={
          <>
            <Button variant="secondary" onClick={() => setOverrideOpen(false)}>
              Cancel
            </Button>
            <Button variant="primary" loading={busy} disabled={reason.trim().length < 10} onClick={() => void override()}>
              Save priority
            </Button>
          </>
        }
      >
        <div className="space-y-5">
          <div className="rounded-lg bg-surface-sunken p-4">
            <p className="sasa-eyebrow mb-2">Calculated now</p>
            <div className="flex items-center gap-3">
              <PriorityBadge priority={assessment.calculated_priority} />
              <span className="tabular text-sm text-ink-600">score {assessment.score ?? "—"}</span>
            </div>
            {assessment.explanation ? (
              <p className="mt-2 text-sm leading-relaxed text-ink-600">{assessment.explanation}</p>
            ) : null}
          </div>

          <Field label="Set priority to" required htmlFor="override-priority">
            <Select id="override-priority" value={priority} onChange={(event) => setPriority(event.target.value)}>
              <option value="high">High</option>
              <option value="medium">Medium</option>
              <option value="low">Low</option>
            </Select>
          </Field>

          <Field
            label="Why"
            required
            htmlFor="override-reason"
            hint="At least a sentence. This is read by auditors, and systematic overrides tell your administrator the weights are wrong."
          >
            <Textarea
              id="override-reason"
              rows={4}
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              placeholder="Chairs the district land committee, which the four scoring dimensions do not capture."
            />
          </Field>
        </div>
      </Drawer>

      <ConfirmDialog
        open={archiveOpen}
        onCancel={() => setArchiveOpen(false)}
        onConfirm={() => void archive()}
        loading={busy}
        tone="danger"
        title={`Archive ${stakeholder.reference}?`}
        description="Nothing is deleted. The record leaves the active register but stays on every engagement, concern, grievance and commitment it is already part of, and stays in the audit trail."
        confirmLabel="Archive the record"
      />
    </div>
  );
}

function ContactTile({
  icon: Icon,
  label,
  value,
  href,
}: {
  icon: typeof Phone;
  label: string;
  value: string | null;
  href?: string;
}) {
  const content = (
    <div className="sasa-card flex items-center gap-3 p-4">
      <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700">
        <Icon className="h-4 w-4" aria-hidden />
      </span>
      <div className="min-w-0">
        <p className="sasa-eyebrow">{label}</p>
        <p className="mt-0.5 truncate text-[0.9375rem] font-medium text-ink-900">{value ?? "Not recorded"}</p>
      </div>
    </div>
  );

  return href ? (
    <a href={href} className="block transition hover:opacity-90">
      {content}
    </a>
  ) : (
    content
  );
}

function PriorityCard({
  assessment,
  strategy,
  frequency,
}: {
  assessment: {
    influence: string | null; interest: string | null; power: string | null; impact: string | null;
    score: number | null; calculated_priority: string | null; stored_priority: string | null;
    is_overridden: boolean; explanation?: string; override_reason?: string | null;
  };
  strategy: string | null;
  frequency: string | null;
}) {
  return (
    <Card>
      <CardHeader
        title="Priority"
        description="The calculated value is always shown next to the stored one."
      />
      <CardBody className="space-y-4">
        <div className="flex items-center justify-between gap-4 rounded-lg bg-brand-50 p-4">
          <div>
            <p className="sasa-eyebrow">Stored</p>
            <div className="mt-1.5">
              <PriorityBadge priority={assessment.stored_priority} />
            </div>
          </div>
          <div className="text-right">
            <p className="sasa-eyebrow">Calculated</p>
            <div className="mt-1.5 flex items-center justify-end gap-2">
              <PriorityBadge priority={assessment.calculated_priority} size="sm" />
              <span className="tabular text-lg font-semibold text-brand-900">{assessment.score ?? "—"}</span>
            </div>
          </div>
        </div>

        {assessment.is_overridden ? (
          <div className="rounded-lg bg-warning-50 p-3 text-sm text-warning-800">
            <p className="font-medium">Set by a person, not by the score.</p>
            {assessment.override_reason ? <p className="mt-1">{assessment.override_reason}</p> : null}
          </div>
        ) : null}

        <dl className="grid grid-cols-2 gap-3">
          {(["influence", "interest", "power", "impact"] as const).map((dimension) => (
            <div key={dimension} className="rounded-lg border border-hairline p-3">
              <dt className="sasa-eyebrow">{dimension}</dt>
              <dd className="mt-1 text-sm font-medium text-ink-800">{humanise(assessment[dimension])}</dd>
            </div>
          ))}
        </dl>

        {assessment.explanation ? (
          <p className="text-sm leading-relaxed text-ink-600">{assessment.explanation}</p>
        ) : null}

        <DetailGrid columns={2}>
          <DetailField label="Recommended strategy">{strategy ?? "—"}</DetailField>
          <DetailField label="Contact frequency">{frequency ?? "—"}</DetailField>
        </DetailGrid>
      </CardBody>
    </Card>
  );
}
