"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { EyeOff, Lock, Save, ShieldCheck, Sparkles, Users } from "lucide-react";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { Controller, useForm } from "react-hook-form";
import { z } from "zod";
import { Button } from "@/components/ui/Button";
import { SavedOnDeviceNotice } from "@/components/app/SavedOnDeviceNotice";
import {
  ChoiceCard,
  Field,
  FieldRow,
  FormActions,
  FormSection,
  Input,
  Select,
  Textarea,
} from "@/components/ui/Form";
import { PageHeader } from "@/components/ui/DetailLayout";
import { PermissionDenied } from "@/components/ui/States";
import { ApiRequestError } from "@/lib/api/client";
import { useFieldReference } from "@/hooks/useFieldReference";
import { localStore } from "@/lib/offline/dexieStore";
import { syncEngine } from "@/lib/offline/syncEngine";
import { humanise, uuid } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";
import { useSync } from "@/providers/SyncProvider";
import { useToast } from "@/providers/ToastProvider";
import type { Grievance } from "@/types/api";

const schema = z
  .object({
    channel: z.string().min(1, "How did this reach you?"),
    confidentiality: z.enum(["normal", "confidential", "anonymous"]),
    received_at: z.string().min(1, "When was it received?"),
    occurred_at: z.string().optional(),
    complainant_name: z.string().optional(),
    complainant_phone: z.string().optional(),
    complainant_email: z.string().optional(),
    complainant_type: z.string().optional(),
    complainant_language: z.string().optional(),
    preferred_contact_method: z.string().optional(),
    stakeholder_id: z.string().optional(),
    location_id: z.string().optional(),
    location_text: z.string().optional(),
    precise_location: z.string().optional(),
    category_id: z.string().optional(),
    subcategory_id: z.string().optional(),
    severity: z.string().optional(),
    title: z.string().optional(),
    description: z.string().min(20, "Write down what the complainant told you — at least a sentence or two."),
    desired_resolution: z.string().optional(),
    demographics_gender: z.string().optional(),
    demographics_age_band: z.string().optional(),
  })
  .refine(
    (values) =>
      values.confidentiality === "anonymous" ||
      Boolean(values.complainant_name?.trim()) ||
      Boolean(values.complainant_phone?.trim()),
    {
      message: "Record a name or a phone number, or mark the case anonymous.",
      path: ["complainant_name"],
    },
  );

type FormValues = z.infer<typeof schema>;

