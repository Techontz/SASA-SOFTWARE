import { useQueryClient } from "@tanstack/react-query";
import { useEffect, useState } from "react";
import { CreateSheet } from "@/components/CreateSheet";
import { PickerField, SegmentField, TextAreaField, TextField, ToggleField } from "@/components/Form";
import { Section } from "@/components/ui";
import { api, newUuid } from "@/lib/api";
import { useQa } from "@/lib/qa";
import { fieldErrorsFrom, isBlank, REQUIRED_MESSAGE, toText, useFormState, useRequiredFields } from "@/lib/forms";
import { CONTACT_METHODS, LEVELS, useLanguageOptions, useListOptions, useLocationOptions } from "@/lib/options";
import type { Stakeholder } from "@/lib/types";

const initial = {
  name: "",
  type: "",
  organisation_name: "",
  phone: "",
  email: "",
  preferred_language: "",
  preferred_contact_method: "",
  primary_location_id: "",
  physical_address: "",
  influence: "",
  interest: "",
  power: "",
  impact: "",
  is_vulnerable: false,
  concerns_expectations: "",
  notes: "",
};

type Values = typeof initial;

export default function NewStakeholder() {
  const queryClient = useQueryClient();
  const required = useRequiredFields("stakeholder");
  const { values, set, errors, setErrors, dirty } = useFormState<Values>(initial);
  const locations = useLocationOptions();
  const languages = useLanguageOptions();
  const types = useListOptions("stakeholder_types");
  const need = (field: keyof Values) => required.has(field);

  // Development only: a scripted simulator run fills the form (see lib/qa.ts).
  const qa = useQa();
  const [qaReady, setQaReady] = useState(false);
  const qaLocation = locations.find((option) => option.subtitle?.includes(" / ")) ?? locations[0];
  useEffect(() => {
    if (!__DEV__ || !qa.mode || qaReady || !qaLocation) return;
    void (async () => {
      if (qa.mode !== "empty") {
        set("name", "Rehema Juma");
        set("type", "household");
        set("phone", "+255 754 210 335");
        set("preferred_language", "sw");
        set("preferred_contact_method", "phone");
        set("primary_location_id", qaLocation.value);
        set("physical_address", "Plot 14, behind the primary school");
        set("influence", "medium");
        set("interest", "high");
        set("power", "low");
        set("impact", "high");
        set("concerns_expectations", "Worried about dust and road safety for the children walking to school.");
        set("notes", "Met during the corridor walkover.");
      }
      setQaReady(true);
    })();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [qa.mode, qaLocation]);

  const submit = async () => {
    const missing: Record<string, string> = {};
    if (isBlank(values.name)) missing.name = "A stakeholder needs a name.";
    if (isBlank(values.type)) missing.type = "Choose what kind of stakeholder this is.";
    for (const field of required) {
      if (field in values && isBlank(values[field as keyof Values])) missing[field] = REQUIRED_MESSAGE;
    }
    if (Object.keys(missing).length > 0) {
      setErrors(missing);
      return { missing: Object.keys(missing).length };
    }

    try {
      const response = await api<{ data: Stakeholder }>("/stakeholders", {
        method: "POST",
        body: {
          client_uuid: newUuid(),
          name: values.name.trim(),
          type: values.type,
          organisation_name: toText(values.organisation_name),
          phone: toText(values.phone),
          email: toText(values.email),
          preferred_language: toText(values.preferred_language),
          preferred_contact_method: toText(values.preferred_contact_method),
          primary_location_id: values.primary_location_id ? Number(values.primary_location_id) : null,
          physical_address: toText(values.physical_address),
          influence: toText(values.influence),
          interest: toText(values.interest),
          power: toText(values.power),
          impact: toText(values.impact),
          is_vulnerable: values.is_vulnerable,
          concerns_expectations: toText(values.concerns_expectations),
          notes: toText(values.notes),
          captured_at: new Date().toISOString(),
        },
      });
      void queryClient.invalidateQueries();
      return {
        saved: {
          title: `${response.data.name} is in the register`,
          message: `Saved to SASA as ${response.data.reference}.`,
          href: `/stakeholders/stakeholder/${response.data.id}` as const,
          viewLabel: "View stakeholder",
        },
      };
    } catch (error) {
      const fields = fieldErrorsFrom(error);
      if (Object.keys(fields).length > 0) setErrors(fields);
      throw error;
    }
  };

  return (
    <CreateSheet what="stakeholder" dirty={dirty} submit={submit} qaReady={qaReady}>
      <Section title="Who they are">
        <TextField label="Name" required value={values.name} onChange={(v) => set("name", v)} error={errors.name} autoCapitalize="words" />
        <PickerField label="Type" required value={values.type} options={types} onChange={(v) => set("type", v)} error={errors.type} />
        <TextField label="Organisation" value={values.organisation_name} onChange={(v) => set("organisation_name", v)} autoCapitalize="words" />
        <ToggleField label="In a vulnerable situation" value={values.is_vulnerable} onChange={(v) => set("is_vulnerable", v)} />
      </Section>

      <Section title="How to reach them" footer="The phone number is also used to spot duplicate records.">
        <TextField label="Phone" required={need("phone")} value={values.phone} onChange={(v) => set("phone", v)} error={errors.phone} keyboardType="phone-pad" placeholder={need("phone") ? "+255 754 000 000" : undefined} />
        <TextField label="Email" required={need("email")} value={values.email} onChange={(v) => set("email", v)} error={errors.email} keyboardType="email-address" autoCapitalize="none" />
        <PickerField label="Language" required={need("preferred_language")} value={values.preferred_language} options={languages} onChange={(v) => set("preferred_language", v)} error={errors.preferred_language} />
        <PickerField label="Contact by" required={need("preferred_contact_method")} value={values.preferred_contact_method} options={CONTACT_METHODS} onChange={(v) => set("preferred_contact_method", v)} error={errors.preferred_contact_method} sheetTitle="Best way to contact them" />
      </Section>

      <Section title="Where they are">
        <PickerField label="Place" required={need("primary_location_id")} value={values.primary_location_id} options={locations} onChange={(v) => set("primary_location_id", v)} error={errors.primary_location_id} sheetTitle="Village, ward or district" searchable />
        <TextAreaField label="Physical address" required={need("physical_address")} value={values.physical_address} onChange={(v) => set("physical_address", v)} error={errors.physical_address} placeholder="House, street or landmark" />
      </Section>

      <Section title="Assessment" footer="Together these set the stakeholder's priority, and the way the project should engage them.">
        <SegmentField label="Influence" required={need("influence")} value={values.influence} options={LEVELS} onChange={(v) => set("influence", v)} error={errors.influence} />
        <SegmentField label="Interest" required={need("interest")} value={values.interest} options={LEVELS} onChange={(v) => set("interest", v)} error={errors.interest} />
        <SegmentField label="Power" required={need("power")} value={values.power} options={LEVELS} onChange={(v) => set("power", v)} error={errors.power} />
        <SegmentField label="Impact" required={need("impact")} value={values.impact} options={LEVELS} onChange={(v) => set("impact", v)} error={errors.impact} />
      </Section>

      <Section title="Notes">
        <TextAreaField label="Concerns and expectations" required={need("concerns_expectations")} value={values.concerns_expectations} onChange={(v) => set("concerns_expectations", v)} error={errors.concerns_expectations} placeholder="What they worry about, and what they expect from the project" />
        <TextAreaField label="Internal notes" required={need("notes")} value={values.notes} onChange={(v) => set("notes", v)} error={errors.notes} placeholder="Seen by project members, not by the stakeholder" />
      </Section>
    </CreateSheet>
  );
}
