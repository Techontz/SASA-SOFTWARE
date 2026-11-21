import { useQueryClient } from "@tanstack/react-query";
import { useEffect, useState } from "react";
import { CreateSheet } from "@/components/CreateSheet";
import { DateField, NumberField, PickerField, TextAreaField, TextField, type Option } from "@/components/Form";
import { StakeholderPicker } from "@/components/StakeholderPicker";
import { Section } from "@/components/ui";
import { api, newUuid } from "@/lib/api";
import { formatDate } from "@/lib/format";
import { useQa } from "@/lib/qa";
import { fieldErrorsFrom, isBlank, REQUIRED_MESSAGE, toNumber, toText, useFormState, useRequiredFields } from "@/lib/forms";
import { useListOptions, useLocationOptions, useMemberOptions } from "@/lib/options";
import { usePlannedEngagements } from "@/lib/queries";
import { useSession } from "@/lib/session";
import type { Engagement } from "@/lib/types";

const NOT_PLANNED = "none";

const initial = {
  plan: "",
  topic: "",
  held_at: new Date(),
  method: "",
  project_phase: "",
  facilitator_id: "",
  location_id: "",
  venue: "",
  organised_by: "",
  stakeholders: [] as Option[],
  attendance_total: "",
  attendance_female: "",
  attendance_male: "",
  attendance_youth: "",
  attendance_elderly: "",
  attendance_disability: "",
  aim: "",
  discussion_points: "",
  outcomes: "",
};

type Values = typeof initial;

const FORM_FIELD: Record<string, keyof Values> = { engagement_plan_id: "plan", stakeholder_ids: "stakeholders" };

