"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { AlertTriangle, Save, Users } from "lucide-react";
import { useRouter } from "next/navigation";
import { useEffect, useMemo, useState } from "react";
import { Controller, useForm } from "react-hook-form";
import { z } from "zod";
import { Button } from "@/components/ui/Button";
import { SavedOnDeviceNotice } from "@/components/app/SavedOnDeviceNotice";
import { Checkbox, Field, FieldRow, FormActions, FormSection, Input, Select, Textarea } from "@/components/ui/Form";
import { PriorityBadge } from "@/components/ui/StatusBadge";
import { ApiRequestError, apiRequest } from "@/lib/api/client";
import { usePriorityModel } from "@/lib/api/hooks";
import { useFieldReference } from "@/hooks/useFieldReference";
import { localStore } from "@/lib/offline/dexieStore";
import { syncEngine } from "@/lib/offline/syncEngine";
import { humanise, uuid, languageName } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";
import { useSync } from "@/providers/SyncProvider";
import { useToast } from "@/providers/ToastProvider";
import type { Stakeholder } from "@/types/api";

const LEVELS = ["high", "medium", "low"] as const;

const schema = z.object({
  name: z.string().min(2, "Enter the stakeholder's name."),
  type: z.string().min(1, "Choose what kind of stakeholder this is."),
  alias: z.string().optional(),
  organisation_name: z.string().optional(),
  position: z.string().optional(),
  phone: z.string().optional(),
  alternate_phone: z.string().optional(),
  email: z.union([z.string().email("That does not look like an email address."), z.literal("")]).optional(),
  preferred_language: z.string().optional(),
  preferred_contact_method: z.string().optional(),
  physical_address: z.string().optional(),
  primary_location_id: z.string().optional(),
  influence: z.enum(LEVELS).optional().or(z.literal("")),
  interest: z.enum(LEVELS).optional().or(z.literal("")),
  power: z.enum(LEVELS).optional().or(z.literal("")),
  impact: z.enum(LEVELS).optional().or(z.literal("")),
  concerns_expectations: z.string().optional(),
  notes: z.string().optional(),
  is_vulnerable: z.boolean(),
  is_indigenous_or_minority: z.boolean(),
  vulnerability_categories: z.array(z.string()),
  consent_status: z.string(),
  consent_basis: z.string().optional(),
  consent_date: z.string().optional(),
  identification_source: z.string().optional(),
  review_date: z.string().optional(),
  owner_id: z.string().optional(),
  demographics_gender: z.string().optional(),
  demographics_age_band: z.string().optional(),
});

type FormValues = z.infer<typeof schema>;

const LEVEL_POINTS: Record<string, number> = { high: 3, medium: 2, low: 1 };

/* Used when the project has not overridden the priority model. */
const DEFAULT_WEIGHTS = { influence: 1, interest: 1, power: 1, impact: 1 } as const;
const DEFAULT_THRESHOLDS = { high: 10, medium: 7 } as const;

