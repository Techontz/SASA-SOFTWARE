"use client";

import { useQueryClient } from "@tanstack/react-query";
import { BadgeCheck, CheckCircle2, Users } from "lucide-react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useState } from "react";
import { AttachmentPanel } from "@/components/app/AttachmentPanel";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { DetailField, DetailGrid, PageHeader } from "@/components/ui/DetailLayout";
import { Drawer } from "@/components/ui/Drawer";
import { Field, Input, Select, Textarea } from "@/components/ui/Form";
import { PriorityBadge, StatusBadge } from "@/components/ui/StatusBadge";
import { ErrorState, LoadingState } from "@/components/ui/States";
import { apiRequest } from "@/lib/api/client";
import { useCommitment } from "@/lib/api/hooks";
import { formatDate, humanise } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";
import { useToast } from "@/providers/ToastProvider";

export default function CommitmentDetailPage() {
  const params = useParams<{ id: string }>();
  const id = Number(params.id);
  const { can } = useSession();
  const toast = useToast();
  const queryClient = useQueryClient();
  const { data, isLoading, isError, error, refetch } = useCommitment(id);

  const [drawer, setDrawer] = useState<"status" | "verify" | null>(null);
  const [form, setForm] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  if (isLoading) return <LoadingState label="Opening the commitment" />;
  if (isError || !data) return <ErrorState error={error} onRetry={() => void refetch()} />;

  const commitment = data.data;

  const run = async (path: string, body: Record<string, unknown>, message: string) => {
    setBusy(true);
    try {
      await apiRequest(`/commitments/${id}${path}`, { method: "POST", body });
      await queryClient.invalidateQueries({ queryKey: ["commitment", id] });
      await queryClient.invalidateQueries({ queryKey: ["commitments"] });
      toast.success(message);
      setDrawer(null);
      setForm({});
      void refetch();
    } catch (error) {
      toast.error("That did not go through", error instanceof Error ? error.message : undefined);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="mx-auto max-w-5xl">
      <PageHeader
        backHref="/commitments"
        eyebrow={`Module 2 · Commitment · ${commitment.reference}`}
        title={commitment.commitment_text}
        meta={
          <>
            <StatusBadge status={commitment.status} label={commitment.status_label} />
            <PriorityBadge priority={commitment.risk_level} prefix="Risk" />
            <StatusBadge status={commitment.verification_status} />
            <span className={commitment.is_overdue ? "text-sm font-medium text-danger-700" : "text-sm text-ink-600"}>
              {commitment.due_date ? `Due ${formatDate(commitment.due_date)}` : "No due date set"}
              {commitment.days_until_due !== null && commitment.days_until_due < 0
                ? ` · ${Math.abs(commitment.days_until_due)} days over`
                : ""}
            </span>
          </>
        }
        actions={
          <>
            {can("commitment.manage") && commitment.status !== "fulfilled" ? (
              <Button variant="primary" icon={<CheckCircle2 className="h-4 w-4" />} onClick={() => setDrawer("status")}>
                Update progress
              </Button>
            ) : null}
            {can("commitment.verify") && commitment.status === "fulfilled" ? (
              <Button variant="primary" icon={<BadgeCheck className="h-4 w-4" />} onClick={() => setDrawer("verify")}>
                Verify delivery
              </Button>
            ) : null}
          </>
        }
      />

      {commitment.status === "fulfilled" && commitment.verification_status === "unverified" ? (
        <div className="mb-6 flex items-start gap-3 rounded-xl border border-warning-500/25 bg-warning-50 p-4">
          <BadgeCheck className="mt-0.5 h-5 w-5 shrink-0 text-warning-600" aria-hidden />
          <p className="text-sm text-warning-800">
            Marked delivered but not yet verified. Verification is a separate act, by a second person, so a
            commitment is not simply closed by whoever owned it.
          </p>
        </div>
      ) : null}

      <div className="grid gap-4 lg:grid-cols-[1.5fr_1fr]">
        <div className="space-y-4">
          <Card>
            <CardHeader title="What was promised" />
            <CardBody className="space-y-5">
              <p className="whitespace-pre-line leading-relaxed text-ink-800">{commitment.commitment_text}</p>
              {commitment.evidence_notes ? (
                <div className="rounded-lg bg-success-50 p-4">
                  <p className="sasa-eyebrow mb-1.5">Evidence of delivery</p>
                  <p className="leading-relaxed text-ink-800">{commitment.evidence_notes}</p>
                </div>
              ) : null}
              {commitment.notes ? (
                <div>
                  <p className="sasa-eyebrow mb-1.5">Notes</p>
                  <p className="whitespace-pre-line leading-relaxed text-ink-700">{commitment.notes}</p>
                </div>
              ) : null}
            </CardBody>
          </Card>

          <Card>
            <CardHeader title="Evidence files" description="Photographs, sign-off sheets, delivery notes." />
            <CardBody>
              <AttachmentPanel
                entity="commitment"
                entityUuid={commitment.client_uuid}
                serverId={commitment.id}
                attachments={commitment.attachments ?? []}
                canUpload={can("commitment.manage")}
                onChanged={() => void refetch()}
              />
            </CardBody>
          </Card>
        </div>

        <div className="space-y-4">
          <Card>
            <CardHeader title="Accountability" />
            <CardBody>
              <DetailGrid columns={2}>
                <DetailField label="Owner">{commitment.owner?.name ?? "Unassigned"}</DetailField>
                <DetailField label="Team">{commitment.owner_team ?? "—"}</DetailField>
                <DetailField label="Due">{formatDate(commitment.due_date)}</DetailField>
                <DetailField label="Completed">{formatDate(commitment.completed_on)}</DetailField>
                <DetailField label="Verification">{humanise(commitment.verification_status)}</DetailField>
                <DetailField label="Verified by">{commitment.verified_by ?? "—"}</DetailField>
              </DetailGrid>
            </CardBody>
          </Card>

          <Card>
            <CardHeader title="Where it came from" />
            <CardBody>
              <DetailGrid columns={2}>
                <DetailField label="Source">{humanise(commitment.source_type)}</DetailField>
                <DetailField label="Made on">{formatDate(commitment.source_date)}</DetailField>
                {commitment.engagement ? (
                  <DetailField label="Engagement" wide>
                    <Link href={`/engagements/${commitment.engagement.id}`} className="text-brand-700 hover:underline">
                      {commitment.engagement.reference} — {commitment.engagement.topic}
                    </Link>
                  </DetailField>
                ) : null}
                {commitment.grievance ? (
                  <DetailField label="Grievance" wide>
                    <Link href={`/grievances/${commitment.grievance.id}`} className="text-brand-700 hover:underline">
                      {commitment.grievance.reference}
                    </Link>
                  </DetailField>
                ) : null}
                <DetailField label="Location">{commitment.location.path ?? "—"}</DetailField>
              </DetailGrid>
            </CardBody>
          </Card>

          {(commitment.stakeholders ?? []).length > 0 ? (
            <Card>
              <CardHeader title="Who it was promised to" />
              <CardBody>
                <ul className="space-y-2">
                  {(commitment.stakeholders ?? []).map((stakeholder) => (
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

      <Drawer
        open={drawer === "status"}
        onClose={() => setDrawer(null)}
        title="Update progress"
        description="Where this commitment has got to, and the evidence for it."
        width="md"
        footer={
          <>
            <Button variant="secondary" onClick={() => setDrawer(null)}>Cancel</Button>
            <Button
              variant="primary"
              loading={busy}
              onClick={() =>
                void run(
                  "/status",
                  {
                    status: form.status ?? "in_progress",
                    completed_on: form.completed_on || null,
                    evidence_notes: form.evidence_notes || null,
                  },
                  "Commitment updated",
                )
              }
            >
              Save
            </Button>
          </>
        }
      >
        <div className="space-y-5">
          <Field label="Status" required htmlFor="commitment-status">
            <Select
              id="commitment-status"
              value={form.status ?? commitment.status}
              onChange={(event) => setForm((current) => ({ ...current, status: event.target.value }))}
            >
              <option value="open">Open — not started</option>
              <option value="in_progress">In progress</option>
              <option value="fulfilled">Delivered</option>
              <option value="cancelled">Cancelled</option>
            </Select>
          </Field>

          {(form.status ?? commitment.status) === "fulfilled" ? (
            <Field label="Delivered on" htmlFor="completed-on">
              <Input
                id="completed-on"
                type="date"
                value={form.completed_on ?? new Date().toISOString().slice(0, 10)}
                onChange={(event) => setForm((current) => ({ ...current, completed_on: event.target.value }))}
              />
            </Field>
          ) : null}

          <Field label="Evidence" optional htmlFor="evidence" hint="What proves it was done. Attach photographs or sign-off sheets on the record too.">
            <Textarea
              id="evidence"
              rows={4}
              value={form.evidence_notes ?? commitment.evidence_notes ?? ""}
              onChange={(event) => setForm((current) => ({ ...current, evidence_notes: event.target.value }))}
            />
          </Field>
        </div>
      </Drawer>

      <Drawer
        open={drawer === "verify"}
        onClose={() => setDrawer(null)}
        title="Verify delivery"
        description="Confirm the commitment was actually delivered — ideally with the stakeholder present."
        width="sm"
        footer={
          <>
            <Button variant="secondary" onClick={() => setDrawer(null)}>Cancel</Button>
            <Button
              variant="primary"
              loading={busy}
              onClick={() =>
                void run(
                  "/verify",
                  { verification_status: form.verification_status ?? "verified", notes: form.notes },
                  "Verification recorded",
                )
              }
            >
              Record verification
            </Button>
          </>
        }
      >
        <div className="space-y-5">
          <Field label="Outcome" required htmlFor="verification">
            <Select
              id="verification"
              value={form.verification_status ?? "verified"}
              onChange={(event) => setForm((current) => ({ ...current, verification_status: event.target.value }))}
            >
              <option value="verified">Verified — it was delivered</option>
              <option value="disputed">Disputed — the stakeholder disagrees</option>
              <option value="unverified">Not yet verified</option>
            </Select>
          </Field>
          <Field label="Notes" optional htmlFor="verify-notes">
            <Textarea
              id="verify-notes"
              rows={4}
              value={form.notes ?? ""}
              onChange={(event) => setForm((current) => ({ ...current, notes: event.target.value }))}
              placeholder="Verified on site with the village chairperson present."
            />
          </Field>
        </div>
      </Drawer>
    </div>
  );
}
