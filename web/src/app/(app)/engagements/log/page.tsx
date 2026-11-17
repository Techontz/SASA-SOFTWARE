"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { Plus, Save, Trash2, Users } from "lucide-react";
import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useEffect, useState, type FormEvent } from "react";
import { Controller, useFieldArray, useForm } from "react-hook-form";
import { z } from "zod";
import { Button } from "@/components/ui/Button";
import { AttachmentPicker, queueAttachments } from "@/components/app/AttachmentPicker";
import { SavedOnDeviceNotice } from "@/components/app/SavedOnDeviceNotice";
import { Checkbox, Field, FieldRow, FormActions, FormSection, Input, Select, Textarea } from "@/components/ui/Form";
import { PageHeader } from "@/components/ui/DetailLayout";
import { LoadingState, PermissionDenied } from "@/components/ui/States";
import { ApiRequestError } from "@/lib/api/client";
import { useEngagementPlans, useStakeholders } from "@/lib/api/hooks";
import { useFieldReference } from "@/hooks/useFieldReference";
import { localStore } from "@/lib/offline/dexieStore";
import { syncEngine } from "@/lib/offline/syncEngine";
import { useRequiredFields } from "@/lib/requiredFields";
import { cn, humanise, uuid } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";
import { useSync } from "@/providers/SyncProvider";
import { useToast } from "@/providers/ToastProvider";
import type { Engagement } from "@/types/api";

const schema = z.object({
  engagement_plan_id: z.string().optional(),
  topic: z.string().min(3, "What was this engagement about?"),
  project_phase: z.string().optional(),
  held_at: z.string().min(1, "When did it take place?"),
  method: z.string().optional(),
  location_id: z.string().optional(),
  location_text: z.string().optional(),
  venue: z.string().optional(),
  organised_by: z.string().optional(),
  facilitator_id: z.string().optional(),
  aim: z.string().optional(),
  discussion_points: z.string().optional(),
  outcomes: z.string().optional(),
  attendance_total: z.string().optional(),
  attendance_female: z.string().optional(),
  attendance_male: z.string().optional(),
  attendance_youth: z.string().optional(),
  attendance_elderly: z.string().optional(),
  attendance_disability: z.string().optional(),
  attendance_vulnerable: z.string().optional(),
  vulnerable_groups_present: z.boolean(),
  stakeholder_ids: z.array(z.string()),
  concerns: z.array(
    z.object({
      title: z.string().optional(),
      description: z.string().min(5, "Write down what the concern was."),
      severity_hint: z.string().optional(),
      grievance_category_id: z.string().optional(),
      raised_by: z.string().optional(),
    }),
  ),
  commitments: z.array(
    z.object({
      commitment_text: z.string().min(5, "Write down what was promised."),
      owner_id: z.string().optional(),
      due_date: z.string().optional(),
      risk_level: z.string().optional(),
    }),
  ),
});

type FormValues = z.infer<typeof schema>;

/** "Was this planned?" — an explicit "no" is an answer, so it has a value of its own. */
const NOT_PLANNED = "none";

const MESSAGES = {
  engagement_plan_id: "Say whether this was planned.",
  stakeholder_ids: "Choose at least one stakeholder who was there.",
};

export default function LogEngagementPage() {
  return (
    <Suspense fallback={<LoadingState />}>
      <LogEngagementForm />
    </Suspense>
  );
}