export function StakeholderForm({ stakeholder }: { stakeholder?: Stakeholder }) {
  const router = useRouter();
  const toast = useToast();
  const sync = useSync();
  const { project } = useSession();
  const reference = useFieldReference();
  const { data: priorityModel } = usePriorityModel();

  const [duplicates, setDuplicates] = useState<Array<{ id: number; reference: string; name: string; village: string | null }>>([]);
  const [savedOffline, setSavedOffline] = useState(false);
  const draftKey = stakeholder ? `stakeholder:${stakeholder.id}` : "stakeholder:new";

  const {
    register,
    handleSubmit,
    watch,
    control,
    reset,
    formState: { errors, isSubmitting, isDirty },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      name: stakeholder?.name ?? "",
      type: stakeholder?.type ?? "",
      alias: stakeholder?.alias ?? "",
      organisation_name: stakeholder?.organisation_name ?? "",
      position: stakeholder?.position ?? "",
      phone: stakeholder?.phone ?? "",
      alternate_phone: stakeholder?.alternate_phone ?? "",
      email: stakeholder?.email ?? "",
      preferred_language: stakeholder?.preferred_language ?? "",
      preferred_contact_method: stakeholder?.preferred_contact_method ?? "",
      physical_address: stakeholder?.physical_address ?? "",
      primary_location_id: stakeholder?.location.id ? String(stakeholder.location.id) : "",
      influence: (stakeholder?.assessment.influence as FormValues["influence"]) ?? "",
      interest: (stakeholder?.assessment.interest as FormValues["interest"]) ?? "",
      power: (stakeholder?.assessment.power as FormValues["power"]) ?? "",
      impact: (stakeholder?.assessment.impact as FormValues["impact"]) ?? "",
      concerns_expectations: stakeholder?.concerns_expectations ?? "",
      notes: stakeholder?.notes ?? "",
      is_vulnerable: stakeholder?.is_vulnerable ?? false,
      is_indigenous_or_minority: stakeholder?.is_indigenous_or_minority ?? false,
      vulnerability_categories: stakeholder?.vulnerability_categories ?? [],
      consent_status: stakeholder?.consent_status ?? "not_recorded",
      consent_basis: stakeholder?.consent_basis ?? "consent",
      consent_date: stakeholder?.consent_date ?? "",
      identification_source: stakeholder?.identification_source ?? "",
      review_date: stakeholder?.review_date ?? "",
      owner_id: stakeholder?.owner?.id ? String(stakeholder.owner.id) : "",
      demographics_gender: stakeholder?.demographics?.gender ?? "",
      demographics_age_band: stakeholder?.demographics?.age_band ?? "",
    },
  });

  const values = watch();

  /* Autosave the draft locally, so a dropped connection or a closed tab never
     costs the officer the form they were halfway through. */
  useEffect(() => {
    if (!project || !isDirty) return;

    const timer = setTimeout(() => {
      void localStore().putDraft({
        key: draftKey,
        projectId: project.id,
        entity: "stakeholder",
        values: values as unknown as Record<string, unknown>,
        updatedAt: new Date().toISOString(),
      });
    }, 1200);

    return () => clearTimeout(timer);
  }, [draftKey, isDirty, project, values]);

  /* Restore an interrupted draft. */
  useEffect(() => {
    if (stakeholder || !project) return;

    void localStore()
      .getDraft(draftKey, project.id)
      .then((draft) => {
        if (draft && Object.keys(draft.values).length > 0) {
          reset(draft.values as FormValues);
          toast.info("Draft restored", "We kept what you had typed on this device.");
        }
      });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [project?.id]);

  /* Warn before leaving with unsaved changes. */
  useEffect(() => {
    if (!isDirty) return;

    const handler = (event: BeforeUnloadEvent) => {
      event.preventDefault();
      event.returnValue = "";
    };

    window.addEventListener("beforeunload", handler);
    return () => window.removeEventListener("beforeunload", handler);
  }, [isDirty]);

  /* Duplicate detection: a warning, never a block. */
  useEffect(() => {
    if (stakeholder || !values.name || values.name.length < 3 || !sync.online) return;

    const timer = setTimeout(async () => {
      try {
        const response = await apiRequest<{ data: typeof duplicates }>("/stakeholders/duplicates", {
          query: { name: values.name, phone: values.phone ?? "" },
        });
        setDuplicates(response.data);
      } catch {
        setDuplicates([]);
      }
    }, 700);

    return () => clearTimeout(timer);
  }, [stakeholder, sync.online, values.name, values.phone]);

  /* Module-level constants, so the fallback is the same object every render
     and the memo below it is not rebuilt on every keystroke. */
  const weights = priorityModel?.data.settings.weights ?? DEFAULT_WEIGHTS;
  const thresholds = priorityModel?.data.settings.thresholds ?? DEFAULT_THRESHOLDS;

  const liveScore = useMemo(() => {
    const score =
      (weights.influence ?? 0) * (LEVEL_POINTS[values.influence ?? ""] ?? 0) +
      (weights.interest ?? 0) * (LEVEL_POINTS[values.interest ?? ""] ?? 0) +
      (weights.power ?? 0) * (LEVEL_POINTS[values.power ?? ""] ?? 0) +
      (weights.impact ?? 0) * (LEVEL_POINTS[values.impact ?? ""] ?? 0);

    const priority = score >= thresholds.high ? "high" : score >= thresholds.medium ? "medium" : "low";

    return { score, priority };
  }, [thresholds, values.impact, values.influence, values.interest, values.power, weights]);

  const { locations, stakeholderTypes, vulnerabilityCategories, languages, members } = reference;

  const onSubmit = handleSubmit(async (formValues) => {
    const payload: Record<string, unknown> = {
      name: formValues.name,
      type: formValues.type,
      alias: formValues.alias || null,
      organisation_name: formValues.organisation_name || null,
      position: formValues.position || null,
      phone: formValues.phone || null,
      alternate_phone: formValues.alternate_phone || null,
      email: formValues.email || null,
      preferred_language: formValues.preferred_language || null,
      preferred_contact_method: formValues.preferred_contact_method || null,
      physical_address: formValues.physical_address || null,
      primary_location_id: formValues.primary_location_id ? Number(formValues.primary_location_id) : null,
      influence: formValues.influence || null,
      interest: formValues.interest || null,
      power: formValues.power || null,
      impact: formValues.impact || null,
      concerns_expectations: formValues.concerns_expectations || null,
      notes: formValues.notes || null,
      is_vulnerable: formValues.is_vulnerable,
      is_indigenous_or_minority: formValues.is_indigenous_or_minority,
      vulnerability_categories: formValues.vulnerability_categories,
      consent_status: formValues.consent_status,
      consent_basis: formValues.consent_basis || null,
      consent_date: formValues.consent_date || null,
      identification_source: formValues.identification_source || null,
      review_date: formValues.review_date || null,
      owner_id: formValues.owner_id ? Number(formValues.owner_id) : null,
      demographics: {
        ...(formValues.demographics_gender ? { gender: formValues.demographics_gender } : {}),
        ...(formValues.demographics_age_band ? { age_band: formValues.demographics_age_band } : {}),
      },
      captured_at: new Date().toISOString(),
    };

    try {
      const result = await syncEngine().save<Stakeholder>({
        entity: "stakeholder",
        operation: stakeholder ? "update" : "create",
        path: stakeholder ? `/stakeholders/${stakeholder.id}` : "/stakeholders",
        payload,
        entityUuid: stakeholder?.client_uuid ?? uuid(),
        serverId: stakeholder?.id,
        baseUpdatedAt: stakeholder?.updated_at ?? undefined,
        baseValues: stakeholder
          ? { name: stakeholder.name, phone: stakeholder.phone, status: stakeholder.status }
          : undefined,
      });

      if (project) await localStore().removeDraft(draftKey, project.id);

      if (result.saved === "server" && result.record) {
        toast.success(
          stakeholder ? "Stakeholder updated" : `Stakeholder added as ${result.record.reference}`,
          "It is on the server and available to the engagement and grievance modules.",
        );
        router.push(`/stakeholders/${result.record.id}`);
      } else {
        // Offline: navigating would force a full page reload, which is exactly
        // the moment a user starts to doubt their work survived. Stay put.
        toast.savedOnDevice("Stakeholder");
        setSavedOffline(true);
        reset();
        window.scrollTo({ top: 0, behavior: "smooth" });
      }
    } catch (error) {
      if (error instanceof ApiRequestError && error.isValidation) {
        toast.error("Some details need attention", error.message);
      } else {
        toast.error(
          "We could not save this record",
          error instanceof Error ? error.message : "Please try again.",
        );
      }
    }
  });

  return (
    <form onSubmit={onSubmit} className="space-y-5 pb-6" noValidate>
      {savedOffline ? (
        <SavedOnDeviceNotice
          what="Stakeholder"
          onAddAnother={() => setSavedOffline(false)}
          listHref="/stakeholders"
          listLabel="See the register"
        />
      ) : null}

      {duplicates.length > 0 ? (
        <div className="flex items-start gap-3 rounded-lg border border-warning-500/25 bg-warning-50 p-4">
          <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-warning-600" aria-hidden />
          <div className="min-w-0">
            <p className="font-medium text-warning-800">These records look similar</p>
            <p className="mt-0.5 text-sm text-warning-700">
              A village can genuinely have two people with the same name, so this is only a check — you can
              still save.
            </p>
            <ul className="mt-2 space-y-1 text-sm">
              {duplicates.map((duplicate) => (
                <li key={duplicate.id}>
                  <a href={`/stakeholders/${duplicate.id}`} className="font-medium text-warning-800 underline underline-offset-2">
                    {duplicate.reference} — {duplicate.name}
                  </a>
                  {duplicate.village ? <span className="text-warning-700"> · {duplicate.village}</span> : null}
                </li>
              ))}
            </ul>
          </div>
        </div>
      ) : null}

      <FormSection title="Who they are" description="The minimum a register entry needs.">
        <FieldRow>
          <Field label="Name" required error={errors.name?.message} htmlFor="name">
            <Input id="name" placeholder="Mwenyekiti Daniel Masanja" invalid={Boolean(errors.name)} {...register("name")} />
          </Field>
          <Field label="Type" required error={errors.type?.message} htmlFor="type">
            <Select id="type" placeholder="Choose a type" invalid={Boolean(errors.type)} {...register("type")}>
              {stakeholderTypes.map((type) => (
                <option key={type} value={type}>
                  {humanise(type)}
                </option>
              ))}
            </Select>
          </Field>
        </FieldRow>

        <FieldRow>
          <Field label="Also known as" optional htmlFor="alias">
            <Input id="alias" placeholder="Another name people use" {...register("alias")} />
          </Field>
          <Field label="Organisation" optional htmlFor="organisation_name" hint="For a CSO, authority or contractor.">
            <Input id="organisation_name" {...register("organisation_name")} />
          </Field>
        </FieldRow>

        <Field label="Position or title" optional htmlFor="position">
          <Input id="position" placeholder="Village chairperson" {...register("position")} />
        </Field>
      </FormSection>

      <FormSection title="How to reach them" description="Used for engagement invitations and grievance follow-up.">
        <FieldRow>
          <Field label="Phone" optional htmlFor="phone" hint="Also used to spot duplicate records.">
            <Input id="phone" type="tel" inputMode="tel" placeholder="+255 754 000 000" {...register("phone")} />
          </Field>
          <Field label="Second phone" optional htmlFor="alternate_phone">
            <Input id="alternate_phone" type="tel" inputMode="tel" {...register("alternate_phone")} />
          </Field>
        </FieldRow>

        <FieldRow>
          <Field label="Email" optional error={errors.email?.message} htmlFor="email">
            <Input id="email" type="email" autoCapitalize="none" invalid={Boolean(errors.email)} {...register("email")} />
          </Field>
          <Field label="Preferred language" optional htmlFor="preferred_language">
            <Select id="preferred_language" placeholder="Not recorded" {...register("preferred_language")}>
              {languages.map((language) => (
                <option key={language} value={language}>
                  {languageName(language)}
                </option>
              ))}
            </Select>
          </Field>
        </FieldRow>

        <FieldRow>
          <Field label="Preferred way to be contacted" optional htmlFor="preferred_contact_method">
            <Select id="preferred_contact_method" placeholder="Not recorded" {...register("preferred_contact_method")}>
              <option value="in_person">In person</option>
              <option value="phone">Phone call</option>
              <option value="sms">SMS</option>
              <option value="whatsapp">WhatsApp</option>
              <option value="email">Email</option>
              <option value="through_leader">Through a local leader</option>
            </Select>
          </Field>
          <Field label="Where they are" optional htmlFor="primary_location_id" hint="Village, ward or district from the project's location list.">
            <Select id="primary_location_id" placeholder="Not recorded" {...register("primary_location_id")}>
              {locations.map((location) => (
                <option key={location.id} value={location.id}>
                  {location.path ?? location.name}
                </option>
              ))}
            </Select>
          </Field>
        </FieldRow>

        <Field label="Physical address" optional htmlFor="physical_address">
          <Textarea id="physical_address" rows={2} {...register("physical_address")} />
        </Field>
      </FormSection>

      <FormSection
        title="Priority"
        description="The score is calculated as you choose. It is always shown next to whatever is finally stored, so any override is visible."
        aside={
          <div className="flex items-center gap-3 rounded-lg bg-brand-50 px-4 py-2.5">
            <div className="text-right">
              <p className="sasa-eyebrow">Score</p>
              <p className="tabular text-2xl font-semibold leading-none text-brand-900">{liveScore.score}</p>
            </div>
            <PriorityBadge priority={liveScore.priority} />
          </div>
        }
      >
        <FieldRow columns={2}>
          {(["influence", "interest", "power", "impact"] as const).map((dimension) => {
            const weight = weights[dimension] ?? 0;

            return (
              <Field
                key={dimension}
                label={humanise(dimension)}
                optional
                htmlFor={dimension}
                hint={weight === 0 ? "Switched off in this project's configuration." : `Weight ×${weight}`}
              >
                <Select id={dimension} placeholder="Not assessed" disabled={weight === 0} {...register(dimension)}>
                  <option value="high">High (3)</option>
                  <option value="medium">Medium (2)</option>
                  <option value="low">Low (1)</option>
                </Select>
              </Field>
            );
          })}
        </FieldRow>

        <p className="rounded-lg bg-surface-sunken p-3 text-sm text-ink-600">
          {thresholds.high} or more is <strong className="text-ink-800">High</strong>, {thresholds.medium} to{" "}
          {thresholds.high - 1} is <strong className="text-ink-800">Medium</strong>, below {thresholds.medium} is{" "}
          <strong className="text-ink-800">Low</strong>. Your project administrator can change these numbers
          in Configuration without a release.
        </p>
      </FormSection>

      <FormSection title="What matters to them" description="What they told you, in the words they used where you can.">
        <Field label="Concerns and expectations" optional htmlFor="concerns_expectations">
          <Textarea id="concerns_expectations" rows={4} placeholder="What they raised, and what they expect from the project." {...register("concerns_expectations")} />
        </Field>
        <Field label="Internal notes" optional htmlFor="notes" hint="Visible to project members, not to the stakeholder.">
          <Textarea id="notes" rows={3} {...register("notes")} />
        </Field>
      </FormSection>

      <FormSection
        title="Vulnerability, consent and review"
        description="Sensitive information is never required. Record only what the stakeholder agreed to share."
      >
        <div className="space-y-3">
          <Controller
            control={control}
            name="is_vulnerable"
            render={({ field }) => (
              <Checkbox
                checked={field.value}
                onChange={field.onChange}
                label="This stakeholder is in a vulnerable situation"
                description="Used to check whether engagement and the grievance mechanism are reaching everyone."
              />
            )}
          />
          <Controller
            control={control}
            name="is_indigenous_or_minority"
            render={({ field }) => (
              <Checkbox
                checked={field.value}
                onChange={field.onChange}
                label="Indigenous, ethnic or linguistic minority"
              />
            )}
          />
        </div>

        {values.is_vulnerable ? (
          <Field label="In what way" optional>
            <Controller
              control={control}
              name="vulnerability_categories"
              render={({ field }) => (
                <div className="flex flex-wrap gap-2">
                  {vulnerabilityCategories.map((category) => {
                    const selected = field.value.includes(category);

                    return (
                      <button
                        key={category}
                        type="button"
                        onClick={() =>
                          field.onChange(
                            selected ? field.value.filter((entry) => entry !== category) : [...field.value, category],
                          )
                        }
                        className={
                          selected
                            ? "rounded-full bg-primary px-3 py-1.5 text-sm font-medium text-on-primary"
                            : "rounded-full border border-ink-300 px-3 py-1.5 text-sm text-ink-700 transition hover:border-ink-400"
                        }
                      >
                        {humanise(category)}
                      </button>
                    );
                  })}
                </div>
              )}
            />
          </Field>
        ) : null}

        <FieldRow columns={3}>
          <Field label="Consent" htmlFor="consent_status" hint="Whether they agreed to their details being held.">
            <Select id="consent_status" {...register("consent_status")}>
              <option value="not_recorded">Not recorded</option>
              <option value="granted">Granted</option>
              <option value="refused">Refused</option>
              <option value="withdrawn">Withdrawn</option>
            </Select>
          </Field>
          <Field label="Legal basis" optional htmlFor="consent_basis">
            <Select id="consent_basis" placeholder="Not recorded" {...register("consent_basis")}>
              <option value="consent">Consent</option>
              <option value="legitimate_interest">Legitimate interest</option>
              <option value="legal_obligation">Legal obligation</option>
            </Select>
          </Field>
          <Field label="Consent date" optional htmlFor="consent_date">
            <Input id="consent_date" type="date" {...register("consent_date")} />
          </Field>
        </FieldRow>

        <FieldRow columns={3}>
          <Field label="How they were identified" optional htmlFor="identification_source">
            <Select id="identification_source" placeholder="Not recorded" {...register("identification_source")}>
              <option value="census">Census</option>
              <option value="survey">Household survey</option>
              <option value="village_meeting">Village meeting</option>
              <option value="self_identified">Came forward themselves</option>
              <option value="referral">Referred by a leader</option>
            </Select>
          </Field>
          <Field label="Review this record on" optional htmlFor="review_date" hint="You will be reminded when it falls due.">
            <Input id="review_date" type="date" {...register("review_date")} />
          </Field>
          <Field label="Record owner" optional htmlFor="owner_id">
            <Select id="owner_id" placeholder="Unassigned" {...register("owner_id")}>
              {members.map((member) => (
                <option key={member.id} value={member.id}>
                  {member.name}
                </option>
              ))}
            </Select>
          </Field>
        </FieldRow>

        <FieldRow>
          <Field
            label="Gender"
            optional
            htmlFor="demographics_gender"
            hint="Optional, and only if they were happy to say. Used for disaggregated reporting."
          >
            <Select id="demographics_gender" placeholder="Not recorded" {...register("demographics_gender")}>
              <option value="female">Female</option>
              <option value="male">Male</option>
              <option value="other">Other</option>
              <option value="prefer_not_to_say">Prefer not to say</option>
            </Select>
          </Field>
          <Field label="Age band" optional htmlFor="demographics_age_band">
            <Select id="demographics_age_band" placeholder="Not recorded" {...register("demographics_age_band")}>
              <option value="under_18">Under 18</option>
              <option value="18_35">18–35</option>
              <option value="36_60">36–60</option>
              <option value="over_60">Over 60</option>
            </Select>
          </Field>
        </FieldRow>
      </FormSection>

      <FormActions
        note={
          !sync.online ? (
            <span className="flex items-center gap-1.5 text-warning-700">
              <Users className="h-4 w-4" aria-hidden />
              Offline — this will be saved on the device and synced later.
            </span>
          ) : (
            "Draft saved on this device as you type."
          )
        }
      >
        <Button variant="secondary" type="button" onClick={() => router.back()}>
          Cancel
        </Button>
        <Button variant="primary" type="submit" loading={isSubmitting} icon={<Save className="h-4 w-4" />}>
          {stakeholder ? "Save changes" : "Add to the register"}
        </Button>
      </FormActions>
    </form>
  );
}
