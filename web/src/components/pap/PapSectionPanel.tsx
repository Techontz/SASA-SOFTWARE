"use client";

import { useQueryClient } from "@tanstack/react-query";
import { Archive, CalendarPlus, Lock, Pencil, Plus, Trash2, WifiOff } from "lucide-react";
import { useState } from "react";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { ConfirmDialog, Drawer } from "@/components/ui/Drawer";
import { Checkbox, Field, FieldRow, Input, Select, Textarea } from "@/components/ui/Form";
import { StatusBadge } from "@/components/ui/StatusBadge";
import { EmptyState, ErrorState, TableSkeleton } from "@/components/ui/States";
import { ApiRequestError, apiRequest } from "@/lib/api/client";
import { usePapRecords } from "@/lib/api/pap";
import { toPayloadValue } from "@/lib/pap/format";
import { formatDate } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";
import { useSync } from "@/providers/SyncProvider";
import { useToast } from "@/providers/ToastProvider";
import type { MonitoringVisit, PapOption, PapRecord } from "@/types/pap";
import { displayField, labelFor } from "./display";
import type { PapFieldDefinition, PapSectionDefinition } from "./sections";

type FormValues = Record<string, string | boolean>;

function initialValues(section: PapSectionDefinition, record: PapRecord | null): FormValues {
  const values: FormValues = {};

  section.fields.forEach((field) => {
    const value = record?.[field.key];
    if (field.kind === "boolean") {
      values[field.key] = record ? Boolean(value) : field.key === "relocation_required";
    } else {
      values[field.key] = value === null || value === undefined || Array.isArray(value) ? "" : String(value);
    }
  });

  return values;
}

function initialVisits(record: PapRecord | null): MonitoringVisit[] {
  const visits = record?.monitoring_visits;
  return Array.isArray(visits) ? visits : [];
}

/**
 * One section of a PAP record: its records as cards, with add, edit and
 * archive. Online only — the PAP register is not on the offline queue, so
 * without a connection the form says so and nothing is submitted.
 */
