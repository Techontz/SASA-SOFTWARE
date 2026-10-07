"use client";

import { useQueryClient } from "@tanstack/react-query";
import { CalendarClock, CheckCircle2, Plus } from "lucide-react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useState } from "react";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { DetailField, DetailGrid, PageHeader } from "@/components/ui/DetailLayout";
import { Drawer } from "@/components/ui/Drawer";
import { Field, Input, Select, Textarea } from "@/components/ui/Form";
import { PriorityBadge, StatusBadge } from "@/components/ui/StatusBadge";
import { ErrorState, LoadingState } from "@/components/ui/States";
import { apiRequest } from "@/lib/api/client";
import { useEngagementPlan } from "@/lib/api/hooks";
import { daysBetween, formatDate, humanise } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";
import { useToast } from "@/providers/ToastProvider";

export default function EngagementPlanDetailPage() {
  const params = useParams<{ id: string }>();
  const id = Number(params.id);
  const { can } = useSession();
  const toast = useToast();
  const queryClient = useQueryClient();
  const { data, isLoading, isError, error, refetch } = useEngagementPlan(id);

  const [statusOpen, setStatusOpen] = useState(false);
  const [status, setStatus] = useState("rescheduled");
  const [reason, setReason] = useState("");
  const [targetDate, setTargetDate] = useState("");
  const [busy, setBusy] = useState(false);

  if (isLoading) return <LoadingState label="Opening the plan" />;
  if (isError || !data) return <ErrorState error={error} onRetry={() => void refetch()} />;

  const plan = data.data;
  const days = daysBetween(plan.target_date);

  const changeStatus = async () => {
    setBusy(true);
    try {
      await apiRequest(`/engagement-plans/${id}/status`, {
        method: "POST",
        body: { status, reason, target_date: targetDate || null },
      });
      await queryClient.invalidateQueries({ queryKey: ["engagement-plan", id] });
      toast.success("Plan updated");
      setStatusOpen(false);
      void refetch();
    } catch (error) {
      toast.error("We could not update the plan", error instanceof Error ? error.message : undefined);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div>
      <PageHeader
        backHref="/engagements/plans"
        eyebrow={`Module 2 · Planned · ${plan.reference}`}
        title={plan.title}
        description={plan.purpose ?? undefined}
        meta={
          <>
            <StatusBadge status={plan.status} label={plan.status_label} />
            <PriorityBadge priority={plan.priority} />
            <span className="text-sm text-ink-600">
              {formatDate(plan.target_date)}
              {plan.status === "planned" && days !== null
                ? days < 0
                  ? ` · ${Math.abs(days)} days overdue`
                  : days === 0
                    ? " · today"
                    : ` · in ${days} days`
                : ""}
            </span>
          </>
        }
        actions={
          <>
            {can("engagement.log") && plan.status === "planned" ? (
              <Link href={`/engagements/log?plan=${plan.id}`}>
                <Button variant="accent" icon={<CheckCircle2 className="h-4 w-4" />}>
                  Log what happened
                </Button>
              </Link>
            ) : null}
            {can("engagement.plan") ? (
              <Button variant="secondary" onClick={() => setStatusOpen(true)}>
                Reschedule or cancel
              </Button>
            ) : null}
          </>
        }
      />

      <div className="grid gap-4 lg:grid-cols-[1.4fr_1fr]">
        <div className="space-y-4">
          <Card>
            <CardHeader title="The plan" />
            <CardBody>
              <DetailGrid>
                <DetailField label="Target date">{formatDate(plan.target_date)}</DetailField>
                <DetailField label="Window">
                  {plan.window_start ? `${formatDate(plan.window_start)} – ${formatDate(plan.window_end)}` : "One day"}
                </DetailField>
                <DetailField label="Method">{humanise(plan.method)}</DetailField>
                <DetailField label="Project phase">{humanise(plan.project_phase)}</DetailField>
                <DetailField label="Stakeholder">
                  {plan.stakeholder ? (
                    <Link href={`/stakeholders/${plan.stakeholder.id}`} className="text-brand-700 hover:underline">
                      {plan.stakeholder.name}
                    </Link>
                  ) : (
                    plan.stakeholder_group ?? "—"
                  )}
                </DetailField>
                <DetailField label="Location">{plan.location.path ?? plan.location.text ?? "—"}</DetailField>
                <DetailField label="Owner">{plan.owner?.name ?? "Unassigned"}</DetailField>
                <DetailField label="Responsible team">{plan.responsible_team ?? "—"}</DetailField>
                <DetailField label="Repeats">{humanise(plan.recurrence)}</DetailField>
                {plan.status_reason ? (
                  <DetailField label="Status reason" wide>
                    {plan.status_reason}
                  </DetailField>
                ) : null}
              </DetailGrid>
            </CardBody>
          </Card>

          <Card>
            <CardHeader
              title="What actually happened"
              description="Engagements logged against this plan."
            />
            <CardBody>
              {(plan.engagements ?? []).length === 0 ? (
                <div className="rounded-lg bg-surface-sunken px-4 py-8 text-center">
                  <CalendarClock className="mx-auto h-6 w-6 text-ink-400" aria-hidden />
                  <p className="mt-3 text-sm font-medium text-ink-800">Nothing logged against this plan yet</p>
                  <p className="mt-1 text-sm text-ink-600">
                    {days !== null && days < 0 && plan.status === "planned"
                      ? "The date has passed. If it happened, log it now; if it did not, mark it missed or reschedule it."
                      : "Log it once the engagement has taken place."}
                  </p>
                  {can("engagement.log") ? (
                    <Link href={`/engagements/log?plan=${plan.id}`} className="mt-4 inline-block">
                      <Button variant="accent" icon={<Plus className="h-4 w-4" />}>
                        Log what happened
                      </Button>
                    </Link>
                  ) : null}
                </div>
              ) : (
                <ul className="space-y-3">
                  {(plan.engagements ?? []).map((engagement) => (
                    <li key={engagement.id}>
                      <Link
                        href={`/engagements/${engagement.id}`}
                        className="flex items-center gap-3 rounded-lg border border-hairline p-4 transition hover:border-brand-300 hover:bg-brand-50/40"
                      >
                        <span className="min-w-0 flex-1">
                          <span className="font-mono text-xs text-ink-500">{engagement.reference}</span>
                          <span className="mt-0.5 block font-medium text-ink-900">{engagement.topic}</span>
                          <span className="mt-0.5 block text-sm text-ink-600">
                            {formatDate(engagement.held_at)} · {engagement.attendance.total} attended
                          </span>
                        </span>
                        <StatusBadge status={engagement.planned_vs_actual} label={engagement.planned_vs_actual_label} size="sm" />
                      </Link>
                    </li>
                  ))}
                </ul>
              )}
            </CardBody>
          </Card>
        </div>

        <div className="space-y-4">
          <Card>
            <CardHeader title="Inclusion" description="Whether the engagement will reach the people it should." />
            <CardBody className="space-y-3">
              {[
                ["Arrangements for vulnerable groups", plan.vulnerable_group_accommodation, plan.accommodation_notes],
                ["FPIC applies", plan.fpic_required, plan.fpic_notes],
                ["Grievance mechanism explained", plan.grievance_channel_available, null],
              ].map(([label, enabled, note]) => (
                <div key={String(label)} className="flex items-start gap-3 rounded-lg border border-hairline p-3">
                  <span
                    className={
                      enabled
                        ? "mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-success-100 text-success-700"
                        : "mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-ink-100 text-ink-500"
                    }
                  >
                    {enabled ? <CheckCircle2 className="h-3.5 w-3.5" aria-hidden /> : "—"}
                  </span>
                  <span className="min-w-0">
                    <span className="block text-sm font-medium text-ink-800">{label as string}</span>
                    {note ? <span className="mt-0.5 block text-sm text-ink-600">{note as string}</span> : null}
                  </span>
                </div>
              ))}
            </CardBody>
          </Card>

          {plan.budget_amount || plan.resources_required ? (
            <Card>
              <CardHeader title="Resources" />
              <CardBody>
                <DetailGrid columns={2}>
                  <DetailField label="Budget">
                    {plan.budget_amount ? `${plan.budget_currency ?? ""} ${plan.budget_amount}` : "—"}
                  </DetailField>
                  <DetailField label="Needed">{plan.resources_required ?? "—"}</DetailField>
                </DetailGrid>
              </CardBody>
            </Card>
          ) : null}
        </div>
      </div>

      <Drawer
        open={statusOpen}
        onClose={() => setStatusOpen(false)}
        title="Reschedule or cancel"
        description="The plan and your reason stay on the record, so the reporting shows what really happened."
        width="sm"
        footer={
          <>
            <Button variant="secondary" onClick={() => setStatusOpen(false)}>Cancel</Button>
            <Button variant="primary" loading={busy} onClick={() => void changeStatus()}>
              Save
            </Button>
          </>
        }
      >
        <div className="space-y-5">
          <Field label="New status" required htmlFor="plan-status">
            <Select id="plan-status" value={status} onChange={(event) => setStatus(event.target.value)}>
              <option value="planned">Still planned</option>
              <option value="rescheduled">Rescheduled</option>
              <option value="postponed">Postponed</option>
              <option value="cancelled">Cancelled</option>
              <option value="missed">Missed</option>
            </Select>
          </Field>

          {status === "rescheduled" ? (
            <Field label="New target date" htmlFor="plan-date">
              <Input id="plan-date" type="date" value={targetDate} onChange={(event) => setTargetDate(event.target.value)} />
            </Field>
          ) : null}

          <Field label="Why" optional htmlFor="plan-reason">
            <Textarea id="plan-reason" rows={3} value={reason} onChange={(event) => setReason(event.target.value)} />
          </Field>
        </div>
      </Drawer>
    </div>
  );
}
