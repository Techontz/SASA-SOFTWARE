"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { Save } from "lucide-react";
import { useRouter } from "next/navigation";
import { Controller, useForm } from "react-hook-form";
import { z } from "zod";
import { useState } from "react";
import { Button } from "@/components/ui/Button";
import { SavedOnDeviceNotice } from "@/components/app/SavedOnDeviceNotice";
import { Field, FieldRow, FormActions, FormSection, Input, Select, Textarea } from "@/components/ui/Form";
import { PageHeader } from "@/components/ui/DetailLayout";
import { PermissionDenied } from "@/components/ui/States";
import { useLocations, useProjectMembers, useStakeholders } from "@/lib/api/hooks";
import { syncEngine } from "@/lib/offline/syncEngine";
import { uuid } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";
import { useSync } from "@/providers/SyncProvider";
import { useToast } from "@/providers/ToastProvider";
import type { Commitment } from "@/types/api";

const schema = z.object({
  commitment_text: z.string().min(10, "Write down what was promised, in the words it was promised."),
  source_date: z.string().optional(),
  due_date: z.string().optional(),
  owner_id: z.string().optional(),
  owner_team: z.string().optional(),
  risk_level: z.string(),
  priority: z.string(),
  location_id: z.string().optional(),
  stakeholder_ids: z.array(z.string()),
  notes: z.string().optional(),
});

type FormValues = z.infer<typeof schema>;

