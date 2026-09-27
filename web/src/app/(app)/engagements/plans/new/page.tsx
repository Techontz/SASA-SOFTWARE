"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { Save } from "lucide-react";
import { useRouter } from "next/navigation";
import { Controller, useForm } from "react-hook-form";
import { z } from "zod";
import { Button } from "@/components/ui/Button";
import { Checkbox, Field, FieldRow, FormActions, FormSection, Input, Select, Textarea } from "@/components/ui/Form";
import { PageHeader } from "@/components/ui/DetailLayout";
import { PermissionDenied } from "@/components/ui/States";
import { apiRequest } from "@/lib/api/client";
import { useConfiguration, useLocations, useProjectMembers, useStakeholders } from "@/lib/api/hooks";
import { humanise } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";
import { useToast } from "@/providers/ToastProvider";
import type { EngagementPlan } from "@/types/api";

const schema = z.object({
  title: z.string().min(3, "Give the planned engagement a short title."),
  stakeholder_id: z.string().optional(),
  stakeholder_group: z.string().optional(),
  purpose: z.string().optional(),
  method: z.string().optional(),
  project_phase: z.string().optional(),
  target_date: z.string().min(1, "When is it planned for?"),
  window_start: z.string().optional(),
  window_end: z.string().optional(),
  location_id: z.string().optional(),
  location_text: z.string().optional(),
  owner_id: z.string().optional(),
  responsible_team: z.string().optional(),
  priority: z.string(),
  recurrence: z.string(),
  recurrence_until: z.string().optional(),
  vulnerable_group_accommodation: z.boolean(),
  accommodation_notes: z.string().optional(),
  fpic_required: z.boolean(),
  fpic_notes: z.string().optional(),
  grievance_channel_available: z.boolean(),
  budget_amount: z.string().optional(),
  resources_required: z.string().optional(),
});

type FormValues = z.infer<typeof schema>;

