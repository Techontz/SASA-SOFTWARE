"use client";

import { Archive, BadgeCheck, CheckCircle2, CircleDot, Clock, Paperclip, PlayCircle, RotateCcw, Send, Undo2 } from "lucide-react";
import { useParams, useRouter } from "next/navigation";
import { useState, type ReactNode } from "react";
import { EshsEvidence } from "@/components/eshs/EshsEvidence";
import { EshsOverdueBadge, EshsRiskBadge, EshsStageBadge } from "@/components/eshs/EshsBadges";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { DetailField, DetailGrid, PageHeader } from "@/components/ui/DetailLayout";
import { ConfirmDialog, Drawer } from "@/components/ui/Drawer";
import { Field, Input, Textarea } from "@/components/ui/Form";
import { ErrorState, LoadingState } from "@/components/ui/States";
import { Timeline, type TimelineItem } from "@/components/ui/Timeline";
import { useEshsAction, useEshsAnomaly, type EshsAction } from "@/lib/api/eshs";
import { targetLabel } from "@/lib/eshs";
import { formatDate, formatDateTime } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";
import { useSync } from "@/providers/SyncProvider";
import { useToast } from "@/providers/ToastProvider";
import type { EshsAnomaly, EshsHistoryEntry } from "@/types/eshs";

type Panel = "progress" | "submit" | "close" | "return" | "archive" | null;

const HISTORY: Record<string, { icon: ReactNode; tone: TimelineItem["tone"] }> = {
  created: { icon: <CircleDot className="h-4 w-4" />, tone: "brand" },
  progress_updated: { icon: <PlayCircle className="h-4 w-4" />, tone: "neutral" },
  evidence_submitted: { icon: <Send className="h-4 w-4" />, tone: "warning" },
  returned: { icon: <Undo2 className="h-4 w-4" />, tone: "danger" },
  closed: { icon: <CheckCircle2 className="h-4 w-4" />, tone: "success" },
  archived: { icon: <Archive className="h-4 w-4" />, tone: "neutral" },
  attachment_uploaded: { icon: <Paperclip className="h-4 w-4" />, tone: "neutral" },
};

export default function EshsAnomalyPage() {
  const params = useParams<{ id: string }>();
  const id = Number(params.id);
  const { data, isLoading, isError, error, refetch } = useEshsAnomaly(id);

  if (isLoading) return <LoadingState label="Opening the anomaly" />;
  if (isError || !data) return <ErrorState error={error} onRetry={() => void refetch()} />;

  return <AnomalyDetail anomaly={data.data} refetch={() => void refetch()} />;
}

