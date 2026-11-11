"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { Send, WifiOff } from "lucide-react";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { useForm, useWatch } from "react-hook-form";
import { z } from "zod";
import { AttachmentPicker } from "@/components/app/AttachmentPicker";
import { Button } from "@/components/ui/Button";
import { PageHeader } from "@/components/ui/DetailLayout";
import { Field, FieldRow, FormActions, FormSection, Input, Select, Textarea } from "@/components/ui/Form";
import { ErrorState, LoadingState, PermissionDenied } from "@/components/ui/States";
import { ApiRequestError } from "@/lib/api/client";
import { uploadEshsFile, useEshsOptions, useRaiseEshsAnomaly } from "@/lib/api/eshs";
import { useLocations } from "@/lib/api/hooks";
import { subcategoriesFor } from "@/lib/eshs";
import { useSession } from "@/providers/SessionProvider";
import { useSync } from "@/providers/SyncProvider";
import { useToast } from "@/providers/ToastProvider";

const today = () => new Date().toISOString().slice(0, 10);

const schema = z.object({
  raised_on: z.string().min(1, "Give the date the anomaly was raised."),
  location_id: z.string().optional(),
  contractor: z.string().min(1, "Choose the contractor responsible."),
  subcontractor: z.string().optional(),
  category: z.string().min(1, "Choose the ESHS category."),
  subcategory: z.string().min(1, "Choose a subcategory."),
  description: z.string().min(10, "Describe what was found — what, where and why it matters."),
  anomaly_type: z.string().min(1, "Choose the anomaly type."),
  risk_rating: z.string().min(1, "Choose a risk rating."),
  root_cause: z.string().optional(),
  contractor_action: z.string().optional(),
  action_owner_name: z.string().optional(),
  action_owner_email: z.union([z.literal(""), z.string().email("That e-mail address does not look right.")]).optional(),
  action_owner_phone: z.string().optional(),
  target_date: z.string().optional(),
});

type FormValues = z.infer<typeof schema>;

/** Root cause is stored as heading + cause; the select carries both. */
const SEPARATOR = "::";