export default function NewEngagementPlanPage() {
  const router = useRouter();
  const toast = useToast();
  const { can } = useSession();
  const { data: stakeholders } = useStakeholders({ per_page: 200, status: "active" });
  const { data: members } = useProjectMembers();
  const { data: locationData } = useLocations();
  const { data: configuration } = useConfiguration();

  const {
    register,
    handleSubmit,
    control,
    watch,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      title: "",
      target_date: "",
      priority: "medium",
      recurrence: "once",
      vulnerable_group_accommodation: false,
      fpic_required: false,
      grievance_channel_available: true,
    },
  });

  const values = watch();

  if (!can("engagement.plan")) return <PermissionDenied what="planning engagements" />;

  const onSubmit = handleSubmit(async (formValues) => {
    try {
      const response = await apiRequest<{ data: EngagementPlan }>("/engagement-plans", {
        method: "POST",
        body: {
          ...formValues,
          stakeholder_id: formValues.stakeholder_id ? Number(formValues.stakeholder_id) : null,
          location_id: formValues.location_id ? Number(formValues.location_id) : null,
          owner_id: formValues.owner_id ? Number(formValues.owner_id) : null,
          budget_amount: formValues.budget_amount ? Number(formValues.budget_amount) : null,
          window_start: formValues.window_start || null,
          window_end: formValues.window_end || null,
          recurrence_until: formValues.recurrence_until || null,
        },
      });

      toast.success(
        `${response.data.reference} planned`,
        formValues.recurrence !== "once" ? "Repeat entries have been created for the whole period." : undefined,
      );
      router.push(`/engagements/plans/${response.data.id}`);
    } catch (error) {
      toast.error("We could not save this plan", error instanceof Error ? error.message : undefined);
    }
  });

  return (
    <div className="mx-auto max-w-4xl">
      <PageHeader
        backHref="/engagements/plans"
        eyebrow="Module 2 · Stakeholder management"
        title="Plan an engagement"
        description="Set what you intend to do and when. What actually happens is logged against it, and the gap between the two is what the dashboards report."
      />

      <form onSubmit={onSubmit} className="space-y-5 pb-6" noValidate>
        <FormSection title="What and when">
          <FieldRow>
            <Field label="Title" required error={errors.title?.message} htmlFor="title">
              <Input id="title" placeholder="Quarterly disclosure meeting — Buswelu" invalid={Boolean(errors.title)} {...register("title")} />
            </Field>
            <Field label="Target date" required error={errors.target_date?.message} htmlFor="target_date">
              <Input id="target_date" type="date" invalid={Boolean(errors.target_date)} {...register("target_date")} />
            </Field>
          </FieldRow>

          <FieldRow>
            <Field label="Window opens" optional htmlFor="window_start" hint="If a range is acceptable rather than one exact day.">
              <Input id="window_start" type="date" {...register("window_start")} />
            </Field>
            <Field label="Window closes" optional htmlFor="window_end">
              <Input id="window_end" type="date" {...register("window_end")} />
            </Field>
          </FieldRow>

          <Field label="Purpose" optional htmlFor="purpose">
            <Textarea id="purpose" rows={3} placeholder="Disclose the works programme for the coming quarter and review outstanding commitments." {...register("purpose")} />
          </Field>

          <FieldRow columns={3}>
            <Field label="Method" optional htmlFor="method">
              <Select id="method" placeholder="Not decided" {...register("method")}>
                {(configuration?.data.lists.engagement_methods ?? []).map((method) => (
                  <option key={method} value={method}>
                    {humanise(method)}
                  </option>
                ))}
              </Select>
            </Field>
            <Field label="Project phase" optional htmlFor="project_phase">
              <Select id="project_phase" placeholder="Not recorded" {...register("project_phase")}>
                {(configuration?.data.lists.project_phases ?? []).map((phase) => (
                  <option key={phase} value={phase}>
                    {humanise(phase)}
                  </option>
                ))}
              </Select>
            </Field>
            <Field label="Priority" htmlFor="priority">
              <Select id="priority" {...register("priority")}>
                <option value="high">High</option>
                <option value="medium">Medium</option>
                <option value="low">Low</option>
              </Select>
            </Field>
          </FieldRow>
        </FormSection>

        <FormSection title="Who and where">
          <FieldRow>
            <Field label="Stakeholder" optional htmlFor="stakeholder_id" hint="From the register.">
              <Select id="stakeholder_id" placeholder="A group rather than one record" {...register("stakeholder_id")}>
                {(stakeholders?.data ?? []).map((stakeholder) => (
                  <option key={stakeholder.id} value={stakeholder.id}>
                    {stakeholder.name} ({stakeholder.type_label})
                  </option>
                ))}
              </Select>
            </Field>
            <Field label="Or a group" optional htmlFor="stakeholder_group">
              <Input id="stakeholder_group" placeholder="All households in the corridor section" {...register("stakeholder_group")} />
            </Field>
          </FieldRow>

          <FieldRow>
            <Field label="Location" optional htmlFor="location_id">
              <Select id="location_id" placeholder="Not decided" {...register("location_id")}>
                {(locationData?.data ?? []).map((location) => (
                  <option key={location.id} value={location.id}>
                    {location.path ?? location.name}
                  </option>
                ))}
              </Select>
            </Field>
            <Field label="Or describe it" optional htmlFor="location_text">
              <Input id="location_text" {...register("location_text")} />
            </Field>
          </FieldRow>

          <FieldRow>
            <Field label="Owner" optional htmlFor="owner_id" hint="Who is accountable for making it happen.">
              <Select id="owner_id" placeholder="Unassigned" {...register("owner_id")}>
                {(members?.data ?? []).map((member) => (
                  <option key={member.user.id} value={member.user.id}>
                    {member.user.name}
                  </option>
                ))}
              </Select>
            </Field>
            <Field label="Responsible team" optional htmlFor="responsible_team">
              <Input id="responsible_team" placeholder="Community relations" {...register("responsible_team")} />
            </Field>
          </FieldRow>
        </FormSection>

        <FormSection
          title="Making it inclusive"
          description="Whether the engagement will actually reach the people it is meant to."
        >
          <Controller
            control={control}
            name="vulnerable_group_accommodation"
            render={({ field }) => (
              <Checkbox
                checked={field.value}
                onChange={field.onChange}
                label="Specific arrangements for people in vulnerable situations"
                description="Interpretation, transport, timing, accessibility, separate sessions."
              />
            )}
          />
          {values.vulnerable_group_accommodation ? (
            <Field label="What has been arranged" optional htmlFor="accommodation_notes">
              <Textarea id="accommodation_notes" rows={2} placeholder="Swahili interpretation; seating for elderly attendees; held at ground level." {...register("accommodation_notes")} />
            </Field>
          ) : null}

          <Controller
            control={control}
            name="fpic_required"
            render={({ field }) => (
              <Checkbox
                checked={field.value}
                onChange={field.onChange}
                label="Free, prior and informed consent applies"
                description="Tick when the engagement forms part of an FPIC process."
              />
            )}
          />
          {values.fpic_required ? (
            <Field label="FPIC notes" optional htmlFor="fpic_notes">
              <Textarea id="fpic_notes" rows={2} {...register("fpic_notes")} />
            </Field>
          ) : null}

          <Controller
            control={control}
            name="grievance_channel_available"
            render={({ field }) => (
              <Checkbox
                checked={field.value}
                onChange={field.onChange}
                label="The grievance mechanism will be explained"
                description="People cannot use a channel they have not been told about."
              />
            )}
          />
        </FormSection>

        <FormSection title="Repeat and resources" description="Optional. A repeating plan creates its own trackable entries.">
          <FieldRow>
            <Field label="Repeats" htmlFor="recurrence">
              <Select id="recurrence" {...register("recurrence")}>
                <option value="once">Once</option>
                <option value="weekly">Weekly</option>
                <option value="monthly">Monthly</option>
                <option value="quarterly">Quarterly</option>
                <option value="semi_annual">Every six months</option>
                <option value="annual">Annually</option>
              </Select>
            </Field>
            {values.recurrence !== "once" ? (
              <Field label="Repeat until" optional htmlFor="recurrence_until">
                <Input id="recurrence_until" type="date" {...register("recurrence_until")} />
              </Field>
            ) : null}
          </FieldRow>

          <FieldRow>
            <Field label="Budget" optional htmlFor="budget_amount">
              <Input id="budget_amount" type="number" inputMode="decimal" min={0} step="0.01" {...register("budget_amount")} />
            </Field>
            <Field label="Resources needed" optional htmlFor="resources_required">
              <Input id="resources_required" placeholder="Vehicle, PA system, interpreter" {...register("resources_required")} />
            </Field>
          </FieldRow>
        </FormSection>

        <FormActions>
          <Button variant="secondary" type="button" onClick={() => router.back()}>
            Cancel
          </Button>
          <Button variant="primary" type="submit" loading={isSubmitting} icon={<Save className="h-4 w-4" />}>
            Add to the plan
          </Button>
        </FormActions>
      </form>
    </div>
  );
}