function AnomalyDetail({ anomaly, refetch }: { anomaly: EshsAnomaly; refetch: () => void }) {
  const { can } = useSession();
  const sync = useSync();
  const toast = useToast();
  const router = useRouter();
  const action = useEshsAction(anomaly.id);

  const [panel, setPanel] = useState<Panel>(null);
  const [form, setForm] = useState<Record<string, string>>({});

  const abilities = anomaly.can ?? { edit: false, upload: false, respond: false, verify: false, archive: false };
  const attachments = anomaly.attachments ?? [];
  const evidence = attachments.filter((file) => file.kind !== "closure_evidence");
  const closureEvidence = attachments.filter((file) => file.kind === "closure_evidence");
  const awaiting = anomaly.stage === "awaiting_verification";
  const closed = anomaly.status === "closed";

  const set = (key: string) => (event: { target: { value: string } }) =>
    setForm((current) => ({ ...current, [key]: event.target.value }));

  const run = async (name: EshsAction, body: Record<string, string | null>, message: string) => {
    try {
      await action.mutateAsync({ action: name, body });
      toast.success(message, "Saved on the server.");
      setPanel(null);
      setForm({});
      if (name === "archive") {
        router.push("/eshs");
        return;
      }
      refetch();
    } catch (failure) {
      toast.error("Nothing was saved", failure instanceof Error ? failure.message : undefined);
    }
  };

  const offlineNote = !sync.online ? "You are offline. This needs a connection — nothing will be saved until you are back online." : undefined;

  const timeline: TimelineItem[] = (anomaly.history ?? []).map((entry: EshsHistoryEntry) => ({
    id: entry.id,
    icon: HISTORY[entry.action]?.icon ?? <CircleDot className="h-4 w-4" />,
    tone: HISTORY[entry.action]?.tone ?? "neutral",
    title: entry.summary ?? entry.action,
    meta: entry.user ?? undefined,
    body: entry.detail ?? undefined,
    at: formatDateTime(entry.at),
  }));

  return (
    <div className="mx-auto max-w-5xl">
      <PageHeader
        backHref="/eshs"
        eyebrow={`ESHS anomaly · ${anomaly.reference}`}
        title={`${anomaly.category_label ?? anomaly.category}: ${anomaly.subcategory}`}
        meta={
          <>
            <EshsStageBadge stage={anomaly.stage} label={anomaly.stage_label} />
            <EshsRiskBadge risk={anomaly.risk_rating} label={anomaly.risk_label} />
            {anomaly.is_overdue ? <EshsOverdueBadge /> : null}
            <span className="text-sm text-ink-600">
              {anomaly.contractor} · raised {formatDate(anomaly.raised_on)}
            </span>
          </>
        }
        actions={
          <>
            {abilities.respond && anomaly.status === "open" ? (
              <Button variant="primary" icon={<PlayCircle className="h-4 w-4" />} onClick={() => setPanel("progress")}>
                Start the action
              </Button>
            ) : null}
            {abilities.respond && anomaly.status === "in_progress" && !awaiting ? (
              <>
                <Button variant="secondary" icon={<Clock className="h-4 w-4" />} onClick={() => setPanel("progress")}>
                  Update progress
                </Button>
                <Button variant="primary" icon={<Send className="h-4 w-4" />} onClick={() => setPanel("submit")}>
                  Submit for verification
                </Button>
              </>
            ) : null}
            {abilities.verify && awaiting ? (
              <Button variant="secondary" icon={<RotateCcw className="h-4 w-4" />} onClick={() => setPanel("return")}>
                Return to contractor
              </Button>
            ) : null}
            {abilities.verify && !closed && (awaiting || closureEvidence.length > 0) ? (
              <Button variant="primary" icon={<BadgeCheck className="h-4 w-4" />} onClick={() => setPanel("close")}>
                Verify and close
              </Button>
            ) : null}
            {abilities.archive ? (
              <Button variant="ghost" icon={<Archive className="h-4 w-4" />} onClick={() => setPanel("archive")}>
                Archive
              </Button>
            ) : null}
          </>
        }
      />

      {awaiting ? (
        <Notice tone="warning" icon={<Clock className="h-5 w-5 shrink-0 text-warning-600" aria-hidden />}>
          {anomaly.contractor} submitted closure evidence on {formatDateTime(anomaly.evidence_submitted_at)}.{" "}
          {abilities.verify
            ? "Check it below, then close the anomaly or return it with a reason."
            : "It is waiting for the supervision team to verify it."}
        </Notice>
      ) : null}

      {anomaly.return_reason && !awaiting && !closed ? (
        <Notice tone="danger" icon={<Undo2 className="h-5 w-5 shrink-0 text-danger-600" aria-hidden />}>
          <strong className="font-semibold">Returned on {formatDateTime(anomaly.returned_at)}:</strong> {anomaly.return_reason}
        </Notice>
      ) : null}

      {closed ? (
        <Notice tone="success" icon={<CheckCircle2 className="h-5 w-5 shrink-0 text-success-600" aria-hidden />}>
          Verified and closed on {formatDate(anomaly.closed_on)}
          {anomaly.closed_by ? ` by ${anomaly.closed_by.name}` : ""}. A closed anomaly cannot be changed.
        </Notice>
      ) : null}

      <div className="grid gap-4 lg:grid-cols-[1.5fr_1fr]">
        <div className="min-w-0 space-y-4">
          <Card>
            <CardHeader title="The anomaly" />
            <CardBody className="space-y-5">
              <p className="whitespace-pre-line leading-relaxed text-ink-800">{anomaly.description}</p>
              <DetailGrid columns={2}>
                <DetailField label="Anomaly type">{anomaly.anomaly_type}</DetailField>
                <DetailField label="Risk rating">{anomaly.risk_label}</DetailField>
                <DetailField label="Root cause" wide>
                  {anomaly.root_cause ? `${anomaly.root_cause_group} — ${anomaly.root_cause}` : "Not yet identified"}
                </DetailField>
              </DetailGrid>
            </CardBody>
          </Card>

          <Card>
            <CardHeader title="Action by the contractor" description="All actions are the contractor's." />
            <CardBody className="space-y-5">
              <p className="whitespace-pre-line leading-relaxed text-ink-800">{anomaly.contractor_action ?? "No action recorded yet."}</p>
              <DetailGrid columns={2}>
                <DetailField label="Action owner">{anomaly.action_owner_name ?? "—"}</DetailField>
                {"action_owner_email" in anomaly ? (
                  <DetailField label="Owner e-mail">
                    {anomaly.action_owner_email ? (
                      <a className="break-all text-brand-700 hover:underline" href={`mailto:${anomaly.action_owner_email}`}>
                        {anomaly.action_owner_email}
                      </a>
                    ) : (
                      "—"
                    )}
                  </DetailField>
                ) : null}
                {"action_owner_phone" in anomaly ? (
                  <DetailField label="Owner phone">
                    {anomaly.action_owner_phone ? (
                      <a className="text-brand-700 hover:underline" href={`tel:${anomaly.action_owner_phone}`}>
                        {anomaly.action_owner_phone}
                      </a>
                    ) : (
                      "—"
                    )}
                  </DetailField>
                ) : null}
                <DetailField label="Target date">
                  <span className={anomaly.is_overdue ? "font-medium text-danger-700" : undefined}>
                    {formatDate(anomaly.target_date)}
                    {targetLabel(anomaly.days_until_target, closed) ? ` · ${targetLabel(anomaly.days_until_target, closed)}` : ""}
                  </span>
                </DetailField>
              </DetailGrid>
              {anomaly.progress_notes ? (
                <div>
                  <p className="sasa-eyebrow mb-1.5">Progress</p>
                  <p className="whitespace-pre-line leading-relaxed text-ink-700">{anomaly.progress_notes}</p>
                </div>
              ) : null}
              {anomaly.closure_notes ? (
                <div className="rounded-lg bg-surface-sunken p-4">
                  <p className="sasa-eyebrow mb-1.5">Contractor&apos;s closure note</p>
                  <p className="whitespace-pre-line leading-relaxed text-ink-800">{anomaly.closure_notes}</p>
                </div>
              ) : null}
              {anomaly.verification_notes ? (
                <div className="rounded-lg bg-success-50 p-4">
                  <p className="sasa-eyebrow mb-1.5">Verification</p>
                  <p className="whitespace-pre-line leading-relaxed text-ink-800">{anomaly.verification_notes}</p>
                </div>
              ) : null}
            </CardBody>
          </Card>

          <Card>
            <CardHeader
              title="Closure evidence"
              description="Uploaded by the contractor for the supervision team to verify. At least one file is needed before the anomaly can be closed."
            />
            <CardBody>
              <EshsEvidence
                anomalyId={anomaly.id}
                kind="closure_evidence"
                attachments={closureEvidence}
                canUpload={abilities.upload && can("eshs.respond")}
                empty="No closure evidence yet."
                onChanged={refetch}
              />
            </CardBody>
          </Card>

          <Card>
            <CardHeader title="Evidence of the anomaly" description="Photographs, documents or video taken when it was raised." />
            <CardBody>
              <EshsEvidence
                anomalyId={anomaly.id}
                kind="evidence"
                attachments={evidence}
                canUpload={abilities.upload && can("eshs.raise")}
                empty="No evidence attached."
                onChanged={refetch}
              />
            </CardBody>
          </Card>
        </div>

        <div className="min-w-0 space-y-4">
          <Card>
            <CardHeader title="Details" />
            <CardBody>
              <DetailGrid columns={2}>
                <DetailField label="ID">{anomaly.reference}</DetailField>
                <DetailField label="Date raised">{formatDate(anomaly.raised_on)}</DetailField>
                <DetailField label="Contractor">{anomaly.contractor}</DetailField>
                <DetailField label="Subcontractor">{anomaly.subcontractor ?? "—"}</DetailField>
                <DetailField label="Area / location" wide>
                  {anomaly.location.path ?? "—"}
                </DetailField>
                <DetailField label="Raised by">{anomaly.raised_by?.name ?? "—"}</DetailField>
                <DetailField label="Status">{anomaly.status_label}</DetailField>
                <DetailField label="Date closed">{formatDate(anomaly.closed_on)}</DetailField>
                <DetailField label="Verified & closed by">{anomaly.closed_by?.name ?? "—"}</DetailField>
              </DetailGrid>
            </CardBody>
          </Card>

          <Card>
            <CardHeader title="History" description="From the audit trail." />
            <CardBody>
              <Timeline items={timeline} />
            </CardBody>
          </Card>
        </div>
      </div>

      <Drawer
        open={panel === "progress"}
        onClose={() => setPanel(null)}
        title={anomaly.status === "open" ? "Start the action" : "Update progress"}
        description="Moves the anomaly to In progress. Upload closure evidence when the work is done."
        width="md"
        footer={
          <>
            <Button variant="secondary" onClick={() => setPanel(null)}>Cancel</Button>
            <Button
              variant="primary"
              loading={action.isPending}
              disabled={!sync.online}
              onClick={() =>
                void run(
                  "progress",
                  {
                    contractor_action: form.contractor_action ?? anomaly.contractor_action,
                    action_owner_name: form.action_owner_name ?? anomaly.action_owner_name,
                    action_owner_email: form.action_owner_email ?? anomaly.action_owner_email ?? null,
                    action_owner_phone: form.action_owner_phone ?? anomaly.action_owner_phone ?? null,
                    progress_notes: form.progress_notes ?? anomaly.progress_notes,
                  },
                  "Progress recorded",
                )
              }
            >
              Save progress
            </Button>
          </>
        }
      >
        <div className="space-y-5">
          {offlineNote ? <p className="text-sm text-warning-700">{offlineNote}</p> : null}
          <Field label="Action by contractor" htmlFor="eshs-action">
            <Textarea id="eshs-action" rows={3} value={form.contractor_action ?? anomaly.contractor_action ?? ""} onChange={set("contractor_action")} />
          </Field>
          <Field label="Action owner" htmlFor="eshs-owner">
            <Input id="eshs-owner" value={form.action_owner_name ?? anomaly.action_owner_name ?? ""} onChange={set("action_owner_name")} />
          </Field>
          <Field label="Owner e-mail" htmlFor="eshs-owner-email">
            <Input
              id="eshs-owner-email"
              type="email"
              autoCapitalize="none"
              value={form.action_owner_email ?? anomaly.action_owner_email ?? ""}
              onChange={set("action_owner_email")}
            />
          </Field>
          <Field label="Owner phone" htmlFor="eshs-owner-phone">
            <Input id="eshs-owner-phone" type="tel" value={form.action_owner_phone ?? anomaly.action_owner_phone ?? ""} onChange={set("action_owner_phone")} />
          </Field>
          <Field label="Progress" htmlFor="eshs-progress" hint="Where the work has got to.">
            <Textarea id="eshs-progress" rows={4} value={form.progress_notes ?? anomaly.progress_notes ?? ""} onChange={set("progress_notes")} />
          </Field>
        </div>
      </Drawer>

      <Drawer
        open={panel === "submit"}
        onClose={() => setPanel(null)}
        title="Submit for verification"
        description="The supervision team checks your closure evidence and either closes the anomaly or returns it to you."
        width="md"
        footer={
          <>
            <Button variant="secondary" onClick={() => setPanel(null)}>Cancel</Button>
            <Button
              variant="primary"
              loading={action.isPending}
              disabled={!sync.online || closureEvidence.length === 0}
              onClick={() => void run("submit", { notes: form.notes || null }, "Submitted for verification")}
            >
              Submit
            </Button>
          </>
        }
      >
        <div className="space-y-5">
          {offlineNote ? <p className="text-sm text-warning-700">{offlineNote}</p> : null}
          {closureEvidence.length === 0 ? (
            <p className="rounded-lg bg-warning-50 p-3 text-sm text-warning-800">
              Upload at least one piece of closure evidence first, under &ldquo;Closure evidence&rdquo; on this page.
            </p>
          ) : (
            <p className="text-sm text-ink-600">
              {closureEvidence.length} {closureEvidence.length === 1 ? "file" : "files"} of closure evidence will be reviewed.
            </p>
          )}
          <Field label="Closure note" optional htmlFor="eshs-submit-notes">
            <Textarea
              id="eshs-submit-notes"
              rows={4}
              value={form.notes ?? ""}
              onChange={set("notes")}
              placeholder="Barricades installed on all four sides; shoring in place; photos attached."
            />
          </Field>
        </div>
      </Drawer>

      <Drawer
        open={panel === "close"}
        onClose={() => setPanel(null)}
        title="Verify and close"
        description="Only the project supervision team closes an anomaly, on the closure evidence."
        width="md"
        footer={
          <>
            <Button variant="secondary" onClick={() => setPanel(null)}>Cancel</Button>
            <Button
              variant="primary"
              loading={action.isPending}
              disabled={!sync.online || closureEvidence.length === 0}
              onClick={() =>
                void run("close", { notes: form.notes || null, closed_on: form.closed_on || null }, `${anomaly.reference} closed`)
              }
            >
              Close the anomaly
            </Button>
          </>
        }
      >
        <div className="space-y-5">
          {offlineNote ? <p className="text-sm text-warning-700">{offlineNote}</p> : null}
          <Field label="Date closed" htmlFor="eshs-closed-on">
            <Input
              id="eshs-closed-on"
              type="date"
              max={new Date().toISOString().slice(0, 10)}
              value={form.closed_on ?? new Date().toISOString().slice(0, 10)}
              onChange={set("closed_on")}
            />
          </Field>
          <Field label="Verification note" optional htmlFor="eshs-close-notes">
            <Textarea
              id="eshs-close-notes"
              rows={4}
              value={form.notes ?? ""}
              onChange={set("notes")}
              placeholder="Verified on site with the contractor HSE lead."
            />
          </Field>
        </div>
      </Drawer>

      <Drawer
        open={panel === "return"}
        onClose={() => setPanel(null)}
        title="Return to the contractor"
        description="The anomaly stays In progress and the contractor is told why."
        width="md"
        footer={
          <>
            <Button variant="secondary" onClick={() => setPanel(null)}>Cancel</Button>
            <Button
              variant="danger"
              loading={action.isPending}
              disabled={!sync.online || (form.reason ?? "").trim().length < 5}
              onClick={() => void run("return", { reason: form.reason ?? "" }, "Returned to the contractor")}
            >
              Return it
            </Button>
          </>
        }
      >
        <div className="space-y-5">
          {offlineNote ? <p className="text-sm text-warning-700">{offlineNote}</p> : null}
          <Field label="Reason" required htmlFor="eshs-return-reason" hint="What is missing or wrong, so the contractor can put it right.">
            <Textarea id="eshs-return-reason" rows={4} value={form.reason ?? ""} onChange={set("reason")} />
          </Field>
        </div>
      </Drawer>

      <ConfirmDialog
        open={panel === "archive"}
        onCancel={() => setPanel(null)}
        onConfirm={() => void run("archive", {}, `${anomaly.reference} archived`)}
        title={`Archive ${anomaly.reference}?`}
        description="It leaves the register and the dashboard. Nothing is deleted: the record and its history stay in the audit trail."
        confirmLabel="Archive"
        tone="danger"
        loading={action.isPending}
      />
    </div>
  );
}

function Notice({ tone, icon, children }: { tone: "warning" | "danger" | "success"; icon: ReactNode; children: ReactNode }) {
  const styles = {
    warning: "border-warning-500/25 bg-warning-50 text-warning-800",
    danger: "border-danger-500/25 bg-danger-50 text-danger-800",
    success: "border-success-500/25 bg-success-50 text-success-800",
  }[tone];

  return (
    <div className={`mb-6 flex items-start gap-3 rounded-xl border p-4 ${styles}`} role="status">
      {icon}
      <p className="text-sm">{children}</p>
    </div>
  );
}
