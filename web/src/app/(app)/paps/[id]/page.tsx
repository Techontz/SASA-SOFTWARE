"use client";

import { useQueryClient } from "@tanstack/react-query";
import { Archive, Link2, Pencil } from "lucide-react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useState } from "react";
import { AttachmentPanel } from "@/components/app/AttachmentPanel";
import { PapGrievancesPanel } from "@/components/pap/PapGrievancesPanel";
import { PapSectionPanel } from "@/components/pap/PapSectionPanel";
import { PAP_SECTIONS } from "@/components/pap/sections";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { DetailField, DetailGrid, PageHeader, Tabs, type TabDefinition } from "@/components/ui/DetailLayout";
import { ConfirmDialog } from "@/components/ui/Drawer";
import { StatusBadge } from "@/components/ui/StatusBadge";
import { ErrorState, LoadingState, PermissionDenied } from "@/components/ui/States";
import { apiRequest } from "@/lib/api/client";
import { usePap, usePapOptions } from "@/lib/api/pap";
import { formatMoney, formatQuantity } from "@/lib/pap/format";
import { formatDate } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";
import { useSync } from "@/providers/SyncProvider";
import { useToast } from "@/providers/ToastProvider";
import type { Pap, PapDetailMeta } from "@/types/pap";

export default function PapDetailPage() {
  const params = useParams<{ id: string }>();
  const id = Number(params.id);
  const router = useRouter();
  const toast = useToast();
  const sync = useSync();
  const queryClient = useQueryClient();
  const { can } = useSession();
  const { data, isLoading, isError, error, refetch } = usePap(id);
  const { data: optionData } = usePapOptions();
  const [archiveOpen, setArchiveOpen] = useState(false);
  const [busy, setBusy] = useState(false);

  if (!can("pap.view")) return <PermissionDenied what="the PAP register" />;
  if (isLoading) return <LoadingState label="Opening the PAP" />;
  if (isError || !data) return <ErrorState error={error} onRetry={() => void refetch()} />;

  const pap = data.data;
  const meta = data.meta;
  const lists = optionData?.data.lists ?? {};
  const currency = optionData?.data.currency ?? "TZS";
  const editable = !pap.archived_at;

  const archive = async () => {
    setBusy(true);
    try {
      await apiRequest(`/paps/${id}`, { method: "DELETE" });
      await queryClient.invalidateQueries({ queryKey: ["paps"] });
      await queryClient.invalidateQueries({ queryKey: ["pap-summary"] });
      toast.success(`${pap.reference} archived`, "Its stakeholder record was archived too. Nothing is deleted.");
      router.push("/paps");
    } catch (caught) {
      toast.error("We could not archive this PAP", caught instanceof Error ? caught.message : undefined);
    } finally {
      setBusy(false);
    }
  };

  const tabs: TabDefinition[] = [
    {
      key: "profile",
      label: "Profile",
      content: <ProfileTab pap={pap} meta={meta} currency={currency} canUpload={editable && can("pap.manage")} onChanged={() => void refetch()} />,
    },
    ...PAP_SECTIONS.map((section) => ({
      key: section.key,
      label: section.label,
      count: meta.counts[section.key] ?? 0,
      content: <PapSectionPanel papId={pap.id} section={section} lists={lists} currency={currency} editable={editable} />,
    })),
    { key: "grievances", label: "Grievances", content: <PapGrievancesPanel pap={pap} /> },
  ];

  return (
    <div>
      <PageHeader
        backHref="/paps"
        eyebrow={`PAP master register · ${pap.reference}`}
        title={pap.full_name}
        description={pap.location.display || undefined}
        meta={
          <>
            {pap.archived_at ? <StatusBadge status="archived" /> : <StatusBadge status="active" />}
            <span className="font-mono text-sm text-ink-600">Household {pap.household_id ?? "—"}</span>
            {pap.is_vulnerable ? <StatusBadge status="at_risk" label={pap.vulnerability_label ?? "Vulnerable"} showIcon={false} /> : null}
            {pap.stakeholder ? (
              <Link href={`/stakeholders/${pap.stakeholder.id}`} className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">
                <Link2 className="h-3.5 w-3.5" aria-hidden />
                Stakeholder {pap.stakeholder.reference ?? `#${pap.stakeholder.id}`}
              </Link>
            ) : null}
          </>
        }
        actions={
          editable && can("pap.manage") ? (
            <>
              <Link href={`/paps/${id}/edit`}>
                <Button variant="secondary" icon={<Pencil className="h-4 w-4" />}>
                  Edit profile
                </Button>
              </Link>
              <Button variant="ghost" icon={<Archive className="h-4 w-4" />} onClick={() => setArchiveOpen(true)} disabled={!sync.online}>
                Archive
              </Button>
            </>
          ) : null
        }
      />

      {pap.archived_at ? (
        <p className="mb-6 rounded-lg bg-surface-sunken p-4 text-sm text-ink-700">
          Archived on {formatDate(pap.archived_at)}. The record is read-only and kept in the audit trail.
        </p>
      ) : null}

      <dl className="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Figure label="Approved valuation" value={meta.totals.approved_valuation === null ? "Not approved" : formatMoney(meta.totals.approved_valuation, currency)} />
        <Figure label="Paid" value={formatMoney(meta.totals.amount_paid, currency)} />
        <Figure label="Outstanding" value={formatMoney(meta.totals.outstanding, currency)} />
        <Figure label="Land affected" value={formatQuantity(meta.totals.affected_land_ha, "ha")} />
      </dl>

      <Tabs tabs={tabs} />

      <ConfirmDialog
        open={archiveOpen}
        onCancel={() => setArchiveOpen(false)}
        onConfirm={() => void archive()}
        title={`Archive ${pap.reference}?`}
        description="The PAP and its stakeholder record leave the active registers. Everything stays in the audit trail and on the cases and engagements it appears on."
        confirmLabel="Archive"
        tone="danger"
        loading={busy}
      />
    </div>
  );
}

