"use client";

import { useQueryClient } from "@tanstack/react-query";
import { ArrowUpRight, ShieldAlert } from "lucide-react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useState } from "react";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { DetailField, DetailGrid, PageHeader } from "@/components/ui/DetailLayout";
import { Drawer } from "@/components/ui/Drawer";
import { Field, Select } from "@/components/ui/Form";
import { PriorityBadge, StatusBadge } from "@/components/ui/StatusBadge";
import { ErrorState, LoadingState } from "@/components/ui/States";
import { apiRequest } from "@/lib/api/client";
import { useConcern, useGrievanceCategories } from "@/lib/api/hooks";
import { formatDate } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";
import { useToast } from "@/providers/ToastProvider";
import type { Grievance } from "@/types/api";

export default function ConcernDetailPage() {
  const params = useParams<{ id: string }>();
  const id = Number(params.id);
  const router = useRouter();
  const toast = useToast();
  const queryClient = useQueryClient();
  const { can } = useSession();
  const { data, isLoading, isError, refetch } = useConcern(id);
  const { data: categories } = useGrievanceCategories();

  const [escalateOpen, setEscalateOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [form, setForm] = useState<Record<string, string>>({});

  if (isLoading) return <LoadingState label="Opening the concern" />;
  if (isError || !data) return <ErrorState onRetry={() => void refetch()} />;

  const concern = data.data;

  const escalate = async () => {
    setBusy(true);
    try {
      const response = await apiRequest<{ data: Grievance }>(`/concerns/${id}/escalate`, {
        method: "POST",
        body: {
          confidentiality: form.confidentiality || "normal",
          severity: form.severity ? Number(form.severity) : undefined,
          category_id: form.category_id ? Number(form.category_id) : undefined,
        },
      });

      await queryClient.invalidateQueries({ queryKey: ["concern", id] });
      toast.success(
        `Escalated as ${response.data.reference}`,
        "The concern's own words carried across — nothing was retyped.",
      );
      router.push(`/grievances/${response.data.id}`);
    } catch (error) {
      toast.error("We could not escalate this concern", error instanceof Error ? error.message : undefined);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="mx-auto max-w-5xl">
      <PageHeader
        backHref="/concerns"
        eyebrow={`Module 2 · Concern · ${concern.reference}`}
        title={concern.title}
        meta={
          <>
            <StatusBadge status={concern.status} />
            <PriorityBadge priority={concern.severity_hint} prefix="Seriousness" />
            <span className="text-sm text-ink-600">Raised {formatDate(concern.raised_on)}</span>
          </>
        }
        actions={
          !concern.grievance && can("concern.escalate") ? (
            <Button variant="accent" icon={<ShieldAlert className="h-4 w-4" />} onClick={() => setEscalateOpen(true)}>
              Escalate to a grievance
            </Button>
          ) : null
        }
      />

      {concern.grievance ? (
        <div className="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-danger-500/25 bg-danger-50 p-4">
          <div className="flex items-center gap-3">
            <ShieldAlert className="h-5 w-5 shrink-0 text-danger-600" aria-hidden />
            <p className="text-sm text-danger-800">
              This concern became a formal grievance. Its substance carried across without being retyped.
            </p>
          </div>
          <Link href={`/grievances/${concern.grievance.id}`}>
            <Button variant="secondary" iconRight={<ArrowUpRight className="h-4 w-4" />}>
              Open {concern.grievance.reference}
            </Button>
          </Link>
        </div>
      ) : null}

      <div className="grid gap-4 lg:grid-cols-[1.5fr_1fr]">
        <Card>
          <CardHeader title="What was raised" description="In the words it was raised." />
          <CardBody className="space-y-5">
            <p data-testid="concern-description" className="whitespace-pre-line leading-relaxed text-ink-800">
              {concern.description}
            </p>
            {concern.response ? (
              <div className="rounded-lg bg-success-50 p-4">
                <p className="sasa-eyebrow mb-1.5">How it was addressed</p>
                <p className="leading-relaxed text-ink-800">{concern.response}</p>
              </div>
            ) : null}
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Where it came from" />
          <CardBody>
            <DetailGrid columns={2}>
              <DetailField label="Raised by">{concern.stakeholder?.name ?? concern.raised_by ?? "Not recorded"}</DetailField>
              <DetailField label="Date">{formatDate(concern.raised_on)}</DetailField>
              <DetailField label="Engagement" wide>
                {concern.engagement ? (
                  <Link href={`/engagements/${concern.engagement.id}`} className="text-brand-700 hover:underline">
                    {concern.engagement.reference} — {concern.engagement.topic}
                  </Link>
                ) : (
                  "Recorded outside an engagement"
                )}
              </DetailField>
              <DetailField label="Topic">{concern.category?.name ?? "Not classified"}</DetailField>
              <DetailField label="Owner">{concern.owner?.name ?? "Unassigned"}</DetailField>
              <DetailField label="Location">{concern.location.path ?? "Not recorded"}</DetailField>
              {concern.stakeholder ? (
                <DetailField label="In the register" wide>
                  <Link href={`/stakeholders/${concern.stakeholder.id}`} className="text-brand-700 hover:underline">
                    {concern.stakeholder.reference} — {concern.stakeholder.name}
                  </Link>
                </DetailField>
              ) : null}
            </DetailGrid>
          </CardBody>
        </Card>
      </div>

      <Drawer
        open={escalateOpen}
        onClose={() => setEscalateOpen(false)}
        title="Escalate to a formal grievance"
        description="The concern's description, stakeholder, location and category all carry across. Check them, adjust anything that has changed, and confirm."
        width="md"
        footer={
          <>
            <Button variant="secondary" onClick={() => setEscalateOpen(false)}>Cancel</Button>
            <Button variant="accent" loading={busy} onClick={() => void escalate()}>
              Create the case
            </Button>
          </>
        }
      >
        <div className="space-y-5">
          <div className="rounded-lg bg-surface-sunken p-4">
            <p className="sasa-eyebrow mb-1.5">Carrying across</p>
            <p className="text-sm leading-relaxed text-ink-700">{concern.description}</p>
          </div>

          <Field label="Confidentiality" htmlFor="esc-confidentiality" hint="Choose confidential if the complainant could face consequences.">
            <Select
              id="esc-confidentiality"
              value={form.confidentiality ?? "normal"}
              onChange={(event) => setForm((current) => ({ ...current, confidentiality: event.target.value }))}
            >
              <option value="normal">Standard</option>
              <option value="confidential">Confidential</option>
              <option value="anonymous">Anonymous</option>
            </Select>
          </Field>

          <Field label="Category" optional htmlFor="esc-category">
            <Select
              id="esc-category"
              value={form.category_id ?? (concern.category ? String(concern.category.id) : "")}
              onChange={(event) => setForm((current) => ({ ...current, category_id: event.target.value }))}
            >
              <option value="">Not classified</option>
              {(categories?.data ?? []).map((category) => (
                <option key={category.id} value={category.id}>
                  {category.name}
                </option>
              ))}
            </Select>
          </Field>

          <Field label="Severity" optional htmlFor="esc-severity">
            <Select
              id="esc-severity"
              value={form.severity ?? ""}
              onChange={(event) => setForm((current) => ({ ...current, severity: event.target.value }))}
            >
              <option value="">Let a grievance officer assess it</option>
              {[1, 2, 3, 4, 5].map((level) => (
                <option key={level} value={level}>
                  Level {level}
                </option>
              ))}
            </Select>
          </Field>

          <p className="text-sm text-ink-600">
            Opening the case starts the acknowledgement and resolution clocks against this project&apos;s standards.
          </p>
        </div>
      </Drawer>
    </div>
  );
}
