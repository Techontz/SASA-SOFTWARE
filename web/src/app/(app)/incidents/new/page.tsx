"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { Send, WifiOff } from "lucide-react";
import { useRouter } from "next/navigation";
import { useMemo, useState } from "react";
import { Controller, useForm, useWatch } from "react-hook-form";
import { z } from "zod";
import { AttachmentPicker, queueAttachments } from "@/components/app/AttachmentPicker";
import { SeriousBadge } from "@/components/incidents/IncidentBadges";
import { ChoiceGrid } from "@/components/incidents/ChoiceGrid";
import { Button } from "@/components/ui/Button";
import { PageHeader } from "@/components/ui/DetailLayout";
import { Field, FieldRow, FormActions, FormSection, Input, Select, Textarea } from "@/components/ui/Form";
import { ErrorState, LoadingState, PermissionDenied } from "@/components/ui/States";
import { ApiRequestError } from "@/lib/api/client";
import { useLocations } from "@/lib/api/hooks";
import { useIncidentOptions, useReportIncident } from "@/lib/api/incidents";
import { groupTypesForCategory, isSeriousIncident, nowForDateTimeInput } from "@/lib/incidents";
import { uuid } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";
import { useSync } from "@/providers/SyncProvider";
import { useToast } from "@/providers/ToastProvider";

const schema = z
  .object({
    occurred_at: z.string().min(1, "Give the date and time of the incident."),
    company: z.string().optional(),
    location_id: z.string().optional(),
    site_detail: z.string().max(255).optional(),
    category: z.string().min(1, "Choose a category."),
    incident_type: z.string().min(1, "Choose the incident type."),
    actual_consequence: z.string().min(1, "Choose the actual consequence."),
    potential_consequence: z.string().min(1, "Choose the potential consequence."),
    summary: z.string().trim().min(10, "Describe what happened — at least a sentence."),
    contractor_manager: z.string().optional(),
    resident_engineer: z.string().optional(),
  })
  .refine(
    (values) =>
      !values.actual_consequence ||
      !values.potential_consequence ||
      Number(values.potential_consequence) >= Number(values.actual_consequence),
    { path: ["potential_consequence"], message: "The potential consequence cannot be lower than what actually happened." },
  );

type FormValues = z.infer<typeof schema>;