export default function NewEshsAnomalyPage() {
  const router = useRouter();
  const toast = useToast();
  const sync = useSync();
  const { can } = useSession();
  const options = useEshsOptions();
  const { data: locationData } = useLocations();
  const raise = useRaiseEshsAnomaly();
  const [files, setFiles] = useState<File[]>([]);

  const {
    register,
    handleSubmit,
    control,
    setValue,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { raised_on: today(), risk_rating: "medium", category: "", subcategory: "" },
  });

  const category = useWatch({ control, name: "category" });

  if (!can("eshs.raise")) return <PermissionDenied what="raising ESHS anomalies" />;
  if (options.isLoading) return <LoadingState label="Loading the lists" />;
  if (options.isError || !options.data) return <ErrorState error={options.error} onRetry={() => void options.refetch()} />;

  const lists = options.data.data;
  const subcategories = subcategoriesFor(lists.categories, category);

  const onSubmit = handleSubmit(async (values) => {
    if (!sync.online) return;

    const [group, cause] = values.root_cause ? values.root_cause.split(SEPARATOR) : [null, null];

    try {
      const result = await raise.mutateAsync({
        raised_on: values.raised_on,
        location_id: values.location_id ? Number(values.location_id) : null,
        contractor: values.contractor,
        subcontractor: values.subcontractor || null,
        category: values.category,
        subcategory: values.subcategory,
        description: values.description,
        anomaly_type: values.anomaly_type,
        risk_rating: values.risk_rating,
        root_cause_group: group || null,
        root_cause: cause || null,
        contractor_action: values.contractor_action || null,
        action_owner_name: values.action_owner_name || null,
        action_owner_email: values.action_owner_email || null,
        action_owner_phone: values.action_owner_phone || null,
        target_date: values.target_date || null,
      });

      const anomaly = result.data;
      const failed: string[] = [];

      for (const file of files) {
        try {
          await uploadEshsFile(anomaly.id, file, "evidence");
        } catch {
          failed.push(file.name);
        }
      }

      if (failed.length > 0) {
        toast.error(
          `${anomaly.reference} is saved, but some evidence did not upload`,
          `Not uploaded: ${failed.join(", ")}. Add them again from the anomaly page.`,
        );
      } else {
        toast.success(`${anomaly.reference} raised`, `Saved on the server and sent to ${anomaly.contractor}.`);
      }

      router.push(`/eshs/${anomaly.id}`);
    } catch (error) {
      if (error instanceof ApiRequestError && error.validationErrors) {
        Object.entries(error.validationErrors).forEach(([field, messages]) => {
          const key = (field === "root_cause_group" ? "root_cause" : field) as keyof FormValues;
          setError(key, { message: messages[0] });
        });
      }
      toast.error("Nothing was saved", error instanceof Error ? error.message : undefined);
    }
  });

  return (
    <div className="mx-auto max-w-3xl">
      <PageHeader
        backHref="/eshs"
        eyebrow="ESHS contractor assurance"
        title="Raise an anomaly"
        description="For the client and the project supervision team. The contractor is told, takes the action and uploads closure evidence for you to verify."
      />

      <form onSubmit={onSubmit} className="space-y-5 pb-6" noValidate>
        {!sync.online ? (
          <div className="flex items-start gap-3 rounded-xl border border-warning-500/25 bg-warning-50 p-4" role="status">
            <WifiOff className="mt-0.5 h-5 w-5 shrink-0 text-warning-600" aria-hidden />
            <p className="text-sm text-warning-800">
              You are offline. Raising an anomaly needs a connection — nothing on this form has been saved. Keep the page open
              and submit when the signal returns.
            </p>
          </div>
        ) : null}

        <FormSection title="What was found">
          <FieldRow>
            <Field label="Date raised" required error={errors.raised_on?.message} htmlFor="raised_on">
              <Input id="raised_on" type="date" max={today()} invalid={Boolean(errors.raised_on)} {...register("raised_on")} />
            </Field>
            <Field label="Area / location" optional htmlFor="location_id">
              <Select id="location_id" placeholder="Not recorded" {...register("location_id")}>
                {(locationData?.data ?? []).map((location) => (
                  <option key={location.id} value={location.id}>
                    {location.path ?? location.name}
                  </option>
                ))}
              </Select>
            </Field>
          </FieldRow>

          <FieldRow>
            <Field label="ESHS category" required error={errors.category?.message} htmlFor="category">
              <Select
                id="category"
                placeholder="Choose a category"
                invalid={Boolean(errors.category)}
                {...register("category", {
                  onChange: () => setValue("subcategory", ""),
                })}
              >
                {lists.categories.map((entry) => (
                  <option key={entry.key} value={entry.key}>
                    {entry.label}
                  </option>
                ))}
              </Select>
            </Field>
            <Field
              label="Subcategory"
              required
              error={errors.subcategory?.message}
              htmlFor="subcategory"
              hint={category ? undefined : "Choose a category first."}
            >
              <Select
                id="subcategory"
                placeholder={category ? "Choose a subcategory" : "—"}
                disabled={!category}
                invalid={Boolean(errors.subcategory)}
                {...register("subcategory")}
              >
                {subcategories.map((entry) => (
                  <option key={entry} value={entry}>
                    {entry}
                  </option>
                ))}
              </Select>
            </Field>
          </FieldRow>

          <Field label="Anomaly description" required error={errors.description?.message} htmlFor="description">
            <Textarea
              id="description"
              rows={5}
              placeholder="Two linesmen on tower T-112 working without a second lanyard attached during conductor stringing."
              invalid={Boolean(errors.description)}
              {...register("description")}
            />
          </Field>

          <FieldRow>
            <Field label="Anomaly type" required error={errors.anomaly_type?.message} htmlFor="anomaly_type">
              <Select id="anomaly_type" placeholder="Choose a type" invalid={Boolean(errors.anomaly_type)} {...register("anomaly_type")}>
                {lists.anomaly_types.map((type) => (
                  <option key={type} value={type}>
                    {type}
                  </option>
                ))}
              </Select>
            </Field>
            <Field label="Risk rating" required error={errors.risk_rating?.message} htmlFor="risk_rating">
              <Select id="risk_rating" invalid={Boolean(errors.risk_rating)} {...register("risk_rating")}>
                {lists.risk_ratings.map((risk) => (
                  <option key={risk.key} value={risk.key}>
                    {risk.label}
                  </option>
                ))}
              </Select>
            </Field>
          </FieldRow>

          <Field label="Root cause" optional error={errors.root_cause?.message} htmlFor="root_cause">
            <Select id="root_cause" placeholder="Not yet known" {...register("root_cause")}>
              {lists.root_causes.map((group) => (
                <optgroup key={group.group} label={group.group}>
                  {group.causes.map((cause) => (
                    <option key={cause} value={`${group.group}${SEPARATOR}${cause}`}>
                      {cause}
                    </option>
                  ))}
                </optgroup>
              ))}
            </Select>
          </Field>
        </FormSection>

        <FormSection title="Who is responsible" description="All actions are the contractor's.">
          <FieldRow>
            <Field
              label="Contractor"
              required
              error={errors.contractor?.message}
              htmlFor="contractor"
              hint={lists.contractors.length === 0 ? "No contractor list is configured for this project yet — type the company name." : undefined}
            >
              {lists.contractors.length > 0 ? (
                <Select id="contractor" placeholder="Choose a contractor" invalid={Boolean(errors.contractor)} {...register("contractor")}>
                  {lists.contractors.map((name) => (
                    <option key={name} value={name}>
                      {name}
                    </option>
                  ))}
                </Select>
              ) : (
                <Input id="contractor" invalid={Boolean(errors.contractor)} {...register("contractor")} />
              )}
            </Field>
            <Field label="Subcontractor" optional error={errors.subcontractor?.message} htmlFor="subcontractor">
              {lists.subcontractors.length > 0 ? (
                <Select id="subcontractor" placeholder="None" {...register("subcontractor")}>
                  {lists.subcontractors.map((name) => (
                    <option key={name} value={name}>
                      {name}
                    </option>
                  ))}
                </Select>
              ) : (
                <Input id="subcontractor" {...register("subcontractor")} />
              )}
            </Field>
          </FieldRow>

          <Field label="Action by contractor" optional htmlFor="contractor_action" hint="What the contractor must do to put it right.">
            <Textarea id="contractor_action" rows={3} {...register("contractor_action")} />
          </Field>

          <FieldRow columns={3}>
            <Field label="Action owner" optional htmlFor="action_owner_name">
              <Input id="action_owner_name" placeholder="Kisare Lutambi" {...register("action_owner_name")} />
            </Field>
            <Field label="Owner e-mail" optional error={errors.action_owner_email?.message} htmlFor="action_owner_email">
              <Input
                id="action_owner_email"
                type="email"
                autoCapitalize="none"
                invalid={Boolean(errors.action_owner_email)}
                {...register("action_owner_email")}
              />
            </Field>
            <Field label="Owner phone" optional htmlFor="action_owner_phone">
              <Input id="action_owner_phone" type="tel" placeholder="+255 764 309 306" {...register("action_owner_phone")} />
            </Field>
          </FieldRow>

          <Field label="Target date" optional htmlFor="target_date" hint="After this date an open anomaly counts as overdue.">
            <Input id="target_date" type="date" {...register("target_date")} />
          </Field>
        </FormSection>

        <FormSection title="Evidence of the anomaly" description="Optional. Photographs, documents or video.">
          <AttachmentPicker files={files} onChange={setFiles} hint="Files upload to the server as soon as the anomaly is saved." />
        </FormSection>

        <FormActions note={!sync.online ? "Offline — connect to raise this anomaly. Nothing has been saved." : undefined}>
          <Button variant="secondary" type="button" onClick={() => router.back()}>
            Cancel
          </Button>
          <Button variant="primary" type="submit" loading={isSubmitting} disabled={!sync.online} icon={<Send className="h-4 w-4" />}>
            Raise anomaly
          </Button>
        </FormActions>
      </form>
    </div>
  );
}