function LogEngagementForm() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const toast = useToast();
  const sync = useSync();
  const { can, project } = useSession();

  const { data: plans } = useEngagementPlans({ status: "planned", per_page: 100 });
  const { data: stakeholders } = useStakeholders({ per_page: 200, status: "active" });
  const reference = useFieldReference();
  const [savedOffline, setSavedOffline] = useState(false);
  const [files, setFiles] = useState<File[]>([]);
  const [filesError, setFilesError] = useState<string | undefined>();

  const draftKey = "engagement:new";
  const { need, withRequired } = useRequiredFields<FormValues>("engagement", { messages: MESSAGES });

  const {
    register,
    handleSubmit,
    control,
    watch,
    reset,
    getValues,
    setValue,
    formState: { errors, isSubmitting, isDirty },
  } = useForm<FormValues>({
    resolver: withRequired(zodResolver(schema)),
    defaultValues: {
      engagement_plan_id: searchParams.get("plan") ?? "",
      topic: "",
      held_at: new Date().toISOString().slice(0, 16),
      method: "community_meeting",
      project_phase: "",
      facilitator_id: "",
      location_id: "",
      venue: "",
      organised_by: "",
      aim: "",
      discussion_points: "",
      outcomes: "",
      attendance_total: "",
      attendance_female: "",
      attendance_male: "",
      attendance_youth: "",
      attendance_elderly: "",
      attendance_disability: "",
      vulnerable_groups_present: false,
      stakeholder_ids: [],
      concerns: [],
      commitments: [],
    },
  });

  const concerns = useFieldArray({ control, name: "concerns" });
  const commitments = useFieldArray({ control, name: "commitments" });
  const values = watch();

  // Arriving from a plan's "Log what happened" link preselects that plan, but
  // the list of plans loads afterwards, so the dropdown would show "Choose"
  // while the form already holds the plan. Re-apply it once the options exist.
  useEffect(() => {
    const current = getValues("engagement_plan_id");
    if (plans?.data && current && current !== NOT_PLANNED) {
      setValue("engagement_plan_id", current);
    }
  }, [plans?.data, getValues, setValue]);

  useEffect(() => {
    if (!project || !isDirty) return;
    const timer = setTimeout(() => {
      void localStore().putDraft({
        key: draftKey,
        projectId: project.id,
        entity: "engagement",
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
          toast.info("Draft restored", "We kept the meeting record you had started on this device.");
        }
      });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [project?.id]);

  if (!can("engagement.log")) return <PermissionDenied what="logging engagements" />;

  const selectedPlan = (plans?.data ?? []).find((plan) => String(plan.id) === values.engagement_plan_id);

  const onSubmit = handleSubmit(async (formValues) => {
    const number = (value?: string) => (value ? Number(value) : 0);

    const payload: Record<string, unknown> = {
      engagement_plan_id:
        formValues.engagement_plan_id && formValues.engagement_plan_id !== NOT_PLANNED
          ? Number(formValues.engagement_plan_id)
          : null,
      topic: formValues.topic,
      project_phase: formValues.project_phase || null,
      held_at: formValues.held_at,
      method: formValues.method || null,
      location_id: formValues.location_id ? Number(formValues.location_id) : null,
      location_text: formValues.location_text || null,
      venue: formValues.venue || null,
      organised_by: formValues.organised_by || null,
      facilitator_id: formValues.facilitator_id ? Number(formValues.facilitator_id) : null,
      aim: formValues.aim || null,
      discussion_points: formValues.discussion_points || null,
      outcomes: formValues.outcomes || null,
      attendance_total: number(formValues.attendance_total),
      attendance_female: number(formValues.attendance_female),
      attendance_male: number(formValues.attendance_male),
      attendance_youth: number(formValues.attendance_youth),
      attendance_elderly: number(formValues.attendance_elderly),
      attendance_disability: number(formValues.attendance_disability),
      attendance_vulnerable: number(formValues.attendance_vulnerable),
      vulnerable_groups_present: formValues.vulnerable_groups_present,
      stakeholder_ids: formValues.stakeholder_ids.map(Number),
      captured_at: new Date().toISOString(),
      concerns: formValues.concerns.map((concern) => ({
        title: concern.title || concern.description.slice(0, 110),
        description: concern.description,
        severity_hint: concern.severity_hint || null,
        grievance_category_id: concern.grievance_category_id ? Number(concern.grievance_category_id) : null,
        raised_by: concern.raised_by || null,
      })),
      commitments: formValues.commitments.map((commitment) => ({
        commitment_text: commitment.commitment_text,
        owner_id: commitment.owner_id ? Number(commitment.owner_id) : null,
        due_date: commitment.due_date || null,
        risk_level: commitment.risk_level || "medium",
        stakeholder_ids: formValues.stakeholder_ids.map(Number),
      })),
    };

    if (files.length === 0) return;

    try {
      const entityUuid = uuid();
      const result = await syncEngine().save<Engagement>({
        entity: "engagement",
        operation: "create",
        path: "/engagements",
        payload,
        entityUuid,
      });

      await queueAttachments({
        entity: "engagement",
        entityUuid,
        serverId: result.saved === "server" && result.record ? result.record.id : null,
        files,
      });
      setFiles([]);

      if (project) await localStore().removeDraft(draftKey, project.id);

      if (result.saved === "server" && result.record) {
        const created = result.record;
        toast.success(
          `${created.reference} logged`,
          formValues.concerns.length + formValues.commitments.length > 0
            ? `${formValues.concerns.length} ${formValues.concerns.length === 1 ? "concern" : "concerns"} and ${formValues.commitments.length} ${formValues.commitments.length === 1 ? "commitment" : "commitments"} were created from it.`
            : undefined,
        );
        router.push(`/engagements/${created.id}`);
      } else {
        toast.savedOnDevice("Engagement");
        setSavedOffline(true);
        reset();
        window.scrollTo({ top: 0, behavior: "smooth" });
      }
    } catch (error) {
      toast.error(
        "We could not save this engagement",
        error instanceof ApiRequestError ? error.message : "Please try again.",
      );
    }
  });

  const { locations, members, categories, engagementMethods: methods, projectPhases: phases } = reference;

  /* Evidence is checked alongside the schema, so every missing thing shows at once. */
  const submit = (event: FormEvent<HTMLFormElement>) => {
    if (files.length === 0) setFilesError("Attach at least one file — the minutes, the attendance list or a photo.");
    return onSubmit(event);
  };

  return (
    <div className="mx-auto max-w-4xl">
      <PageHeader
        backHref="/engagements"
        eyebrow="Stakeholder management"
        title="Log an engagement"
        description="Record what happened, who was there and what was said. Concerns and commitments captured here become records of their own — nobody types them twice."
      />

      <form onSubmit={submit} className="space-y-5 pb-6" noValidate>
        {savedOffline ? (
          <SavedOnDeviceNotice
            what="Engagement"
            onAddAnother={() => setSavedOffline(false)}
            listHref="/engagements"
            listLabel="See the engagements"
          />
        ) : null}

        <FormSection title="The meeting" description="What it was, when it happened, and where.">
          <Field
            label="Was this planned?"
            {...need("engagement_plan_id")}
            error={errors.engagement_plan_id?.message}
            htmlFor="engagement_plan_id"
            hint="Linking it closes the plan and works out whether it was on time."
          >
            <Select
              id="engagement_plan_id"
              placeholder="Choose"
              invalid={Boolean(errors.engagement_plan_id)}
              {...register("engagement_plan_id")}
            >
              <option value={NOT_PLANNED}>Not planned — this was unscheduled</option>
              {(plans?.data ?? []).map((plan) => (
                <option key={plan.id} value={plan.id}>
                  {plan.reference} — {plan.title} ({plan.target_date})
                </option>
              ))}
            </Select>
          </Field>

          {selectedPlan ? (
            <p className="rounded-lg bg-brand-50 p-3 text-sm text-brand-800">
              Planned for {selectedPlan.target_date}. If you held it on a very different day, SASA will flag it
              as late or early — that gap is the point, not a problem to hide.
            </p>
          ) : null}

          <FieldRow>
            <Field label="Topic" required error={errors.topic?.message} htmlFor="topic">
              <Input id="topic" placeholder="Corridor disclosure meeting — Buswelu" invalid={Boolean(errors.topic)} {...register("topic")} />
            </Field>
            <Field label="Date and time" required error={errors.held_at?.message} htmlFor="held_at">
              <Input id="held_at" type="datetime-local" invalid={Boolean(errors.held_at)} {...register("held_at")} />
            </Field>
          </FieldRow>

          <FieldRow columns={3}>
            <Field label="How it was done" {...need("method")} error={errors.method?.message} htmlFor="method">
              <Select id="method" placeholder="Choose" invalid={Boolean(errors.method)} {...register("method")}>
                {methods.map((method) => (
                  <option key={method} value={method}>
                    {humanise(method)}
                  </option>
                ))}
              </Select>
            </Field>
            <Field label="Project phase" {...need("project_phase")} error={errors.project_phase?.message} htmlFor="project_phase">
              <Select id="project_phase" placeholder="Choose" invalid={Boolean(errors.project_phase)} {...register("project_phase")}>
                {phases.map((phase) => (
                  <option key={phase} value={phase}>
                    {humanise(phase)}
                  </option>
                ))}
              </Select>
            </Field>
            <Field label="Facilitated by" {...need("facilitator_id")} error={errors.facilitator_id?.message} htmlFor="facilitator_id">
              <Select id="facilitator_id" placeholder="Choose" invalid={Boolean(errors.facilitator_id)} {...register("facilitator_id")}>
                {members.map((member) => (
                  <option key={member.id} value={member.id}>
                    {member.name}
                  </option>
                ))}
              </Select>
            </Field>
          </FieldRow>

          <FieldRow columns={3}>
            <Field label="Location" {...need("location_id")} error={errors.location_id?.message} htmlFor="location_id">
              <Select id="location_id" placeholder="Choose a place" invalid={Boolean(errors.location_id)} {...register("location_id")}>
                {locations.map((location) => (
                  <option key={location.id} value={location.id}>
                    {location.path ?? location.name}
                  </option>
                ))}
              </Select>
            </Field>
            <Field label="Venue" {...need("venue")} error={errors.venue?.message} htmlFor="venue">
              <Input id="venue" placeholder="Village office" invalid={Boolean(errors.venue)} {...register("venue")} />
            </Field>
            <Field label="Organised by" {...need("organised_by")} error={errors.organised_by?.message} htmlFor="organised_by">
              <Input
                id="organised_by"
                placeholder="Community relations team"
                invalid={Boolean(errors.organised_by)}
                {...register("organised_by")}
              />
            </Field>
          </FieldRow>
        </FormSection>

        <FormSection
          title="Who was there"
          description="Attendance counts feed the reporting on who the project is reaching, and who it is missing."
        >
          <Field
            label="Stakeholders present"
            {...need("stakeholder_ids")}
            error={errors.stakeholder_ids?.message}
            hint="Links the meeting to their record and their timeline."
          >
            <Controller
              control={control}
              name="stakeholder_ids"
              render={({ field }) => (
                <div
                  className={cn(
                    "max-h-56 space-y-1 overflow-y-auto rounded-lg border p-2",
                    errors.stakeholder_ids ? "border-danger-500" : "border-ink-200",
                  )}
                  aria-invalid={errors.stakeholder_ids ? "true" : undefined}
                >
                  {(stakeholders?.data ?? []).map((stakeholder) => {
                    const id = String(stakeholder.id);
                    const selected = field.value.includes(id);

                    return (
                      <label
                        key={stakeholder.id}
                        className="flex cursor-pointer items-center gap-2.5 rounded-md px-2 py-1.5 transition hover:bg-ink-50"
                      >
                        <input
                          type="checkbox"
                          checked={selected}
                          onChange={() =>
                            field.onChange(selected ? field.value.filter((entry) => entry !== id) : [...field.value, id])
                          }
                          className="h-4 w-4 rounded border-ink-300 accent-[var(--color-brand-700)]"
                        />
                        <span className="min-w-0 flex-1 truncate text-sm text-ink-800">{stakeholder.name}</span>
                        <span className="shrink-0 text-xs text-ink-500">{stakeholder.type_label}</span>
                      </label>
                    );
                  })}
                </div>
              )}
            />
          </Field>

          <FieldRow columns={3}>
            <Field label="Total attending" {...need("attendance_total")} error={errors.attendance_total?.message} htmlFor="attendance_total">
              <Input
                id="attendance_total"
                type="number"
                inputMode="numeric"
                min={0}
                invalid={Boolean(errors.attendance_total)}
                {...register("attendance_total")}
              />
            </Field>
            <Field label="Of which women" {...need("attendance_female")} error={errors.attendance_female?.message} htmlFor="attendance_female">
              <Input
                id="attendance_female"
                type="number"
                inputMode="numeric"
                min={0}
                invalid={Boolean(errors.attendance_female)}
                {...register("attendance_female")}
              />
            </Field>
            <Field label="Of which men" {...need("attendance_male")} error={errors.attendance_male?.message} htmlFor="attendance_male">
              <Input
                id="attendance_male"
                type="number"
                inputMode="numeric"
                min={0}
                invalid={Boolean(errors.attendance_male)}
                {...register("attendance_male")}
              />
            </Field>
          </FieldRow>

          <FieldRow columns={3}>
            <Field label="Young people" {...need("attendance_youth")} error={errors.attendance_youth?.message} htmlFor="attendance_youth">
              <Input
                id="attendance_youth"
                type="number"
                inputMode="numeric"
                min={0}
                invalid={Boolean(errors.attendance_youth)}
                {...register("attendance_youth")}
              />
            </Field>
            <Field label="Elderly" {...need("attendance_elderly")} error={errors.attendance_elderly?.message} htmlFor="attendance_elderly">
              <Input
                id="attendance_elderly"
                type="number"
                inputMode="numeric"
                min={0}
                invalid={Boolean(errors.attendance_elderly)}
                {...register("attendance_elderly")}
              />
            </Field>
            <Field label="People with a disability" {...need("attendance_disability")} error={errors.attendance_disability?.message} htmlFor="attendance_disability">
              <Input
                id="attendance_disability"
                type="number"
                inputMode="numeric"
                min={0}
                invalid={Boolean(errors.attendance_disability)}
                {...register("attendance_disability")}
              />
            </Field>
          </FieldRow>

          <Controller
            control={control}
            name="vulnerable_groups_present"
            render={({ field }) => (
              <Checkbox
                checked={field.value}
                onChange={field.onChange}
                label="People in vulnerable situations attended"
                description="Recorded so the project can show whether engagement is reaching everyone it should."
              />
            )}
          />

          {values.vulnerable_groups_present ? (
            <Field label="How many" optional htmlFor="attendance_vulnerable">
              <Input id="attendance_vulnerable" type="number" inputMode="numeric" min={0} {...register("attendance_vulnerable")} />
            </Field>
          ) : null}
        </FormSection>

        <FormSection title="What was said" description="The minutes. This is the record an auditor reads in two years' time.">
          <Field label="Aim of the meeting" {...need("aim")} error={errors.aim?.message} htmlFor="aim">
            <Textarea id="aim" rows={2} invalid={Boolean(errors.aim)} {...register("aim")} />
          </Field>
          <Field label="Discussion points" {...need("discussion_points")} error={errors.discussion_points?.message} htmlFor="discussion_points">
            <Textarea
              id="discussion_points"
              rows={6}
              placeholder="What was presented, what was asked, and what was answered."
              invalid={Boolean(errors.discussion_points)}
              {...register("discussion_points")}
            />
          </Field>
          <Field label="Outcomes" {...need("outcomes")} error={errors.outcomes?.message} htmlFor="outcomes">
            <Textarea id="outcomes" rows={3} invalid={Boolean(errors.outcomes)} {...register("outcomes")} />
          </Field>
        </FormSection>

        <FormSection
          title="Concerns raised"
          description="Each becomes a concern record. If one later needs to become a formal grievance, it carries across without anybody retyping it."
          aside={
            <Button
              type="button"
              size="sm"
              variant="secondary"
              icon={<Plus className="h-4 w-4" />}
              onClick={() => concerns.append({ description: "", severity_hint: "medium" })}
            >
              Add a concern
            </Button>
          }
        >
          {concerns.fields.length === 0 ? (
            <p className="rounded-lg bg-surface-sunken px-4 py-5 text-center text-sm text-ink-500">
              No concerns recorded. If somebody raised something, add it — a concern in the register is what
              stops it becoming a grievance nobody saw coming.
            </p>
          ) : (
            <div className="space-y-4">
              {concerns.fields.map((entry, index) => (
                <div key={entry.id} className="rounded-lg border border-hairline p-4">
                  <div className="mb-3 flex items-center justify-between">
                    <p className="text-sm font-semibold text-ink-800">Concern {index + 1}</p>
                    <button
                      type="button"
                      onClick={() => concerns.remove(index)}
                      className="rounded p-1 text-ink-400 transition hover:bg-danger-50 hover:text-danger-600"
                      aria-label={`Remove concern ${index + 1}`}
                    >
                      <Trash2 className="h-4 w-4" aria-hidden />
                    </button>
                  </div>
                  <div className="space-y-4">
                    <Field
                      label="What was raised"
                      required
                      error={errors.concerns?.[index]?.description?.message}
                      htmlFor={`concern-${index}-description`}
                    >
                      <Textarea
                        id={`concern-${index}-description`}
                        rows={3}
                        placeholder="Dust from the access road is reaching the school."
                        {...register(`concerns.${index}.description`)}
                      />
                    </Field>
                    <FieldRow columns={3}>
                      <Field label="Raised by" optional htmlFor={`concern-${index}-raised-by`}>
                        <Input id={`concern-${index}-raised-by`} {...register(`concerns.${index}.raised_by`)} />
                      </Field>
                      <Field label="Roughly how serious" optional htmlFor={`concern-${index}-severity`}>
                        <Select id={`concern-${index}-severity`} placeholder="Not assessed" {...register(`concerns.${index}.severity_hint`)}>
                          <option value="high">High</option>
                          <option value="medium">Medium</option>
                          <option value="low">Low</option>
                        </Select>
                      </Field>
                      <Field label="Topic" optional htmlFor={`concern-${index}-category`}>
                        <Select id={`concern-${index}-category`} placeholder="Not classified" {...register(`concerns.${index}.grievance_category_id`)}>
                          {categories.map((category) => (
                            <option key={category.id} value={category.id}>
                              {category.name}
                            </option>
                          ))}
                        </Select>
                      </Field>
                    </FieldRow>
                  </div>
                </div>
              ))}
            </div>
          )}
        </FormSection>

        <FormSection
          title="Commitments made"
          description="Anything the project promised in the meeting. Each becomes an entry in the commitments register with an owner and a due date."
          aside={
            <Button
              type="button"
              size="sm"
              variant="secondary"
              icon={<Plus className="h-4 w-4" />}
              onClick={() => commitments.append({ commitment_text: "", risk_level: "medium" })}
            >
              Add a commitment
            </Button>
          }
        >
          {commitments.fields.length === 0 ? (
            <p className="rounded-lg bg-surface-sunken px-4 py-5 text-center text-sm text-ink-500">
              Nothing promised. If the project said it would do something, record it here — a promise made in
              March has to be traceable in October.
            </p>
          ) : (
            <div className="space-y-4">
              {commitments.fields.map((entry, index) => (
                <div key={entry.id} className="rounded-lg border border-hairline p-4">
                  <div className="mb-3 flex items-center justify-between">
                    <p className="text-sm font-semibold text-ink-800">Commitment {index + 1}</p>
                    <button
                      type="button"
                      onClick={() => commitments.remove(index)}
                      className="rounded p-1 text-ink-400 transition hover:bg-danger-50 hover:text-danger-600"
                      aria-label={`Remove commitment ${index + 1}`}
                    >
                      <Trash2 className="h-4 w-4" aria-hidden />
                    </button>
                  </div>
                  <div className="space-y-4">
                    <Field
                      label="What was promised"
                      required
                      error={errors.commitments?.[index]?.commitment_text?.message}
                      htmlFor={`commitment-${index}-text`}
                    >
                      <Textarea
                        id={`commitment-${index}-text`}
                        rows={3}
                        placeholder="Water the access road twice daily during dry-season works."
                        {...register(`commitments.${index}.commitment_text`)}
                      />
                    </Field>
                    <FieldRow columns={3}>
                      <Field label="Who owns it" optional htmlFor={`commitment-${index}-owner`}>
                        <Select id={`commitment-${index}-owner`} placeholder="Unassigned" {...register(`commitments.${index}.owner_id`)}>
                          {members.map((member) => (
                            <option key={member.id} value={member.id}>
                              {member.name}
                            </option>
                          ))}
                        </Select>
                      </Field>
                      <Field label="Due by" optional htmlFor={`commitment-${index}-due`}>
                        <Input id={`commitment-${index}-due`} type="date" {...register(`commitments.${index}.due_date`)} />
                      </Field>
                      <Field label="Risk if it slips" optional htmlFor={`commitment-${index}-risk`}>
                        <Select id={`commitment-${index}-risk`} {...register(`commitments.${index}.risk_level`)}>
                          <option value="low">Low</option>
                          <option value="medium">Medium</option>
                          <option value="high">High</option>
                        </Select>
                      </Field>
                    </FieldRow>
                  </div>
                </div>
              ))}
            </div>
          )}
        </FormSection>

        <FormSection
          title="Evidence"
          description="Required. Every engagement on record needs something to show it happened."
        >
          <AttachmentPicker
            files={files}
            onChange={(next) => {
              setFiles(next);
              if (next.length > 0) setFilesError(undefined);
            }}
            error={filesError}
            hint="Minutes of the meeting, the signed attendance list, photos, video or any other file. Attach at least one."
          />
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
          <Button variant="accent" type="submit" loading={isSubmitting} icon={<Save className="h-4 w-4" />}>
            Save the engagement
          </Button>
        </FormActions>
      </form>
    </div>
  );
}