function Figure({ label, value }: { label: string; value: string }) {
  return (
    <div className="sasa-card min-w-0 px-4 py-3">
      <dt className="sasa-eyebrow">{label}</dt>
      <dd className="tabular mt-1 break-words text-lg font-semibold text-ink-900">{value}</dd>
    </div>
  );
}

function ProfileTab({
  pap,
  meta,
  currency,
  canUpload,
  onChanged,
}: {
  pap: Pap;
  meta: PapDetailMeta;
  currency: string;
  canUpload: boolean;
  onChanged: () => void;
}) {
  const photo = pap.photo;

  return (
    <div className="grid gap-4 lg:grid-cols-[1.5fr_1fr]">
      <div className="space-y-4">
        <Card>
          <CardHeader eyebrow="Part A" title="Profile" />
          <CardBody>
            <DetailGrid>
              <DetailField label="PAP ID">{pap.reference}</DetailField>
              <DetailField label="Household ID">{pap.household_id ?? "—"}</DetailField>
              <DetailField label="Project">{pap.project_name ?? "—"}</DetailField>
              <DetailField label="Full name">{pap.full_name}</DetailField>
              <DetailField label="National ID (NIDA)">
                {pap.sensitive_withheld ? <span className="text-ink-500">Withheld — needs sensitive access</span> : (pap.national_id ?? "—")}
              </DetailField>
              <DetailField label="Gender">{pap.gender_label ?? "—"}</DetailField>
              <DetailField label="Date of birth">{formatDate(pap.date_of_birth)}</DetailField>
              <DetailField label="Age">
                {pap.age === null ? "—" : `${pap.age}${pap.age_is_derived ? "" : " (stated)"}`}
              </DetailField>
              <DetailField label="Marital status">{pap.marital_status_label ?? "—"}</DetailField>
              <DetailField label="Phone number">{pap.phone ?? "—"}</DetailField>
              <DetailField label="Alternative contact">{pap.alternative_contact ?? "—"}</DetailField>
              <DetailField label="Vulnerability category">{pap.vulnerability_label ?? "None"}</DetailField>
              <DetailField label="Next of kin">{pap.next_of_kin ?? "—"}</DetailField>
              <DetailField label="Next of kin contact">{pap.next_of_kin_contact ?? "—"}</DetailField>
              {pap.remarks ? (
                <DetailField label="Remarks" wide>
                  <span className="whitespace-pre-line">{pap.remarks}</span>
                </DetailField>
              ) : null}
            </DetailGrid>
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Location" />
          <CardBody>
            <DetailGrid>
              <DetailField label="Region">{pap.location.region ?? "—"}</DetailField>
              <DetailField label="District">{pap.location.district ?? "—"}</DetailField>
              <DetailField label="Ward">{pap.location.ward ?? "—"}</DetailField>
              <DetailField label="Village">{pap.location.village ?? "—"}</DetailField>
              <DetailField label="Kitongoji">{pap.location.kitongoji ?? "—"}</DetailField>
              <DetailField label="GPS coordinates">
                {pap.gps.latitude !== null && pap.gps.longitude !== null ? `${pap.gps.latitude}, ${pap.gps.longitude}` : "—"}
              </DetailField>
              <DetailField label="Physical address" wide>{pap.physical_address ?? "—"}</DetailField>
            </DetailGrid>
          </CardBody>
        </Card>
      </div>

      <div className="space-y-4">
        <Card>
          <CardHeader title="Photograph" />
          <CardBody>
            {photo?.is_image ? (
              // eslint-disable-next-line @next/next/no-img-element -- signed, short-lived URL from the API
              <img src={photo.download_url} alt={`Photograph of ${pap.full_name}`} className="aspect-square w-full max-w-xs rounded-lg object-cover" />
            ) : (
              <p className="text-sm text-ink-600">No photograph yet. Add one under Files with “Take a photo”.</p>
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Compensation at a glance" />
          <CardBody>
            <DetailGrid columns={2}>
              <DetailField label="Entitlements value">{formatMoney(meta.totals.entitlements_value, currency)}</DetailField>
              <DetailField label="Crop compensation">{formatMoney(meta.totals.crop_compensation, currency)}</DetailField>
              <DetailField label="Approved for payment">{formatMoney(meta.totals.amount_approved, currency)}</DetailField>
              <DetailField label="Outstanding">{formatMoney(meta.totals.outstanding, currency)}</DetailField>
            </DetailGrid>
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Files" description="Photograph, scanned documents and signed forms." />
          <CardBody>
            <AttachmentPanel
              entity="pap"
              entityUuid={pap.client_uuid}
              serverId={pap.id}
              attachments={pap.attachments ?? []}
              canUpload={canUpload}
              onChanged={onChanged}
            />
          </CardBody>
        </Card>
      </div>
    </div>
  );
}
