import { useQueryClient } from "@tanstack/react-query";
import { useEffect, useState } from "react";
import * as Haptics from "expo-haptics";
import { Pressable, View } from "react-native";
import { CreateSheet } from "@/components/CreateSheet";
import { DateField, PickerField, TextAreaField, TextField, ToggleField, type Option } from "@/components/Form";
import { Icon, type IconName } from "@/components/Icon";
import { StakeholderPicker } from "@/components/StakeholderPicker";
import { IconTile, Section, T } from "@/components/ui";
import { api, newUuid } from "@/lib/api";
import { humanise } from "@/lib/format";
import { useQa } from "@/lib/qa";
import { fieldErrorsFrom, isBlank, REQUIRED_MESSAGE, toText, useFormState, useRequiredFields } from "@/lib/forms";
import { COMPLAINANT_TYPES, CONTACT_METHODS, GENDERS, useLanguageOptions, useLocationOptions } from "@/lib/options";
import { useBootstrap } from "@/lib/queries";
import { space, useTheme, type Tone } from "@/lib/theme";
import type { Grievance } from "@/lib/types";

type Confidentiality = "normal" | "confidential" | "anonymous";

const CONFIDENTIALITY: { value: Confidentiality; title: string; description: string; icon: IconName; tone: Tone }[] = [
  {
    value: "normal",
    title: "Standard",
    description: "Anyone on the project who can see cases can see this one, including who raised it.",
    icon: "standard",
    tone: "accent",
  },
  {
    value: "confidential",
    title: "Confidential",
    description: "The case is visible, but the complainant's name, contact and exact location go only to the handling group.",
    icon: "lock",
    tone: "warning",
  },
  {
    value: "anonymous",
    title: "Anonymous",
    description: "No identity is recorded at all, not even for administrators. Nobody can contact them back.",
    icon: "anonymous",
    tone: "danger",
  },
];

/** Never asked on an anonymous case: there is nobody to identify. */
const IDENTITY = new Set(["complainant_name", "complainant_phone", "complainant_email", "preferred_contact_method", "stakeholder_id"]);

const REGISTER: Option[] = [
  { value: "yes", label: "Yes, link their record" },
  { value: "no", label: "No, not in the register" },
];

const initial = {
  confidentiality: "normal" as Confidentiality,
  channel: "in_person",
  received_at: new Date(),
  complainant_name: "",
  complainant_phone: "",
  complainant_email: "",
  no_email: false,
  complainant_type: "community_member",
  complainant_language: "",
  preferred_contact_method: "",
  in_register: "",
  stakeholder: [] as Option[],
  title: "",
  description: "",
  desired_resolution: "",
  location_id: "",
  location_text: "",
  gender: "",
};

type Values = typeof initial;

/** API field name to the form's own, where they differ. */
const FORM_FIELD: Record<string, keyof Values> = { "demographics.gender": "gender", stakeholder_id: "in_register" };

function ConfidentialityRow({ option, selected, onChange }: { option: (typeof CONFIDENTIALITY)[number]; selected: boolean; onChange: (value: Confidentiality) => void }) {
  const theme = useTheme();
  return (
          <Pressable
            key={option.value}
            accessibilityRole="radio"
            accessibilityState={{ selected }}
            accessibilityLabel={`${option.title}. ${option.description}`}
            onPress={() => {
              void Haptics.selectionAsync();
              onChange(option.value);
            }}
            style={({ pressed }) => ({
              flexDirection: "row",
              gap: space.m,
              padding: space.l,
              backgroundColor: pressed ? theme.pressed : "transparent",
            })}
          >
            <IconTile name={option.icon} tone={option.tone} />
            <View style={{ flex: 1 }}>
              <T weight="600">{option.title}</T>
              <T variant="subhead" tone="secondary" style={{ marginTop: 2 }}>
                {option.description}
              </T>
            </View>
            <View style={{ width: 22, alignItems: "center", paddingTop: 4 }}>
              {selected ? <Icon name="check" size={18} color={theme.accent} weight="bold" /> : null}
            </View>
          </Pressable>
  );
}