export default function NewEngagement() {
  const queryClient = useQueryClient();
  const { user } = useSession();
  const required = useRequiredFields("engagement");
  const { values, set, errors, setErrors, dirty } = useFormState<Values>({
    ...initial,
    held_at: new Date(),
    facilitator_id: user ? String(user.id) : "",
  });
  const plans = usePlannedEngagements();
  const methods = useListOptions("engagement_methods");
  const phases = useListOptions("project_phases");
  const members = useMemberOptions();
  const locations = useLocationOptions();
  const need = (field: string) => required.has(field);

  const planOptions: Option[] = [
    { value: NOT_PLANNED, label: "Not planned", subtitle: "This was unscheduled" },
    ...(plans.data ?? []).map((plan) => ({
      value: String(plan.id),
      label: plan.title,
      subtitle: [plan.reference, plan.target_date ? `for ${formatDate(plan.target_date)}` : null].filter(Boolean).join(" · "),
    })),
  ];

  const choosePlan = (value: string) => {
    set("plan", value);
    const plan = plans.data?.find((candidate) => String(candidate.id) === value);
    if (!plan) return;
    // A planned engagement already knows most of its own details.
    if (!values.topic) set("topic", plan.title);
    if (!values.method && plan.method) set("method", plan.method);
    if (!values.project_phase && plan.project_phase) set("project_phase", plan.project_phase);
    if (!values.location_id && plan.location?.id) set("location_id", String(plan.location.id));
  };

  // Development only: a scripted simulator run fills the form (see lib/qa.ts).
  const qa = useQa();
  const [qaReady, setQaReady] = useState(false);
  const qaLocation = locations.find((option) => option.subtitle?.includes(" / ")) ?? locations[0];
  useEffect(() => {
    if (!__DEV__ || !qa.mode || qaReady || !qaLocation) return;
    void (async () => {
      if (qa.mode !== "empty") {
        const first = await api<{ data: { id: number; name: string; reference: string }[] }>("/stakeholders", { query: { per_page: 1 } });
        const stakeholder = first.data[0];
        set("plan", "none");
        set("topic", "Village meeting on dust and road safety");
        set("method", "community_meeting");
        set("project_phase", "construction");
        set("location_id", qaLocation.value);
        set("venue", "Village office");
        set("organised_by", "Community relations team");
        if (stakeholder) set("stakeholders", [{ value: String(stakeholder.id), label: stakeholder.name }]);
        set("attendance_total", "42");
        set("attendance_female", "23");
        set("attendance_male", "19");
        set("attendance_youth", "11");
        set("attendance_elderly", "6");
        set("attendance_disability", "2");
        set("aim", "Hear the village's concerns about dust from the haulage road.");
        set("discussion_points", "Dust on houses and food; trucks speeding near the school.");
        set("outcomes", "The contractor will water the road twice daily and post a speed limit by the school.");
      }
      setQaReady(true);
    })();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [qa.mode, qaLocation]);

  const submit = async () => {
    const missing: Record<string, string> = {};
    if (isBlank(values.topic)) missing.topic = "What was this engagement about?";
    for (const field of required) {
      const key = FORM_FIELD[field] ?? (field as keyof Values);
      if (key in values && isBlank(values[key])) {
        missing[key] =
          field === "stakeholder_ids" ? "Choose at least one stakeholder who was there." : field === "engagement_plan_id" ? "Say whether this was planned." : REQUIRED_MESSAGE;
      }
    }
    const total = toNumber(values.attendance_total);
    const women = toNumber(values.attendance_female) ?? 0;
    const men = toNumber(values.attendance_male) ?? 0;
    if (total !== null && women + men > total) missing.attendance_total = "Women and men together cannot be more than the total.";
    if (Object.keys(missing).length > 0) {
      setErrors(missing);
      return { missing: Object.keys(missing).length };
    }

    try {
      const response = await api<{ data: Engagement }>("/engagements", {
        method: "POST",
        body: {
          client_uuid: newUuid(),
          engagement_plan_id: values.plan && values.plan !== NOT_PLANNED ? Number(values.plan) : null,
          topic: values.topic.trim(),
          held_at: values.held_at.toISOString(),
          method: toText(values.method),
          project_phase: toText(values.project_phase),
          facilitator_id: toNumber(values.facilitator_id),
          location_id: toNumber(values.location_id),
          venue: toText(values.venue),
          organised_by: toText(values.organised_by),
          stakeholder_ids: values.stakeholders.map((option) => Number(option.value)),
          attendance_total: total,
          attendance_female: toNumber(values.attendance_female),
          attendance_male: toNumber(values.attendance_male),
          attendance_youth: toNumber(values.attendance_youth),
          attendance_elderly: toNumber(values.attendance_elderly),
          attendance_disability: toNumber(values.attendance_disability),
          aim: toText(values.aim),
          discussion_points: toText(values.discussion_points),
          outcomes: toText(values.outcomes),
          status: "logged",
          captured_at: new Date().toISOString(),
        },
      });
      void queryClient.invalidateQueries();
      return {
        saved: {
          title: "Engagement logged",
          message: `Saved to SASA as ${response.data.reference}.`,
          href: `/engagements/engagement/${response.data.id}` as const,
          viewLabel: "View engagement",
        },
      };
    } catch (error) {
      const fields = fieldErrorsFrom(error, { engagement_plan_id: "plan", stakeholder_ids: "stakeholders" });
      if (Object.keys(fields).length > 0) setErrors(fields);
      throw error;
    }
  };

  return (
    <CreateSheet what="engagement" dirty={dirty} submit={submit} qaReady={qaReady}>
      <Section title="The meeting">
        <PickerField label="Planned" required={need("engagement_plan_id")} value={values.plan} options={planOptions} onChange={choosePlan} error={errors.plan} sheetTitle="Was this planned?" />
        <TextField label="Topic" required value={values.topic} onChange={(v) => set("topic", v)} error={errors.topic} />
        <DateField label="When" required withTime value={values.held_at} onChange={(v) => set("held_at", v)} />
        <PickerField label="How" required={need("method")} value={values.method} options={methods} onChange={(v) => set("method", v)} error={errors.method} sheetTitle="How it was done" />
        <PickerField label="Phase" required={need("project_phase")} value={values.project_phase} options={phases} onChange={(v) => set("project_phase", v)} error={errors.project_phase} sheetTitle="Project phase" />
        <PickerField label="Facilitator" required={need("facilitator_id")} value={values.facilitator_id} options={members} onChange={(v) => set("facilitator_id", v)} error={errors.facilitator_id} sheetTitle="Facilitated by" searchable />
      </Section>

      <Section title="Where">
        <PickerField label="Place" required={need("location_id")} value={values.location_id} options={locations} onChange={(v) => set("location_id", v)} error={errors.location_id} sheetTitle="Location" searchable />
        <TextField label="Venue" required={need("venue")} value={values.venue} onChange={(v) => set("venue", v)} error={errors.venue} placeholder={need("venue") ? "Village office" : undefined} />
        <TextField label="Organised by" required={need("organised_by")} value={values.organised_by} onChange={(v) => set("organised_by", v)} error={errors.organised_by} />
      </Section>

      <Section title="Who came" footer="Count everyone, then the groups the project reports on. One person can be in several groups.">
        <StakeholderPicker label="Stakeholders" multiple required={need("stakeholder_ids")} value={values.stakeholders} onChange={(v) => set("stakeholders", v)} error={errors.stakeholders} />
        <NumberField label="Total attending" required={need("attendance_total")} value={values.attendance_total} onChange={(v) => set("attendance_total", v)} error={errors.attendance_total} />
        <NumberField label="Women" required={need("attendance_female")} value={values.attendance_female} onChange={(v) => set("attendance_female", v)} error={errors.attendance_female} />
        <NumberField label="Men" required={need("attendance_male")} value={values.attendance_male} onChange={(v) => set("attendance_male", v)} error={errors.attendance_male} />
        <NumberField label="Young people" required={need("attendance_youth")} value={values.attendance_youth} onChange={(v) => set("attendance_youth", v)} error={errors.attendance_youth} />
        <NumberField label="Elderly" required={need("attendance_elderly")} value={values.attendance_elderly} onChange={(v) => set("attendance_elderly", v)} error={errors.attendance_elderly} />
        <NumberField label="With a disability" required={need("attendance_disability")} value={values.attendance_disability} onChange={(v) => set("attendance_disability", v)} error={errors.attendance_disability} />
      </Section>

      <Section title="What was said">
        <TextAreaField label="Aim" required={need("aim")} value={values.aim} onChange={(v) => set("aim", v)} error={errors.aim} placeholder="Why the meeting was held" />
        <TextAreaField label="Discussion points" required={need("discussion_points")} value={values.discussion_points} onChange={(v) => set("discussion_points", v)} error={errors.discussion_points} placeholder="What was raised, and by whom" />
        <TextAreaField label="Outcomes" required={need("outcomes")} value={values.outcomes} onChange={(v) => set("outcomes", v)} error={errors.outcomes} placeholder="What was agreed, and what happens next" />
      </Section>
    </CreateSheet>
  );
}