export default function NewGrievancePage() {
  const router = useRouter();
  const toast = useToast();
  const sync = useSync();
  const { can, project } = useSession();
  const reference = useFieldReference();
  const [savedOffline, setSavedOffline] = useState(false);

  const {
    register,
    handleSubmit,
    watch,
    control,
    setValue,
    reset,
    formState: { errors, isSubmitting, isDirty },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      channel: "in_person",
      confidentiality: "normal",
      received_at: new Date().toISOString().slice(0, 16),
      description: "",
      complainant_type: "community_member",
    },
  });

  const values = watch();
  const isAnonymous = values.confidentiality === "anonymous";
  const draftKey = "grievance:new";

  useEffect(() => {
    if (!project || !isDirty) return;
    const timer = setTimeout(() => {
      void localStore().putDraft({
        key: draftKey,
        projectId: project.id,
        entity: "grievance",
        values: values as unknown as Record<string, unknown>,
        updatedAt: new Date().toISOString(),
      });
    }, 1200);
    return () => clearTimeout(timer);
  }, [isDirty, project, values]);

  useEffect(() => {
    if (!project) return;
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

  useEffect(() => {
    if (!isDirty) return;
    const handler = (event: BeforeUnloadEvent) => {
      event.preventDefault();
      event.returnValue = "";
    };
    window.addEventListener("beforeunload", handler);
    return () => window.removeEventListener("beforeunload", handler);
  }, [isDirty]);

  if (!can("grievance.create")) return <PermissionDenied what="logging grievances" />;

  const { categories: categoryList, severityLevels, locations, channels } = reference;
  const selectedCategory = categoryList.find((category) => String(category.id) === values.category_id);

  const onSubmit = handleSubmit(async (formValues) => {
    const anonymous = formValues.confidentiality === "anonymous";

    const payload: Record<string, unknown> = {
      channel: formValues.channel,
      confidentiality: formValues.confidentiality,
      received_at: formValues.received_at,
      occurred_at: formValues.occurred_at || null,
      complainant_type: formValues.complainant_type || null,
      complainant_language: formValues.complainant_language || null,
      preferred_contact_method: formValues.preferred_contact_method || null,
      location_id: formValues.location_id ? Number(formValues.location_id) : null,
      location_text: formValues.location_text || null,
      category_id: formValues.category_id ? Number(formValues.category_id) : null,
      subcategory_id: formValues.subcategory_id ? Number(formValues.subcategory_id) : null,
      severity: formValues.severity ? Number(formValues.severity) : null,
      title: formValues.title || null,
      description: formValues.description,
      desired_resolution: formValues.desired_resolution || null,
      demographics: {
        ...(formValues.demographics_gender ? { gender: formValues.demographics_gender } : {}),
        ...(formValues.demographics_age_band ? { age_band: formValues.demographics_age_band } : {}),
      },
      captured_at: new Date().toISOString(),
      // The anonymous promise is kept on the client too: identity is not even
      // put in the request body.
      ...(anonymous
        ? {}
        : {
            complainant_name: formValues.complainant_name || null,
            complainant_phone: formValues.complainant_phone || null,
            complainant_email: formValues.complainant_email || null,
            precise_location: formValues.precise_location || null,
            stakeholder_id: formValues.stakeholder_id ? Number(formValues.stakeholder_id) : null,
          }),
    };

    try {
      const result = await syncEngine().save<Grievance>({
        entity: "grievance",
        operation: "create",
        path: "/grievances",
        payload,
        entityUuid: uuid(),
      });

      if (project) await localStore().removeDraft(draftKey, project.id);

      if (result.saved === "server" && result.record) {
        toast.success(
          `Case ${result.record.reference} opened`,
          "The acknowledgement and resolution clocks have started.",
        );
        router.push(`/grievances/${result.record.id}`);
      } else {
        toast.savedOnDevice("Grievance");
        setSavedOffline(true);
        reset();
        window.scrollTo({ top: 0, behavior: "smooth" });
      }
    } catch (error) {
      if (error instanceof ApiRequestError) {
        toast.error("We could not open this case", error.message);
      } else {
        toast.error("We could not open this case", "Please try again.");
      }
    }
  });

  return (
    <div className="mx-auto max-w-4xl">
      <PageHeader
        backHref="/grievances"
        eyebrow="Module 3 · Grievances"
        title="Log a grievance"
        description="Record it the way it was told to you. Classification and severity can be confirmed afterwards — what matters now is that nothing is lost."
      />

      <form onSubmit={onSubmit} className="space-y-5 pb-6" noValidate>
        {savedOffline ? (
          <SavedOnDeviceNotice
            what="Grievance"
            onAddAnother={() => setSavedOffline(false)}
            listHref="/grievances"
            listLabel="See the case list"
          />
        ) : null}

        {/* --------------------- confidentiality first --------------------- */}
        <FormSection
          title="Who knows about this case"
          description="Choose before you type anything else. It decides what SASA is allowed to store and who may ever see it."
        >
          <Controller
            control={control}
            name="confidentiality"
            render={({ field }) => (
              <div className="grid gap-3 sm:grid-cols-3">
                <ChoiceCard
                  checked={field.value === "normal"}
                  onSelect={() => field.onChange("normal")}
                  title="Standard"
                  description="Anyone on the project who can see cases can see this one, including the complainant's details."
                  icon={<Users className="h-4 w-4" aria-hidden />}
                />
                <ChoiceCard
                  checked={field.value === "confidential"}
                  onSelect={() => field.onChange("confidential")}
                  title="Confidential"
                  description="The case is visible, but the complainant's name, contact and exact location go only to the handling group. Every view of them is logged."
                  icon={<Lock className="h-4 w-4" aria-hidden />}
                  tone="warning"
                />
                <ChoiceCard
                  checked={field.value === "anonymous"}
                  onSelect={() => {
                    field.onChange("anonymous");
                    setValue("complainant_name", "");
                    setValue("complainant_phone", "");
                    setValue("complainant_email", "");
                    setValue("stakeholder_id", "");
                  }}
                  title="Anonymous"
                  description="No identity is recorded at all — not even for administrators. You will not be able to contact them back."
                  icon={<EyeOff className="h-4 w-4" aria-hidden />}
                  tone="danger"
                />
              </div>
            )}
          />

          {isAnonymous ? (
            <div className="flex items-start gap-3 rounded-lg bg-danger-50 p-4 text-sm text-danger-800">
              <EyeOff className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
              <p>
                Nothing identifying will be stored, so SASA will record that acknowledging the complainant was
                not possible rather than leaving it blank. If they can be contacted, ask whether they would
                prefer <strong>confidential</strong> instead — it protects them and still lets you close the loop.
              </p>
            </div>
          ) : null}
        </FormSection>

        {/* --------------------- intake --------------------- */}
        <FormSection title="How it arrived" description="The channel is recorded, but it changes nothing about how the case is worked.">
          <FieldRow columns={3}>
            <Field label="Received via" required error={errors.channel?.message} htmlFor="channel">
              <Select id="channel" invalid={Boolean(errors.channel)} {...register("channel")}>
                {channels.map((channel) => (
                  <option key={channel} value={channel}>
                    {humanise(channel)}
                  </option>
                ))}
              </Select>
            </Field>
            <Field label="Date received" required error={errors.received_at?.message} htmlFor="received_at">
              <Input id="received_at" type="datetime-local" invalid={Boolean(errors.received_at)} {...register("received_at")} />
            </Field>
            <Field label="When it happened" optional htmlFor="occurred_at">
              <Input id="occurred_at" type="date" {...register("occurred_at")} />
            </Field>
          </FieldRow>
        </FormSection>

        {/* --------------------- complainant --------------------- */}
        {!isAnonymous ? (
          <FormSection
            title="Who raised it"
            description="Enough to reach them again. On a confidential case this is released only to the handling group."
          >
            <FieldRow>
              <Field label="Name" error={errors.complainant_name?.message} htmlFor="complainant_name">
                <Input id="complainant_name" invalid={Boolean(errors.complainant_name)} {...register("complainant_name")} />
              </Field>
              <Field label="Phone" optional htmlFor="complainant_phone" hint="Used to acknowledge and to close the loop.">
                <Input id="complainant_phone" type="tel" inputMode="tel" placeholder="+255 754 000 000" {...register("complainant_phone")} />
              </Field>
            </FieldRow>

            <FieldRow columns={3}>
              <Field label="Email" optional htmlFor="complainant_email">
                <Input id="complainant_email" type="email" autoCapitalize="none" {...register("complainant_email")} />
              </Field>
              <Field label="They are a" optional htmlFor="complainant_type">
                <Select id="complainant_type" placeholder="Not recorded" {...register("complainant_type")}>
                  <option value="community_member">Community member</option>
                  <option value="worker">Project worker</option>
                  <option value="contractor">Contractor staff</option>
                  <option value="leader">Local or traditional leader</option>
                  <option value="cso">CSO or NGO</option>
                  <option value="government">Government</option>
                  <option value="other">Other</option>
                </Select>
              </Field>
              <Field label="Language they used" optional htmlFor="complainant_language">
                <Select id="complainant_language" placeholder="Not recorded" {...register("complainant_language")}>
                  <option value="sw">Kiswahili</option>
                  <option value="en">English</option>
                </Select>
              </Field>
            </FieldRow>

            <FieldRow>
              <Field label="Best way to contact them" optional htmlFor="preferred_contact_method">
                <Select id="preferred_contact_method" placeholder="Not recorded" {...register("preferred_contact_method")}>
                  <option value="phone">Phone call</option>
                  <option value="sms">SMS</option>
                  <option value="whatsapp">WhatsApp</option>
                  <option value="in_person">In person</option>
                  <option value="through_leader">Through a local leader</option>
                </Select>
              </Field>
              <Field
                label="Already in the register?"
                optional
                htmlFor="stakeholder_id"
                hint="Linking the case puts it on that stakeholder's timeline."
              >
                <Input id="stakeholder_id" type="number" placeholder="Stakeholder record number" {...register("stakeholder_id")} />
              </Field>
            </FieldRow>
          </FormSection>
        ) : null}

        {/* --------------------- what happened --------------------- */}
        <FormSection title="What happened" description="In the complainant's own words wherever you can. This is the part an auditor reads.">
          <Field label="Short summary" optional htmlFor="title" hint="Left blank, the first line of the account is used.">
            <Input id="title" placeholder="Dust from haulage trucks is covering houses" {...register("title")} />
          </Field>

          <Field label="The account" required error={errors.description?.message} htmlFor="description">
            <Textarea
              id="description"
              rows={7}
              placeholder="Trucks pass every few minutes from early morning. The dust settles on the houses, the food and the washing, and the children have been coughing."
              invalid={Boolean(errors.description)}
              {...register("description")}
            />
          </Field>

          <Field label="What they want done" optional htmlFor="desired_resolution">
            <Textarea id="desired_resolution" rows={3} placeholder="Water the road, or move the haulage route away from the houses." {...register("desired_resolution")} />
          </Field>

          <FieldRow>
            <Field label="Where" optional htmlFor="location_id">
              <Select id="location_id" placeholder="Not recorded" {...register("location_id")}>
                {locations.map((location) => (
                  <option key={location.id} value={location.id}>
                    {location.path ?? location.name}
                  </option>
                ))}
              </Select>
            </Field>
            <Field label="Or describe the place" optional htmlFor="location_text">
              <Input id="location_text" placeholder="Near tower 142, by the school" {...register("location_text")} />
            </Field>
          </FieldRow>

          {!isAnonymous ? (
            <Field
              label="Exact location"
              optional
              htmlFor="precise_location"
              hint="Treated as a sensitive field on a confidential case, like the complainant's contact details."
            >
              <Input id="precise_location" placeholder="Plot number, landmark or coordinates" {...register("precise_location")} />
            </Field>
          ) : null}
        </FormSection>

        {/* --------------------- classification --------------------- */}
        <FormSection
          title="First classification"
          description="A first view is enough. A grievance officer confirms the category and severity afterwards, and the AI may propose one for them to check."
          aside={
            <span className="inline-flex items-center gap-1.5 rounded-full bg-brand-50 px-3 py-1.5 text-xs font-medium text-brand-800">
              <Sparkles className="h-3.5 w-3.5" aria-hidden />
              AI will suggest, a person confirms
            </span>
          }
        >
          <FieldRow columns={3}>
            <Field label="Category" optional htmlFor="category_id">
              <Select id="category_id" placeholder="Not yet classified" {...register("category_id")}>
                {categoryList.map((category) => (
                  <option key={category.id} value={category.id}>
                    {category.name}
                    {category.is_restricted ? " (restricted)" : ""}
                  </option>
                ))}
              </Select>
            </Field>
            <Field label="Subcategory" optional htmlFor="subcategory_id">
              <Select id="subcategory_id" placeholder={selectedCategory ? "Choose a subcategory" : "Choose a category first"} disabled={!selectedCategory} {...register("subcategory_id")}>
                {(selectedCategory?.subcategories ?? []).map((subcategory) => (
                  <option key={subcategory.id} value={subcategory.id}>
                    {subcategory.name}
                  </option>
                ))}
              </Select>
            </Field>
            <Field label="How serious" optional htmlFor="severity">
              <Select id="severity" placeholder="Not assessed" {...register("severity")}>
                {severityLevels.map((level) => (
                  <option key={level.level} value={level.level}>
                    {level.label}
                  </option>
                ))}
              </Select>
            </Field>
          </FieldRow>

          {selectedCategory?.is_restricted ? (
            <div className="flex items-start gap-3 rounded-lg bg-danger-50 p-4 text-sm text-danger-800">
              <ShieldCheck className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
              <p>
                This is a restricted category. The case will be visible only to the named handling group —
                not to other project members — and will be reported in aggregate only.
              </p>
            </div>
          ) : null}

          {severityLevels.length > 0 && values.severity ? (
            <p className="rounded-lg bg-surface-sunken p-3 text-sm text-ink-600">
              {severityLevels.find((level) => String(level.level) === values.severity)?.description}
            </p>
          ) : null}
        </FormSection>

        <FormSection
          title="About the complainant"
          description="Optional, and only what they were happy to share. Used to show who the mechanism is reaching, and who it is missing."
        >
          <FieldRow>
            <Field label="Gender" optional htmlFor="demographics_gender">
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
            !sync.online
              ? "Offline — the case will be saved on this device and opened on the server when the signal returns."
              : "Draft saved on this device as you type."
          }
        >
          <Button variant="secondary" type="button" onClick={() => router.back()}>
            Cancel
          </Button>
          <Button variant="accent" type="submit" loading={isSubmitting} icon={<Save className="h-4 w-4" />}>
            Open the case
          </Button>
        </FormActions>
      </form>
    </div>
  );
}
