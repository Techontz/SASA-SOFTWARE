"use client";

import { useQueryClient } from "@tanstack/react-query";
import { LocateFixed, Save } from "lucide-react";
import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";
import { Button } from "@/components/ui/Button";
import { Field, FieldRow, FormActions, FormSection, Input, Select, Textarea } from "@/components/ui/Form";
import { ApiRequestError, apiRequest } from "@/lib/api/client";
import { useLocations } from "@/lib/api/hooks";
import { usePapOptions } from "@/lib/api/pap";
import { toPayloadValue } from "@/lib/pap/format";
import { useSession } from "@/providers/SessionProvider";
import { useSync } from "@/providers/SyncProvider";
import { useToast } from "@/providers/ToastProvider";
import type { Pap } from "@/types/pap";
import { OfflineNotice } from "./PapSectionPanel";

const TEXT_FIELDS = [
  "full_name", "national_id", "gender", "date_of_birth", "stated_age", "marital_status", "phone",
  "alternative_contact", "location_id", "kitongoji", "physical_address", "latitude", "longitude",
  "household_id", "next_of_kin", "next_of_kin_contact", "vulnerability_category", "remarks",
] as const;

type ProfileField = (typeof TEXT_FIELDS)[number];
type Values = Record<ProfileField, string>;

const NUMERIC: ProfileField[] = ["stated_age", "latitude", "longitude", "location_id"];

function fromPap(pap: Pap | null): Values {
  const text = (value: string | number | null | undefined) => (value === null || value === undefined ? "" : String(value));

  return {
    full_name: text(pap?.full_name),
    national_id: text(pap?.national_id),
    gender: text(pap?.gender),
    date_of_birth: text(pap?.date_of_birth),
    stated_age: text(pap?.stated_age),
    marital_status: text(pap?.marital_status),
    phone: text(pap?.phone),
    alternative_contact: text(pap?.alternative_contact),
    location_id: text(pap?.location.id),
    kitongoji: text(pap?.location.kitongoji),
    physical_address: text(pap?.physical_address),
    latitude: text(pap?.gps.latitude),
    longitude: text(pap?.gps.longitude),
    household_id: text(pap?.household_id),
    next_of_kin: text(pap?.next_of_kin),
    next_of_kin_contact: text(pap?.next_of_kin_contact),
    vulnerability_category: pap?.vulnerability_category ?? "none",
    remarks: text(pap?.remarks),
  };
}

/**
 * Part A — the PAP profile, for both adding and editing. Saving also creates
 * or updates the PAP's stakeholder record on the server, so the form says so.
 */