export default function NewGrievance() {
  const queryClient = useQueryClient();
  const bootstrap = useBootstrap();
  const required = useRequiredFields("grievance");
  const { values, set, errors, setErrors, dirty } = useFormState<Values>({ ...initial, received_at: new Date() });
  const locations = useLocationOptions();
  const languages = useLanguageOptions();
  const channels: Option[] = (bootstrap.data?.channels ?? ["in_person"]).map((value) => ({ value, label: humanise(value) }));
  const anonymous = values.confidentiality === "anonymous";
  const need = (field: string) => required.has(field) && !(anonymous && IDENTITY.has(field));

  // Development only: a scripted simulator run fills the form (see lib/qa.ts).
  const qa = useQa();
  const [qaReady, setQaReady] = useState(false);
  const qaLocation = locations.find((option) => option.subtitle?.includes(" / ")) ?? locations[0];
  useEffect(() => {
    if (!__DEV__ || !qa.mode || qaReady || !qaLocation) return;
    void (async () => {
      if (qa.mode !== "empty") {
        set("complainant_name", "Rehema Juma");
        set("complainant_phone", "+255 754 210 334");
        set("no_email", true);
        set("preferred_contact_method", "phone");
        set("in_register", "no");
        set("complainant_language", "sw");
        set("gender", "female");
        set("title", "Dust from haulage trucks is covering the houses");
        set("description", "Trucks pass every few minutes from early morning. The dust settles on the houses, the food and the washing, and the children have been coughing.");
        set("desired_resolution", "Water the road twice a day, or move the haulage route away from the houses.");
        set("location_id", qaLocation.value);
        set("location_text", "Near tower 142, by the school");
      }
      setQaReady(true);
    })();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [qa.mode, qaLocation]);

  const submit = async () => {
    const missing: Record<string, string> = {};
    for (const field of required) {
      if (anonymous && IDENTITY.has(field)) continue;
      if (field === "complainant_email" && values.no_email) continue;
      const key = FORM_FIELD[field] ?? (field as keyof Values);
      if (key in values && isBlank(values[key])) {
        missing[key] =
          field === "complainant_email" ? "Add their email, or say they have none." : field === "stakeholder_id" ? "Say whether they are already in the register." : REQUIRED_MESSAGE;
      }
    }
    if (!anonymous && values.in_register === "yes" && values.stakeholder.length === 0) missing.stakeholder = "Choose their record.";
    if (values.description.trim().length < 20) missing.description = "Write down what they told you: at least a sentence or two.";
    if (Object.keys(missing).length > 0) {
      setErrors(missing);
      return { missing: Object.keys(missing).length };
    }

    try {
      const response = await api<{ data: Grievance }>("/grievances", {
        method: "POST",
        body: {
          client_uuid: newUuid(),
          channel: values.channel,
          confidentiality: values.confidentiality,
          received_at: values.received_at.toISOString(),
          complainant_type: toText(values.complainant_type),
          complainant_language: toText(values.complainant_language),
          location_id: values.location_id ? Number(values.location_id) : null,
          location_text: toText(values.location_text),
          title: toText(values.title),
          description: values.description.trim(),
          desired_resolution: toText(values.desired_resolution),
          demographics: values.gender ? { gender: values.gender } : {},
          captured_at: new Date().toISOString(),
          // The anonymous promise is kept on the phone too: identity is not
          // even put in the request.
          ...(anonymous
            ? {}
            : {
                complainant_name: toText(values.complainant_name),
                complainant_phone: toText(values.complainant_phone),
                complainant_email: values.no_email ? null : toText(values.complainant_email),
                complainant_has_no_email: values.no_email,
                preferred_contact_method: toText(values.preferred_contact_method),
                stakeholder_id: values.in_register === "yes" && values.stakeholder[0] ? Number(values.stakeholder[0].value) : null,
              }),
        },
      });
      void queryClient.invalidateQueries();
      return {
        saved: {
          title: `Case ${response.data.reference} opened`,
          message: "Saved to SASA. The acknowledgement and resolution clocks have started.",
          href: `/grievances/grievance/${response.data.id}` as const,
          viewLabel: "View case",
        },
      };
    } catch (error) {
      const fields = fieldErrorsFrom(error, { "demographics.gender": "gender", stakeholder_id: "stakeholder" });
      if (Object.keys(fields).length > 0) setErrors(fields);
      throw error;
    }
  };

  return (
    <CreateSheet what="case" dirty={dirty} submit={submit} qaReady={qaReady}>
      <Section title="Who may know about it" footer="Choose this first. It decides what SASA may store and who can ever see it." inset={58}>
        {CONFIDENTIALITY.map((option) => (
        <ConfidentialityRow
          key={option.value}
          option={option}
          selected={values.confidentiality === option.value}
          onChange={(value) => {
            set("confidentiality", value);
            if (value === "anonymous") {
              set("complainant_name", "");
              set("complainant_phone", "");
              set("complainant_email", "");
              set("in_register", "");
              set("stakeholder", []);
            }
          }}
        />
        ))}
      </Section>

      <Section title="How it arrived">
        <PickerField label="Received via" required value={values.channel} options={channels} onChange={(v) => set("channel", v)} />
        <DateField label="Received" required withTime value={values.received_at} onChange={(v) => set("received_at", v)} />
      </Section>

      {!anonymous ? (
        <Section title="Who raised it" footer={values.confidentiality === "confidential" ? "On a confidential case this goes only to the handling group." : undefined}>
          <TextField label="Name" required={need("complainant_name")} value={values.complainant_name} onChange={(v) => set("complainant_name", v)} error={errors.complainant_name} autoCapitalize="words" />
          <TextField label="Phone" required={need("complainant_phone")} value={values.complainant_phone} onChange={(v) => set("complainant_phone", v)} error={errors.complainant_phone} keyboardType="phone-pad" />
          <TextField
            label="Email"
            required={need("complainant_email") && !values.no_email}
            value={values.complainant_email}
            onChange={(v) => set("complainant_email", v)}
            error={errors.complainant_email}
            keyboardType="email-address"
            autoCapitalize="none"
            editable={!values.no_email}
            placeholder={values.no_email ? "None" : undefined}
          />
          <ToggleField
            label="They have no email"
            value={values.no_email}
            onChange={(v) => {
              set("no_email", v);
              if (v) set("complainant_email", "");
            }}
          />
          <PickerField label="Contact by" required={need("preferred_contact_method")} value={values.preferred_contact_method} options={CONTACT_METHODS.filter((o) => o.value !== "email" || !values.no_email)} onChange={(v) => set("preferred_contact_method", v)} error={errors.preferred_contact_method} sheetTitle="Best way to contact them" />
          <PickerField label="In the register" required={need("stakeholder_id")} value={values.in_register} options={REGISTER} onChange={(v) => set("in_register", v)} error={errors.in_register} sheetTitle="Already in the register?" />
          {values.in_register === "yes" ? (
            <StakeholderPicker label="Their record" required value={values.stakeholder} onChange={(v) => set("stakeholder", v)} error={errors.stakeholder} />
          ) : null}
        </Section>
      ) : null}

      <Section title="About them">
        <PickerField label="They are a" required={need("complainant_type")} value={values.complainant_type} options={COMPLAINANT_TYPES} onChange={(v) => set("complainant_type", v)} error={errors.complainant_type} />
        <PickerField label="Language used" required={need("complainant_language")} value={values.complainant_language} options={languages} onChange={(v) => set("complainant_language", v)} error={errors.complainant_language} />
        <PickerField label="Gender" required={need("demographics.gender")} value={values.gender} options={GENDERS} onChange={(v) => set("gender", v)} error={errors.gender} />
      </Section>

      <Section title="What happened" footer="In their own words wherever you can. A grievance officer confirms the category and severity afterwards.">
        <TextField label="Summary" required={need("title")} value={values.title} onChange={(v) => set("title", v)} error={errors.title} placeholder={need("title") ? "Required" : "One line"} />
        <TextAreaField label="Their account" required value={values.description} onChange={(v) => set("description", v)} error={errors.description} placeholder="Trucks pass every few minutes from early morning, and the dust settles on the houses." />
        <TextAreaField label="What they want done" required={need("desired_resolution")} value={values.desired_resolution} onChange={(v) => set("desired_resolution", v)} error={errors.desired_resolution} placeholder="Water the road, or move the haulage route." />
      </Section>

      <Section title="Where">
        <PickerField label="Place" required={need("location_id")} value={values.location_id} options={locations} onChange={(v) => set("location_id", v)} error={errors.location_id} sheetTitle="Where it happened" searchable />
        <TextField label="Describe it" required={need("location_text")} value={values.location_text} onChange={(v) => set("location_text", v)} error={errors.location_text} placeholder={need("location_text") ? "Near tower 142" : undefined} />
      </Section>
    </CreateSheet>
  );
}