export default function NewCommitmentPage() {
  const router = useRouter();
  const toast = useToast();
  const sync = useSync();
  const { can } = useSession();
  const { data: members } = useProjectMembers();
  const { data: stakeholders } = useStakeholders({ per_page: 200, status: "active" });
  const { data: locationData } = useLocations();

  const [savedOffline, setSavedOffline] = useState(false);

  const {
    register,
    handleSubmit,
    control,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      commitment_text: "",
      risk_level: "medium",
      priority: "medium",
      stakeholder_ids: [],
      source_date: new Date().toISOString().slice(0, 10),
    },
  });

  if (!can("commitment.manage")) return <PermissionDenied what="adding commitments" />;

  const onSubmit = handleSubmit(async (values) => {
    try {
      const result = await syncEngine().save<Commitment>({
        entity: "commitment",
        operation: "create",
        path: "/commitments",
        payload: {
          commitment_text: values.commitment_text,
          source_type: "manual",
          source_date: values.source_date || null,
          due_date: values.due_date || null,
          owner_id: values.owner_id ? Number(values.owner_id) : null,
          owner_team: values.owner_team || null,
          risk_level: values.risk_level,
          priority: values.priority,
          location_id: values.location_id ? Number(values.location_id) : null,
          stakeholder_ids: values.stakeholder_ids.map(Number),
          notes: values.notes || null,
          captured_at: new Date().toISOString(),
        },
        entityUuid: uuid(),
      });

      if (result.saved === "server" && result.record) {
        toast.success(`${result.record.reference} added to the register`);
        router.push(`/commitments/${result.record.id}`);
      } else {
        toast.savedOnDevice("Commitment");
        setSavedOffline(true);
        reset();
        window.scrollTo({ top: 0, behavior: "smooth" });
      }
    } catch (error) {
      toast.error("We could not save this commitment", error instanceof Error ? error.message : undefined);
    }
  });

  return (
    <div className="mx-auto max-w-3xl">
      <PageHeader
        backHref="/commitments"
        eyebrow="Module 2 · Stakeholder management"
        title="Add a commitment"
        description="Most commitments are captured while logging an engagement. Use this for one made outside a meeting — in a letter, a call or a site visit."
      />

      <form onSubmit={onSubmit} className="space-y-5 pb-6" noValidate>
        {savedOffline ? (
          <SavedOnDeviceNotice
            what="Commitment"
            onAddAnother={() => setSavedOffline(false)}
            listHref="/commitments"
            listLabel="See the register"
          />
        ) : null}

        <FormSection title="The promise">
          <Field label="What was promised" required error={errors.commitment_text?.message} htmlFor="commitment_text">
            <Textarea
              id="commitment_text"
              rows={4}
              placeholder="Water the access road twice daily during dry-season works."
              invalid={Boolean(errors.commitment_text)}
              {...register("commitment_text")}
            />
          </Field>

          <FieldRow columns={3}>
            <Field label="Made on" optional htmlFor="source_date">
              <Input id="source_date" type="date" {...register("source_date")} />
            </Field>
            <Field label="Due by" optional htmlFor="due_date" hint="Reminders fire before and after this date.">
              <Input id="due_date" type="date" {...register("due_date")} />
            </Field>
            <Field label="Risk if it slips" htmlFor="risk_level">
              <Select id="risk_level" {...register("risk_level")}>
                <option value="low">Low</option>
                <option value="medium">Medium</option>
                <option value="high">High — escalates immediately when overdue</option>
              </Select>
            </Field>
          </FieldRow>
        </FormSection>

        <FormSection title="Who owns it" description="A commitment with no owner is a commitment nobody delivers.">
          <FieldRow>
            <Field label="Owner" optional htmlFor="owner_id">
              <Select id="owner_id" placeholder="Unassigned" {...register("owner_id")}>
                {(members?.data ?? []).map((member) => (
                  <option key={member.user.id} value={member.user.id}>
                    {member.user.name} — {member.role.name}
                  </option>
                ))}
              </Select>
            </Field>
            <Field label="Team" optional htmlFor="owner_team">
              <Input id="owner_team" placeholder="Community relations" {...register("owner_team")} />
            </Field>
          </FieldRow>

          <Field label="Promised to" optional hint="Links the commitment to their record and their timeline.">
            <Controller
              control={control}
              name="stakeholder_ids"
              render={({ field }) => (
                <div className="max-h-48 space-y-1 overflow-y-auto rounded-lg border border-ink-200 p-2">
                  {(stakeholders?.data ?? []).map((stakeholder) => {
                    const id = String(stakeholder.id);
                    const selected = field.value.includes(id);

                    return (
                      <label key={stakeholder.id} className="flex cursor-pointer items-center gap-2.5 rounded-md px-2 py-1.5 transition hover:bg-ink-50">
                        <input
                          type="checkbox"
                          checked={selected}
                          onChange={() => field.onChange(selected ? field.value.filter((entry) => entry !== id) : [...field.value, id])}
                          className="h-4 w-4 rounded border-ink-300 accent-[var(--color-brand-700)]"
                        />
                        <span className="min-w-0 flex-1 truncate text-sm text-ink-800">{stakeholder.name}</span>
                      </label>
                    );
                  })}
                </div>
              )}
            />
          </Field>

          <FieldRow>
            <Field label="Location" optional htmlFor="location_id">
              <Select id="location_id" placeholder="Not recorded" {...register("location_id")}>
                {(locationData?.data ?? []).map((location) => (
                  <option key={location.id} value={location.id}>
                    {location.path ?? location.name}
                  </option>
                ))}
              </Select>
            </Field>
            <Field label="Priority" htmlFor="priority">
              <Select id="priority" {...register("priority")}>
                <option value="low">Low</option>
                <option value="medium">Medium</option>
                <option value="high">High</option>
              </Select>
            </Field>
          </FieldRow>

          <Field label="Notes" optional htmlFor="notes">
            <Textarea id="notes" rows={3} {...register("notes")} />
          </Field>
        </FormSection>

        <FormActions note={!sync.online ? "Offline — this will be saved on the device and synced later." : undefined}>
          <Button variant="secondary" type="button" onClick={() => router.back()}>
            Cancel
          </Button>
          <Button variant="primary" type="submit" loading={isSubmitting} icon={<Save className="h-4 w-4" />}>
            Add to the register
          </Button>
        </FormActions>
      </form>
    </div>
  );
}