export function PapProfileForm({ pap }: { pap: Pap | null }) {
  const router = useRouter();
  const toast = useToast();
  const sync = useSync();
  const queryClient = useQueryClient();
  const { project } = useSession();
  const { data: optionData } = usePapOptions();
  const { data: villages } = useLocations({ level: "village" });

  const [values, setValues] = useState<Values>(() => fromPap(pap));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [locating, setLocating] = useState(false);

  const lists = optionData?.data.lists ?? {};
  const nidaWithheld = pap !== null && pap.sensitive_withheld;
  const set = (key: ProfileField) => (value: string) => setValues((current) => ({ ...current, [key]: value }));

  const locate = () => {
    if (!("geolocation" in navigator)) {
      toast.error("This device cannot report its location");
      return;
    }
    setLocating(true);
    navigator.geolocation.getCurrentPosition(
      (position) => {
        setValues((current) => ({
          ...current,
          latitude: position.coords.latitude.toFixed(7),
          longitude: position.coords.longitude.toFixed(7),
        }));
        setLocating(false);
      },
      () => {
        toast.error("We could not read the location", "Check that location access is allowed, or type the coordinates.");
        setLocating(false);
      },
      { enableHighAccuracy: true, timeout: 15_000 },
    );
  };

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    if (!sync.online) return;

    if (!values.full_name.trim()) {
      setErrors({ full_name: "A PAP needs a full name." });
      return;
    }

    const payload: Record<string, unknown> = {};
    TEXT_FIELDS.forEach((key) => {
      if (key === "national_id" && nidaWithheld && values.national_id === "") return;
      payload[key] = toPayloadValue(values[key], NUMERIC.includes(key) ? "number" : "text");
    });
    if (!pap) payload.captured_at = new Date().toISOString();

    setBusy(true);
    setErrors({});
    try {
      const result = await apiRequest<{ data: Pap }>(pap ? `/paps/${pap.id}` : "/paps", {
        method: pap ? "PATCH" : "POST",
        body: payload,
      });
      await queryClient.invalidateQueries({ queryKey: ["paps"] });
      await queryClient.invalidateQueries({ queryKey: ["pap", result.data.id] });
      await queryClient.invalidateQueries({ queryKey: ["pap-summary"] });
      await queryClient.invalidateQueries({ queryKey: ["stakeholders"] });
      toast.success(
        pap ? `${result.data.reference} updated` : `${result.data.reference} added to the register`,
        "Saved to the server. The stakeholder register has been updated too.",
      );
      router.push(`/paps/${result.data.id}`);
    } catch (caught) {
      if (caught instanceof ApiRequestError && caught.validationErrors) {
        setErrors(Object.fromEntries(Object.entries(caught.validationErrors).map(([key, messages]) => [key, messages[0] ?? ""])));
      }
      toast.error("Nothing was saved", caught instanceof Error ? caught.message : undefined);
    } finally {
      setBusy(false);
    }
  };

  const select = (key: ProfileField, list: string, label: string, placeholder = "Not recorded", required = false) => (
    <Field label={label} htmlFor={`pap-${key}`} error={errors[key]} required={required}>
      <Select id={`pap-${key}`} placeholder={required ? undefined : placeholder} value={values[key]} onChange={(event) => set(key)(event.target.value)} invalid={Boolean(errors[key])}>
        {(lists[list] ?? []).map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </Select>
    </Field>
  );

  const text = (key: ProfileField, label: string, extra: { hint?: string; required?: boolean; type?: string; inputMode?: "tel" | "decimal" | "numeric" } = {}) => (
    <Field label={label} htmlFor={`pap-${key}`} error={errors[key]} hint={extra.hint} required={extra.required}>
      <Input
        id={`pap-${key}`}
        type={extra.type ?? "text"}
        inputMode={extra.inputMode}
        autoComplete="off"
        value={values[key]}
        invalid={Boolean(errors[key])}
        onChange={(event) => set(key)(event.target.value)}
      />
    </Field>
  );

  return (
    <form onSubmit={(event) => void submit(event)} className="space-y-5 pb-6" noValidate>
      {!sync.online ? <OfflineNotice /> : null}

      <FormSection title="Who they are" description="Particulars here also appear on the PAP's stakeholder record. The National ID never does.">
        {text("full_name", "Full name", { required: true })}
        <FieldRow>
          <Field
            label="National ID (NIDA)"
            htmlFor="pap-national_id"
            error={errors.national_id}
            hint={nidaWithheld ? "Hidden from you. Leave blank to keep what is recorded." : "Only people with sensitive access will see this."}
          >
            <Input id="pap-national_id" autoComplete="off" value={values.national_id} onChange={(event) => set("national_id")(event.target.value)} />
          </Field>
          {select("gender", "genders", "Gender")}
        </FieldRow>
        <FieldRow columns={3}>
          {text("date_of_birth", "Date of birth", { type: "date", hint: "Age is worked out from this." })}
          {values.date_of_birth ? (
            <Field label="Age" hint="Derived from the date of birth.">
              <Input value={String(ageFrom(values.date_of_birth) ?? "")} readOnly disabled />
            </Field>
          ) : (
            text("stated_age", "Age (if no date of birth)", { inputMode: "numeric" })
          )}
          {select("marital_status", "marital_statuses", "Marital status")}
        </FieldRow>
        {select("vulnerability_category", "vulnerability_categories", "Vulnerability category", "None", true)}
      </FormSection>

      <FormSection title="Contact">
        <FieldRow>
          {text("phone", "Phone number", { type: "tel", inputMode: "tel" })}
          {text("alternative_contact", "Alternative contact", { type: "tel", inputMode: "tel" })}
        </FieldRow>
        <FieldRow>
          {text("next_of_kin", "Next of kin")}
          {text("next_of_kin_contact", "Next of kin contact", { type: "tel", inputMode: "tel" })}
        </FieldRow>
      </FormSection>

      <FormSection
        title="Where they are"
        description={project ? `Project: ${project.name}. Region, district and ward follow from the village.` : "Region, district and ward follow from the village."}
      >
        <FieldRow>
          <Field label="Village" htmlFor="pap-location_id" error={errors.location_id}>
            <Select id="pap-location_id" placeholder="Not recorded" value={values.location_id} onChange={(event) => set("location_id")(event.target.value)}>
              {(villages?.data ?? []).map((location) => (
                <option key={location.id} value={location.id}>
                  {location.path ?? location.name}
                </option>
              ))}
            </Select>
          </Field>
          {text("kitongoji", "Kitongoji")}
        </FieldRow>
        <Field label="Physical address" htmlFor="pap-physical_address" error={errors.physical_address}>
          <Textarea id="pap-physical_address" rows={2} value={values.physical_address} onChange={(event) => set("physical_address")(event.target.value)} />
        </Field>
        <FieldRow columns={3}>
          {text("latitude", "GPS latitude", { inputMode: "decimal" })}
          {text("longitude", "GPS longitude", { inputMode: "decimal" })}
          <div className="flex items-end">
            <Button type="button" variant="secondary" icon={<LocateFixed className="h-4 w-4" />} loading={locating} onClick={locate} fullWidth>
              Use this device&apos;s location
            </Button>
          </div>
        </FieldRow>
      </FormSection>

      <FormSection title="Household and remarks">
        {text("household_id", "Household ID", {
          hint: pap ? "Change only to move this PAP into another household." : "Leave blank to start a new household, or enter an existing HH- number to join it.",
        })}
        <Field label="Remarks" htmlFor="pap-remarks" error={errors.remarks}>
          <Textarea id="pap-remarks" rows={3} value={values.remarks} onChange={(event) => set("remarks")(event.target.value)} />
        </Field>
        {!pap ? (
          <p className="text-sm text-ink-600">The photograph is added on the PAP&apos;s record after saving — use “Take a photo” under Files.</p>
        ) : null}
      </FormSection>

      <FormActions note={!sync.online ? "Offline — nothing can be saved until you reconnect." : "Saves to the server and updates the stakeholder register."}>
        <Button variant="secondary" type="button" onClick={() => router.back()}>
          Cancel
        </Button>
        <Button variant="primary" type="submit" loading={busy} disabled={!sync.online} icon={<Save className="h-4 w-4" />}>
          {pap ? "Save changes" : "Add to the register"}
        </Button>
      </FormActions>
    </form>
  );
}

function ageFrom(date: string): number | null {
  const born = new Date(date);
  if (Number.isNaN(born.getTime())) return null;
  const today = new Date();
  let age = today.getFullYear() - born.getFullYear();
  const beforeBirthday =
    today.getMonth() < born.getMonth() || (today.getMonth() === born.getMonth() && today.getDate() < born.getDate());
  if (beforeBirthday) age -= 1;
  return age >= 0 ? age : null;
}
