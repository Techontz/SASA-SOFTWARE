"use client";

import { Archive, CheckCircle2, ClipboardCheck } from "lucide-react";
import { useParams, useRouter } from "next/navigation";
import { useState } from "react";
import { AttachmentPanel } from "@/components/app/AttachmentPanel";
import { IncidentStatusBadge, SeriousBadge } from "@/components/incidents/IncidentBadges";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { DetailField, DetailGrid, PageHeader } from "@/components/ui/DetailLayout";
import { ConfirmDialog, Drawer } from "@/components/ui/Drawer";
import { Field, Textarea } from "@/components/ui/Form";
import { ErrorState, LoadingState, PermissionDenied } from "@/components/ui/States";
import { useArchiveIncident, useIncident, useIncidentStatus } from "@/lib/api/incidents";
import { formatDateTime } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";
import { useSync } from "@/providers/SyncProvider";
import { useToast } from "@/providers/ToastProvider";

export default function IncidentDetailPage() {
  const params = useParams<{ id: string }>();
  const id = Number(params.id);
  const router = useRouter();
  const { can } = useSession();
  const sync = useSync();
  const toast = useToast();
  const allowed = can("incident.view");
  const { data, isLoading, isError, error, refetch } = useIncident(id, { enabled: allowed && Boolean(id) });
  const changeStatus = useIncidentStatus(id);
  const archive = useArchiveIncident(id);

  const [drawer, setDrawer] = useState<"under_review" | "closed" | null>(null);
  const [note, setNote] = useState("");
  const [confirmArchive, setConfirmArchive] = useState(false);

  if (!allowed) return <PermissionDenied what="incident notifications" />;
  if (isLoading) return <LoadingState label="Opening the incident" />;
  if (isError || !data) return <ErrorState error={error} onRetry={() => void refetch()} />;

  const incident = data.data;
  const canManage = incident.permissions.can_manage && can("incident.manage");

  const submitStatus = async () => {
    if (!drawer) return;
    try {
      await changeStatus.mutateAsync({ status: drawer, review_note: note.trim() || null });
      toast.success(drawer === "closed" ? `${incident.reference} closed` : `${incident.reference} is under review`);
      setDrawer(null);
      setNote("");
      void refetch();
    } catch (failure) {
      toast.error("That did not go through", failure instanceof Error ? failure.message : undefined);
    }
  };

  return (
    <div className="mx-auto max-w-5xl">
      <PageHeader
        backHref="/incidents"
        eyebrow={`Incident · ${incident.reference}`}
        title={incident.incident_type_label ?? incident.incident_type}
        meta={
          <>
            <IncidentStatusBadge status={incident.status} />
            {incident.is_serious ? <SeriousBadge /> : null}
            <span className="text-sm text-ink-600">{formatDateTime(incident.occurred_at)}</span>
          </>
        }
        actions={
          canManage && incident.status !== "closed" ? (
            <>
              {incident.status === "submitted" ? (
                <Button
                  variant="secondary"
                  icon={<ClipboardCheck className="h-4 w-4" />}
                  disabled={!sync.online}
                  onClick={() => setDrawer("under_review")}
                >
                  Start review
                </Button>
              ) : null}
              <Button
                variant="primary"
                icon={<CheckCircle2 className="h-4 w-4" />}
                disabled={!sync.online}
                onClick={() => setDrawer("closed")}
              >
                Close incident
              </Button>
            </>
          ) : null
        }
      />

      {incident.is_serious && incident.status !== "closed" ? (
        <div className="mb-6 flex flex-wrap items-center gap-3 rounded-xl border border-danger-500/25 bg-danger-50 p-4">
          <SeriousBadge />
          <p className="min-w-0 flex-1 text-sm text-danger-800">
            Potential consequence {incident.potential_consequence_label}. Serious incidents need a review without delay.
          </p>
        </div>
      ) : null}

      <div className="grid gap-4 lg:grid-cols-[1.5fr_1fr]">
        <div className="min-w-0 space-y-4">
          <Card>
            <CardHeader title="Summary" />
            <CardBody>
              <p className="whitespace-pre-line leading-relaxed text-ink-800">{incident.summary}</p>
            </CardBody>
          </Card>

          <Card>
            <CardHeader title="Photos, videos and documents" description="Evidence submitted with the report and added during review." />
            <CardBody>
              <AttachmentPanel
                entity="incident"
                entityUuid={incident.client_uuid}
                serverId={incident.id}
                attachments={incident.attachments ?? []}
                canUpload={incident.permissions.can_add_evidence}
                onChanged={() => void refetch()}
              />
            </CardBody>
          </Card>

          {incident.status !== "submitted" ? (
            <Card>
              <CardHeader title="Review" />
              <CardBody className="space-y-5">
                <DetailGrid columns={2}>
                  <DetailField label="Reviewed by">{incident.reviewed_by ?? "—"}</DetailField>
                  <DetailField label="Review started">{formatDateTime(incident.reviewed_at)}</DetailField>
                  <DetailField label="Closed by">{incident.closed_by ?? "—"}</DetailField>
                  <DetailField label="Closed">{formatDateTime(incident.closed_at)}</DetailField>
                </DetailGrid>
                {incident.review_note ? (
                  <div>
                    <p className="sasa-eyebrow mb-1.5">Review note</p>
                    <p className="whitespace-pre-line leading-relaxed text-ink-700">{incident.review_note}</p>
                  </div>
                ) : null}
              </CardBody>
            </Card>
          ) : null}
        </div>

        <div className="min-w-0 space-y-4">
          <Card>
            <CardHeader title="Classification" />
            <CardBody>
              <DetailGrid columns={2}>
                <DetailField label="Category" wide>{incident.category_label}</DetailField>
                <DetailField label="Incident type" wide>{incident.incident_type_label}</DetailField>
                <DetailField label="Actual consequence">{incident.actual_consequence_label}</DetailField>
                <DetailField label="Potential consequence">{incident.potential_consequence_label}</DetailField>
              </DetailGrid>
            </CardBody>
          </Card>

          <Card>
            <CardHeader title="Where and who" />
            <CardBody>
              <DetailGrid columns={2}>
                <DetailField label="Date and time" wide>{formatDateTime(incident.occurred_at)}</DetailField>
                <DetailField label="Company" wide>{incident.company ?? "—"}</DetailField>
                <DetailField label="Location" wide>{incident.location.path ?? "—"}</DetailField>
                <DetailField label="Site detail" wide>{incident.site_detail ?? "—"}</DetailField>
                <DetailField label="Reported by">{incident.reported_by?.name ?? "—"}</DetailField>
                <DetailField label="Contractor manager">{incident.contractor_manager ?? "—"}</DetailField>
                <DetailField label="Consultant's resident engineer" wide>{incident.resident_engineer ?? "—"}</DetailField>
              </DetailGrid>
            </CardBody>
          </Card>

          {canManage ? (
            <Button variant="ghost" icon={<Archive className="h-4 w-4" />} onClick={() => setConfirmArchive(true)}>
              Archive this report
            </Button>
          ) : null}
        </div>
      </div>

      <Drawer
        open={drawer !== null}
        onClose={() => setDrawer(null)}
        title={drawer === "closed" ? "Close this incident" : "Start the review"}
        description={
          drawer === "closed"
            ? "Closing records who closed it and when. A closed incident cannot be reopened."
            : "Tells everyone this report has been picked up."
        }
        width="md"
        footer={
          <>
            <Button variant="secondary" onClick={() => setDrawer(null)}>
              Cancel
            </Button>
            <Button variant="primary" loading={changeStatus.isPending} disabled={!sync.online} onClick={() => void submitStatus()}>
              {drawer === "closed" ? "Close incident" : "Start review"}
            </Button>
          </>
        }
      >
        <Field
          label="Review note"
          optional
          htmlFor="review-note"
          hint="Seen only by people who manage incidents."
        >
          <Textarea
            id="review-note"
            rows={5}
            value={note}
            onChange={(event) => setNote(event.target.value)}
            placeholder={incident.review_note ?? "Findings, corrective actions, who is following up."}
          />
        </Field>
      </Drawer>

      <ConfirmDialog
        open={confirmArchive}
        onCancel={() => setConfirmArchive(false)}
        onConfirm={async () => {
          try {
            await archive.mutateAsync(undefined);
            toast.success(`${incident.reference} archived`);
            router.push("/incidents");
          } catch (failure) {
            toast.error("We could not archive it", failure instanceof Error ? failure.message : undefined);
          }
        }}
        title="Archive this report?"
        description="It leaves the register and the dashboard but is kept, with its history, in the audit trail."
        confirmLabel="Archive"
        tone="danger"
        loading={archive.isPending}
      />
    </div>
  );
}