export function PapSectionPanel({
  papId,
  section,
  lists,
  currency,
  editable,
}: {
  papId: number;
  section: PapSectionDefinition;
  lists: Record<string, PapOption[]>;
  currency: string;
  /** False when the PAP itself is archived. */
  editable: boolean;
}) {
  const { can } = useSession();
  const sync = useSync();
  const toast = useToast();
  const queryClient = useQueryClient();
  const { data, isLoading, isError, error, refetch } = usePapRecords(papId, section.key);

  const [editing, setEditing] = useState<PapRecord | "new" | null>(null);
  const [values, setValues] = useState<FormValues>({});
  const [visits, setVisits] = useState<MonitoringVisit[]>([]);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [archiving, setArchiving] = useState<PapRecord | null>(null);

  const canWrite = editable && can(section.permission);
  const records = data?.data ?? [];

  const open = (record: PapRecord | null) => {
    setValues(initialValues(section, record));
    setVisits(initialVisits(record));
    setErrors({});
    setEditing(record ?? "new");
  };

  const refresh = async () => {
    await queryClient.invalidateQueries({ queryKey: ["pap-records", papId, section.key] });
    await queryClient.invalidateQueries({ queryKey: ["pap", papId] });
    await queryClient.invalidateQueries({ queryKey: ["pap-summary"] });
    await queryClient.invalidateQueries({ queryKey: ["paps"] });
  };

  const submit = async () => {
    if (!sync.online) return;

    const record = editing === "new" ? null : editing;
    const payload: Record<string, unknown> = {};

    section.fields.forEach((field) => {
      const raw = values[field.key] ?? "";
      // A value the viewer cannot see is never overwritten by an empty field.
      if (record && record[field.key] === undefined && raw === "") return;
      const kind = field.kind === "boolean" ? "boolean" : field.kind === "number" || field.kind === "money" ? "number" : "text";
      payload[field.key] = toPayloadValue(raw, kind);
    });

    if (section.hasVisits) {
      payload.monitoring_visits = visits
        .filter((visit) => visit.visit_date)
        .map((visit) => ({ visit_date: visit.visit_date, visited_by: visit.visited_by || null, findings: visit.findings || null }));
    }

    setBusy(true);
    setErrors({});
    try {
      await apiRequest(record ? `/paps/${papId}/${section.key}/${record.id}` : `/paps/${papId}/${section.key}`, {
        method: record ? "PATCH" : "POST",
        body: payload,
      });
      await refresh();
      toast.success(record ? `${capitalise(section.singular)} updated` : `${capitalise(section.singular)} added`, "Saved to the server.");
      setEditing(null);
    } catch (caught) {
      if (caught instanceof ApiRequestError && caught.validationErrors) {
        setErrors(Object.fromEntries(Object.entries(caught.validationErrors).map(([key, messages]) => [key, messages[0] ?? ""])));
      }
      toast.error("Nothing was saved", caught instanceof Error ? caught.message : undefined);
    } finally {
      setBusy(false);
    }
  };

  const archive = async () => {
    if (!archiving) return;
    setBusy(true);
    try {
      await apiRequest(`/paps/${papId}/${section.key}/${archiving.id}`, { method: "DELETE" });
      await refresh();
      toast.success("Archived", "It is kept in the audit trail, not deleted.");
      setArchiving(null);
    } catch (caught) {
      toast.error("We could not archive that", caught instanceof Error ? caught.message : undefined);
    } finally {
      setBusy(false);
    }
  };

  const title = (record: PapRecord) => {
    const label = labelFor(record, section.titleField);
    const raw = record[section.titleField];
    return label ?? (typeof raw === "string" && raw ? raw : (record.reference ?? capitalise(section.singular)));
  };

  return (
    <Card>
      <CardHeader
        eyebrow={`Part ${section.part}`}
        title={section.label}
        description={section.description}
        action={
          canWrite ? (
            <Button variant="secondary" size="sm" icon={<Plus className="h-4 w-4" />} onClick={() => open(null)}>
              Add {section.singular}
            </Button>
          ) : null
        }
      />
      <CardBody>
        {!canWrite && editable && section.permission === "pap.manage_payments" ? (
          <p className="mb-4 flex items-start gap-2 rounded-lg bg-surface-sunken p-3 text-sm text-ink-600">
            <Lock className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
            Recording payments needs the payments permission. You can see the register but not change it.
          </p>
        ) : null}

        {isError ? (
          <ErrorState error={error} onRetry={() => void refetch()} />
        ) : isLoading ? (
          <TableSkeleton rows={2} columns={3} />
        ) : records.length === 0 ? (
          <EmptyState
            title={section.empty}
            description={canWrite ? `Use “Add ${section.singular}” to record one.` : undefined}
            className="py-8"
          />
        ) : (
          <ul className="space-y-3">
            {records.map((record) => (
              <li key={record.id} className="rounded-lg border border-hairline p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div className="min-w-0">
                    {record.reference ? <p className="font-mono text-xs text-ink-500">{record.reference}</p> : null}
                    <p className="mt-0.5 break-words font-medium text-ink-900">{title(record)}</p>
                  </div>
                  <div className="flex flex-wrap items-center gap-2">
                    {section.statusField && record[section.statusField] ? (
                      <StatusBadge
                        status={String(record[section.statusField])}
                        label={labelFor(record, section.statusField) ?? undefined}
                        size="sm"
                      />
                    ) : null}
                    {canWrite ? (
                      <>
                        <Button variant="ghost" size="sm" icon={<Pencil className="h-4 w-4" />} onClick={() => open(record)}>
                          Edit
                        </Button>
                        <Button variant="ghost" size="sm" icon={<Archive className="h-4 w-4" />} onClick={() => setArchiving(record)}>
                          Archive
                        </Button>
                      </>
                    ) : null}
                  </div>
                </div>

                <dl className="mt-3 grid gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
                  {section.summary.map((key) => {
                    const field = section.fields.find((candidate) => candidate.key === key) ?? section.derived.find((candidate) => candidate.key === key);
                    if (!field) return null;
                    return (
                      <div key={key} className="min-w-0">
                        <dt className="sasa-eyebrow">{field.label}</dt>
                        <dd className="mt-0.5 break-words text-sm text-ink-800">{displayField(record, field, currency)}</dd>
                      </div>
                    );
                  })}
                </dl>

                <details className="mt-3">
                  <summary className="cursor-pointer text-sm font-medium text-brand-700">All details</summary>
                  <dl className="mt-3 grid gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
                    {[...section.fields, ...section.derived].map((field) => (
                      <div key={field.key} className="min-w-0">
                        <dt className="sasa-eyebrow">{field.label}</dt>
                        <dd className="mt-0.5 whitespace-pre-line break-words text-sm text-ink-800">{displayField(record, field, currency)}</dd>
                      </div>
                    ))}
                  </dl>
                  {section.hasVisits ? <VisitList visits={initialVisits(record)} /> : null}
                </details>
              </li>
            ))}
          </ul>
        )}
      </CardBody>

      <Drawer
        open={editing !== null}
        onClose={() => setEditing(null)}
        title={editing === "new" ? `Add ${section.singular}` : `Edit ${section.singular}`}
        description={section.description}
        width="lg"
        footer={
          <>
            <Button variant="secondary" onClick={() => setEditing(null)}>
              Cancel
            </Button>
            <Button variant="primary" loading={busy} disabled={!sync.online} onClick={() => void submit()}>
              Save to the register
            </Button>
          </>
        }
      >
        <div className="space-y-5">
          {!sync.online ? <OfflineNotice /> : null}

          {section.derived.length > 0 ? (
            <div className="rounded-lg bg-surface-sunken p-3 text-sm text-ink-600">
              {section.derived.map((derived) => (
                <p key={derived.key}>
                  <span className="font-medium text-ink-800">{derived.label}</span> is calculated when you save: {derived.explain}
                </p>
              ))}
            </div>
          ) : null}

          <FieldRow>
            {section.fields.map((field) => (
              <div key={field.key} className={field.wide || field.kind === "textarea" ? "sm:col-span-2" : undefined}>
                <SectionInput
                  field={field}
                  value={values[field.key] ?? ""}
                  error={errors[field.key]}
                  options={field.options ? (lists[field.options] ?? []) : []}
                  withheld={editing !== null && editing !== "new" && editing[field.key] === undefined}
                  onChange={(value) => setValues((current) => ({ ...current, [field.key]: value }))}
                />
              </div>
            ))}
          </FieldRow>

          {section.hasVisits ? (
            <VisitEditor visits={visits} onChange={setVisits} error={errors.monitoring_visits} />
          ) : null}
        </div>
      </Drawer>

      <ConfirmDialog
        open={archiving !== null}
        onCancel={() => setArchiving(null)}
        onConfirm={() => void archive()}
        title={`Archive this ${section.singular}?`}
        description="It leaves the register and the totals, and stays in the audit trail. Nothing is deleted."
        confirmLabel="Archive"
        tone="danger"
        loading={busy}
      />
    </Card>
  );
}

