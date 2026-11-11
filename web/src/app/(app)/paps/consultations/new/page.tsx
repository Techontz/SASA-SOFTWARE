"use client";

import { useQueryClient } from "@tanstack/react-query";
import { Save } from "lucide-react";
import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";
import { OfflineNotice } from "@/components/pap/PapSectionPanel";
import { Button } from "@/components/ui/Button";
import { PageHeader } from "@/components/ui/DetailLayout";
import { Field, FieldRow, FormActions, FormSection, Input, Select, Textarea } from "@/components/ui/Form";
import { PermissionDenied } from "@/components/ui/States";
import { ApiRequestError, apiRequest } from "@/lib/api/client";
import { useLocations } from "@/lib/api/hooks";
import { usePapOptions } from "@/lib/api/pap";
import { toPayloadValue } from "@/lib/pap/format";
import { useSession } from "@/providers/SessionProvider";
import { useSync } from "@/providers/SyncProvider";
import { useToast } from "@/providers/ToastProvider";
import type { PapConsultation } from "@/types/pap";

const FIELDS = [
  "consultation_date", "location_id", "village", "meeting_type", "topic_discussed", "facilitator",
  "number_male", "number_female", "number_vulnerable", "attendance_sheet_reference",
  "key_concerns_raised", "actions_agreed",
] as const;

type Key = (typeof FIELDS)[number];
const NUMERIC: Key[] = ["location_id", "number_male", "number_female", "number_vulnerable"];

export default function NewPapConsultationPage() {
  const router = useRouter();
  const toast = useToast();
  const sync = useSync();
  const queryClient = useQueryClient();
  const { can } = useSession();
  const { data: optionData } = usePapOptions();
  const { data: villages } = useLocations({ level: "village" });

  const [values, setValues] = useState<Record<Key, string>>(() => ({
    consultation_date: new Date().toISOString().slice(0, 10),
    location_id: "", village: "", meeting_type: "", topic_discussed: "", facilitator: "",
    number_male: "", number_female: "", number_vulnerable: "", attendance_sheet_reference: "",
    key_concerns_raised: "", actions_agreed: "",
  }));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  if (!can("pap.manage")) return <PermissionDenied what="recording consultations" />;

  const set = (key: Key) => (value: string) => setValues((current) => ({ ...current, [key]: value }));

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    if (!sync.online) return;
    if (!values.topic_discussed.trim()) {
      setErrors({ topic_discussed: "Record what the meeting discussed." });
      return;
    }

    const body = Object.fromEntries(FIELDS.map((key) => [key, toPayloadValue(values[key], NUMERIC.includes(key) ? "number" : "text")]));

    setBusy(true);
    setErrors({});
    try {
      const result = await apiRequest<{ data: PapConsultation }>("/pap-consultations", { method: "POST", body });
      await queryClient.invalidateQueries({ queryKey: ["pap-consultations"] });
      toast.success(`${result.data.reference} recorded`, "Saved to the server.");
      router.push("/paps/consultations");
    } catch (caught) {
      if (caught instanceof ApiRequestError && caught.validationErrors) {
        setErrors(Object.fromEntries(Object.entries(caught.validationErrors).map(([key, messages]) => [key, messages[0] ?? ""])));
      }
      toast.error("Nothing was saved", caught instanceof Error ? caught.message : undefined);
    } finally {
      setBusy(false);
    }
  };

  const count = (key: Key, label: string) => (
    <Field label={label} htmlFor={key} error={errors[key]}>
      <Input id={key} inputMode="numeric" value={values[key]} onChange={(event) => set(key)(event.target.value)} />
    </Field>
  );

  return (
    <div className="mx-auto max-w-3xl">
      <PageHeader
        backHref="/paps/consultations"
        eyebrow="PAP master register · Part L"
        title="Record a consultation"
        description="A resettlement meeting with a village or group — not with one PAP."
      />

      <form onSubmit={(event) => void submit(event)} className="space-y-5 pb-6" noValidate>
        {!sync.online ? <OfflineNotice /> : null}

        <FormSection title="The meeting">
          <FieldRow columns={3}>
            <Field label="Date" htmlFor="consultation_date" required error={errors.consultation_date}>
              <Input id="consultation_date" type="date" value={values.consultation_date} onChange={(event) => set("consultation_date")(event.target.value)} />
            </Field>
            <Field label="Village" htmlFor="location_id" error={errors.location_id}>
              <Select id="location_id" placeholder="Choose a village" value={values.location_id} onChange={(event) => set("location_id")(event.target.value)}>
                {(villages?.data ?? []).map((location) => (
                  <option key={location.id} value={location.id}>
                    {location.name}
                  </option>
                ))}
              </Select>
            </Field>
            <Field label="Meeting type" htmlFor="meeting_type" error={errors.meeting_type}>
              <Select id="meeting_type" placeholder="Not recorded" value={values.meeting_type} onChange={(event) => set("meeting_type")(event.target.value)}>
                {(optionData?.data.lists.meeting_types ?? []).map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </Select>
            </Field>
          </FieldRow>
          <Field label="Topic discussed" htmlFor="topic_discussed" required error={errors.topic_discussed}>
            <Textarea id="topic_discussed" rows={3} value={values.topic_discussed} onChange={(event) => set("topic_discussed")(event.target.value)} />
          </Field>
          <FieldRow>
            <Field label="Facilitator" htmlFor="facilitator" error={errors.facilitator}>
              <Input id="facilitator" value={values.facilitator} onChange={(event) => set("facilitator")(event.target.value)} />
            </Field>
            <Field label="Attendance sheet reference" htmlFor="attendance_sheet_reference" error={errors.attendance_sheet_reference}>
              <Input id="attendance_sheet_reference" value={values.attendance_sheet_reference} onChange={(event) => set("attendance_sheet_reference")(event.target.value)} />
            </Field>
          </FieldRow>
        </FormSection>

        <FormSection title="Who attended" description="Counts from the signed attendance sheet.">
          <FieldRow columns={3}>
            {count("number_male", "Number male")}
            {count("number_female", "Number female")}
            {count("number_vulnerable", "Number vulnerable")}
          </FieldRow>
        </FormSection>

        <FormSection title="What was said and agreed">
          <Field label="Key concerns raised" htmlFor="key_concerns_raised" error={errors.key_concerns_raised}>
            <Textarea id="key_concerns_raised" rows={4} value={values.key_concerns_raised} onChange={(event) => set("key_concerns_raised")(event.target.value)} />
          </Field>
          <Field label="Actions agreed" htmlFor="actions_agreed" error={errors.actions_agreed}>
            <Textarea id="actions_agreed" rows={4} value={values.actions_agreed} onChange={(event) => set("actions_agreed")(event.target.value)} />
          </Field>
        </FormSection>

        <FormActions note={!sync.online ? "Offline — nothing can be saved until you reconnect." : undefined}>
          <Button variant="secondary" type="button" onClick={() => router.back()}>
            Cancel
          </Button>
          <Button variant="primary" type="submit" loading={busy} disabled={!sync.online} icon={<Save className="h-4 w-4" />}>
            Add to the register
          </Button>
        </FormActions>
      </form>
    </div>
  );
}