export default function ReportIncidentPage() {
  const router = useRouter();
  const toast = useToast();
  const sync = useSync();
  const { can, user } = useSession();
  const options = useIncidentOptions();
  const { data: locationData } = useLocations();
  const report = useReportIncident();
  const [files, setFiles] = useState<File[]>([]);
  const [clientUuid] = useState(() => uuid());

  const {
    register,
    handleSubmit,
    control,
    setError,
    setValue,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      occurred_at: nowForDateTimeInput(),
      category: "",
      incident_type: "",
      actual_consequence: "",
      potential_consequence: "",
      summary: "",
    },
  });

  const category = useWatch({ control, name: "category" });
  const typeKey = useWatch({ control, name: "incident_type" });
  const potential = useWatch({ control, name: "potential_consequence" });

  const vocabulary = options.data?.data;
  const grouped = useMemo(() => groupTypesForCategory(vocabulary?.types ?? [], category), [vocabulary, category]);
  const chosenType = vocabulary?.types.find((type) => type.key === typeKey);
  const serious = vocabulary
    ? isSeriousIncident({
        potentialConsequence: potential === "" || potential === undefined ? null : Number(potential),
        type: chosenType,
        seriousLevel: vocabulary.serious_potential_level,
      })
    : false;

  if (!can("incident.report")) return <PermissionDenied what="reporting incidents" />;
  if (options.isLoading) return <LoadingState label="Opening the incident form" />;
  if (options.isError || !vocabulary) return <ErrorState error={options.error} onRetry={() => void options.refetch()} />;

  const levelOptions = vocabulary.consequence_levels.map((level) => ({
    value: String(level.level),
    label: level.label,
  }));
  const alarming = (value: string) => Number(value) >= vocabulary.serious_potential_level;

  const onSubmit = handleSubmit(async (values) => {
    if (!sync.online) return;

    try {
      const result = await report.mutateAsync({
        client_uuid: clientUuid,
        occurred_at: new Date(values.occurred_at).toISOString(),
        company: values.company || null,
        location_id: values.location_id ? Number(values.location_id) : null,
        site_detail: values.site_detail?.trim() || null,
        category: values.category,
        incident_type: values.incident_type,
        actual_consequence: Number(values.actual_consequence),
        potential_consequence: Number(values.potential_consequence),
        summary: values.summary.trim(),
        contractor_manager: values.contractor_manager || null,
        resident_engineer: values.resident_engineer || null,
      });

      const incident = result.data;

      if (files.length > 0) {
        await queueAttachments({ entity: "incident", entityUuid: clientUuid, serverId: incident.id, files });
      }

      toast.success(
        `${incident.reference} submitted`,
        files.length > 0 ? "The report is on the server. Your files are uploading now." : "The report is on the server.",
      );
      router.push(`/incidents/${incident.id}`);
    } catch (error) {
      if (error instanceof ApiRequestError && error.validationErrors) {
        Object.entries(error.validationErrors).forEach(([field, messages]) => {
          if (field in schema.shape) {
            setError(field as keyof FormValues, { message: messages[0] });
          }
        });
      }
      toast.error("The report was not submitted", error instanceof Error ? error.message : undefined);
    }
  });

  return (
    <div className="mx-auto max-w-3xl">
      <PageHeader
        backHref="/incidents"
        eyebrow="Incident management"
        title="Incident notification report"
        description="Report it now, with what you know. A reviewer can follow up for detail."
      />

      <form onSubmit={onSubmit} className="space-y-5 pb-6" noValidate>
        {!sync.online ? (
          <div className="flex items-start gap-3 rounded-xl border border-warning-500/25 bg-warning-50 p-4" role="status">
            <WifiOff className="mt-0.5 h-5 w-5 shrink-0 text-warning-700" aria-hidden />
            <p className="text-sm text-warning-800">
              You are offline. Incident reports need a connection to submit, and nothing on this form has been saved.
              Keep this page open and submit when the signal returns.
            </p>
          </div>
        ) : null}

        <FormSection title="When and where">
          <FieldRow>
            <Field label="Date and time" required error={errors.occurred_at?.message} htmlFor="occurred_at">
              <Input id="occurred_at" type="datetime-local" invalid={Boolean(errors.occurred_at)} {...register("occurred_at")} />
            </Field>
            <Field
              label="Company"
              optional
              error={errors.company?.message}
              htmlFor="company"
              hint={vocabulary.companies.length === 0 ? "No companies are configured for this project yet." : undefined}
            >
              <Select id="company" placeholder="Choose a company" disabled={vocabulary.companies.length === 0} {...register("company")}>
                {vocabulary.companies.map((company) => (
                  <option key={company} value={company}>
                    {company}
                  </option>
                ))}
              </Select>
            </Field>
          </FieldRow>
          <FieldRow>
            <Field label="Site / location" optional error={errors.location_id?.message} htmlFor="location_id">
              <Select id="location_id" placeholder="Choose a location" {...register("location_id")}>
                {(locationData?.data ?? []).map((location) => (
                  <option key={location.id} value={location.id}>
                    {location.path ?? location.name}
                  </option>
                ))}
              </Select>
            </Field>
            <Field label="Site detail" optional error={errors.site_detail?.message} htmlFor="site_detail">
              <Input id="site_detail" placeholder="Tower T-118, stringing yard, km 14…" {...register("site_detail")} />
            </Field>
          </FieldRow>
        </FormSection>

        <FormSection title="What happened">
          <Field label="Category" required error={errors.category?.message}>
            <Controller
              control={control}
              name="category"
              render={({ field }) => (
                <ChoiceGrid
                  name="category"
                  legend="Category"
                  options={vocabulary.categories.map((entry) => ({ value: entry.key, label: entry.label }))}
                  value={field.value}
                  invalid={Boolean(errors.category)}
                  onChange={(value) => {
                    field.onChange(value);
                    // A near miss has one obvious type; every other choice is the reporter's.
                    if (value === "near_miss" && !typeKey) setValue("incident_type", "near_miss");
                  }}
                />
              )}
            />
          </Field>

          <Field
            label="Incident type"
            required
            error={errors.incident_type?.message}
            htmlFor="incident_type"
            hint={category ? "Types for the chosen category are listed first. Any type can be chosen." : undefined}
          >
            <Select id="incident_type" placeholder="Choose the incident type" invalid={Boolean(errors.incident_type)} {...register("incident_type")}>
              {grouped.suggested.length > 0 ? (
                <optgroup label="For this category">
                  {grouped.suggested.map((type) => (
                    <option key={type.key} value={type.key}>
                      {type.label}
                    </option>
                  ))}
                </optgroup>
              ) : null}
              <optgroup label={grouped.suggested.length > 0 ? "All other types" : "All types"}>
                {grouped.others.map((type) => (
                  <option key={type.key} value={type.key}>
                    {type.label}
                  </option>
                ))}
              </optgroup>
            </Select>
          </Field>

          <Field label="Actual consequence" required error={errors.actual_consequence?.message}>
            <Controller
              control={control}
              name="actual_consequence"
              render={({ field }) => (
                <ChoiceGrid
                  name="actual_consequence"
                  legend="Actual consequence"
                  options={levelOptions}
                  value={field.value}
                  onChange={field.onChange}
                  columns={3}
                  invalid={Boolean(errors.actual_consequence)}
                  danger={alarming}
                />
              )}
            />
          </Field>

          <Field
            label="Potential consequence"
            required
            error={errors.potential_consequence?.message}
            hint="The worst that could realistically have happened."
          >
            <Controller
              control={control}
              name="potential_consequence"
              render={({ field }) => (
                <ChoiceGrid
                  name="potential_consequence"
                  legend="Potential consequence"
                  options={levelOptions}
                  value={field.value}
                  onChange={field.onChange}
                  columns={3}
                  invalid={Boolean(errors.potential_consequence)}
                  danger={alarming}
                />
              )}
            />
          </Field>

          {serious ? (
            <div className="flex flex-wrap items-center gap-3 rounded-lg border border-danger-500/25 bg-danger-50 p-3" role="status">
              <SeriousBadge />
              <p className="min-w-0 flex-1 text-sm text-danger-800">
                This will be flagged as a serious incident and incident managers are told straight away.
              </p>
            </div>
          ) : null}

          <Field label="Summary" required error={errors.summary?.message} htmlFor="summary">
            <Textarea
              id="summary"
              rows={5}
              placeholder="What happened, who was involved, what was done immediately."
              invalid={Boolean(errors.summary)}
              {...register("summary")}
            />
          </Field>
        </FormSection>

        <FormSection title="People">
          <Field label="Reported by" hint="Recorded automatically as the signed-in user.">
            <p className="sasa-field flex items-center bg-surface-sunken text-ink-800">{user?.name ?? "You"}</p>
          </Field>
          <FieldRow>
            <Field label="Contractor manager" optional error={errors.contractor_manager?.message} htmlFor="contractor_manager">
              <Select
                id="contractor_manager"
                placeholder={vocabulary.contractor_managers.length ? "Choose" : "None configured"}
                disabled={vocabulary.contractor_managers.length === 0}
                {...register("contractor_manager")}
              >
                {vocabulary.contractor_managers.map((name) => (
                  <option key={name} value={name}>
                    {name}
                  </option>
                ))}
              </Select>
            </Field>
            <Field label="Consultant's resident engineer" optional error={errors.resident_engineer?.message} htmlFor="resident_engineer">
              <Select
                id="resident_engineer"
                placeholder={vocabulary.resident_engineers.length ? "Choose" : "None configured"}
                disabled={vocabulary.resident_engineers.length === 0}
                {...register("resident_engineer")}
              >
                {vocabulary.resident_engineers.map((name) => (
                  <option key={name} value={name}>
                    {name}
                  </option>
                ))}
              </Select>
            </Field>
          </FieldRow>
        </FormSection>

        <FormSection title="Photos, videos or documents" description="Optional — but a photo taken now is worth a page of description later.">
          <AttachmentPicker
            files={files}
            onChange={setFiles}
            hint="Files upload as soon as the report is submitted."
          />
        </FormSection>

        <FormActions
          note={
            !sync.online
              ? "Offline — a connection is needed to submit. Nothing has been saved."
              : files.length > 0
                ? `${files.length} file${files.length === 1 ? "" : "s"} will upload after you submit.`
                : undefined
          }
        >
          <Button variant="secondary" type="button" onClick={() => router.back()}>
            Cancel
          </Button>
          <Button
            variant="primary"
            size="lg"
            type="submit"
            loading={isSubmitting}
            disabled={!sync.online}
            icon={<Send className="h-4 w-4" />}
          >
            SUBMIT
          </Button>
        </FormActions>
      </form>
    </div>
  );
}