function SectionInput({
  field,
  value,
  error,
  options,
  withheld,
  onChange,
}: {
  field: PapFieldDefinition;
  value: string | boolean;
  error?: string;
  options: PapOption[];
  withheld: boolean;
  onChange: (value: string | boolean) => void;
}) {
  const id = `pap-field-${field.key}`;
  const label = field.unit ? `${field.label} (${field.unit})` : field.kind === "money" ? `${field.label} (TZS)` : field.label;
  const hint = withheld ? "Hidden from you. Leave blank to keep what is recorded." : field.hint;

  if (field.kind === "boolean") {
    return <Checkbox checked={Boolean(value)} onChange={onChange} label={field.label} />;
  }

  return (
    <Field label={label} required={field.required} error={error} hint={hint} htmlFor={id}>
      {field.kind === "select" ? (
        <Select id={id} placeholder="Not recorded" value={String(value)} invalid={Boolean(error)} onChange={(event) => onChange(event.target.value)}>
          {options.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </Select>
      ) : field.kind === "textarea" ? (
        <Textarea id={id} rows={3} value={String(value)} invalid={Boolean(error)} onChange={(event) => onChange(event.target.value)} />
      ) : (
        <Input
          id={id}
          type={field.kind === "date" ? "date" : "text"}
          inputMode={field.kind === "number" || field.kind === "money" ? "decimal" : undefined}
          autoComplete="off"
          value={String(value)}
          invalid={Boolean(error)}
          onChange={(event) => onChange(event.target.value)}
        />
      )}
    </Field>
  );
}

function VisitList({ visits }: { visits: MonitoringVisit[] }) {
  if (visits.length === 0) return <p className="mt-3 text-sm text-ink-500">No monitoring visits yet.</p>;

  return (
    <div className="mt-4">
      <p className="sasa-eyebrow mb-2">Monitoring visits</p>
      <ol className="space-y-2">
        {visits.map((visit, index) => (
          <li key={`${visit.visit_date}-${index}`} className="rounded-md bg-surface-sunken p-3 text-sm">
            <p className="font-medium text-ink-800">
              Visit {index + 1} · {formatDate(visit.visit_date)}
              {visit.visited_by ? <span className="font-normal text-ink-600"> · {visit.visited_by}</span> : null}
            </p>
            {visit.findings ? <p className="mt-1 whitespace-pre-line text-ink-700">{visit.findings}</p> : null}
          </li>
        ))}
      </ol>
    </div>
  );
}

function VisitEditor({
  visits,
  onChange,
  error,
}: {
  visits: MonitoringVisit[];
  onChange: (visits: MonitoringVisit[]) => void;
  error?: string;
}) {
  const update = (index: number, patch: Partial<MonitoringVisit>) =>
    onChange(visits.map((visit, position) => (position === index ? { ...visit, ...patch } : visit)));

  return (
    <div>
      <div className="mb-2 flex items-center justify-between gap-3">
        <p className="text-sm font-medium text-ink-800">Monitoring visits</p>
        <Button
          variant="quiet"
          size="sm"
          icon={<CalendarPlus className="h-4 w-4" />}
          onClick={() => onChange([...visits, { visit_date: new Date().toISOString().slice(0, 10), visited_by: "", findings: "" }])}
        >
          Add a visit
        </Button>
      </div>
      {error ? <p className="mb-2 text-sm text-danger-600">{error}</p> : null}
      {visits.length === 0 ? <p className="text-sm text-ink-500">No visits recorded. Add as many as the programme needs.</p> : null}
      <ol className="space-y-3">
        {visits.map((visit, index) => (
          <li key={index} className="rounded-lg border border-hairline p-3">
            <div className="mb-2 flex items-center justify-between">
              <span className="text-sm font-medium text-ink-700">Visit {index + 1}</span>
              <Button
                variant="ghost"
                size="sm"
                icon={<Trash2 className="h-4 w-4" />}
                onClick={() => onChange(visits.filter((_, position) => position !== index))}
              >
                Remove
              </Button>
            </div>
            <FieldRow>
              <Field label="Date" htmlFor={`visit-date-${index}`} required>
                <Input id={`visit-date-${index}`} type="date" value={visit.visit_date} onChange={(event) => update(index, { visit_date: event.target.value })} />
              </Field>
              <Field label="Visited by" htmlFor={`visit-by-${index}`} optional>
                <Input id={`visit-by-${index}`} value={visit.visited_by ?? ""} onChange={(event) => update(index, { visited_by: event.target.value })} />
              </Field>
            </FieldRow>
            <div className="mt-3">
              <Field label="Findings" htmlFor={`visit-findings-${index}`} optional>
                <Textarea id={`visit-findings-${index}`} rows={2} value={visit.findings ?? ""} onChange={(event) => update(index, { findings: event.target.value })} />
              </Field>
            </div>
          </li>
        ))}
      </ol>
    </div>
  );
}

/** The PAP register is online-only. Say so plainly; never pretend it saved. */
export function OfflineNotice() {
  return (
    <div className="flex items-start gap-3 rounded-lg border border-warning-500/25 bg-warning-50 p-3 text-sm text-warning-800" role="status">
      <WifiOff className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
      <p>
        You are offline. The PAP register needs a connection to save, and <strong>nothing has been saved</strong>. Keep this
        open and save when the signal returns.
      </p>
    </div>
  );
}

function capitalise(value: string): string {
  return value.charAt(0).toUpperCase() + value.slice(1);
}
