"use client";

import {
  AlertTriangle,
  CheckCircle2,
  Download,
  Upload,
} from "lucide-react";
import { useState } from "react";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { PageHeader } from "@/components/ui/DetailLayout";
import { Field, Select } from "@/components/ui/Form";
import { PermissionDenied, Scanner } from "@/components/ui/States";
import { apiRequest, downloadFile } from "@/lib/api/client";
import { cn } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";
import { useToast } from "@/providers/ToastProvider";

interface TemplateColumn {
  key: string;
  label: string;
  required: boolean;
  help: string;
}

interface ImportJobState {
  id: number;
  entity: string;
  original_name: string;
  headers: string[];
  mapping: Record<string, string>;
  status: string;
  rows_total: number;
  rows_valid: number;
  rows_invalid: number;
  rows_committed: number;
  errors: Array<{ row: number; errors: string[]; values?: Record<string, string> }> | null;
  preview: Array<Record<string, string>> | null;
}

const ENTITIES = [
  { value: "stakeholders", label: "Stakeholders" },
  { value: "locations", label: "Locations (regions, districts, wards, villages)" },
  { value: "commitments", label: "Commitments" },
  { value: "grievances", label: "Historical grievances" },
];

const STEPS = ["Choose and download", "Upload", "Map the columns", "Check", "Import"];

export default function ImportWizardPage() {
  const { can } = useSession();
  const toast = useToast();

  const [entity, setEntity] = useState("stakeholders");
  const [template, setTemplate] = useState<TemplateColumn[]>([]);
  const [job, setJob] = useState<ImportJobState | null>(null);
  const [mapping, setMapping] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [step, setStep] = useState(0);

  if (!can("import.run")) return <PermissionDenied what="importing data" />;

  const loadTemplate = async () => {
    const response = await apiRequest<{ data: { columns: TemplateColumn[] } }>(`/imports/template/${entity}`, {
      query: { format: "json" },
    });
    setTemplate(response.data.columns);
  };

  const upload = async (file: File) => {
    setBusy(true);
    try {
      const form = new FormData();
      form.append("entity", entity);
      form.append("file", file);

      const response = await apiRequest<{ data: { job: ImportJobState; template: { columns: TemplateColumn[] } } }>(
        "/imports",
        { method: "POST", formData: form },
      );

      setJob(response.data.job);
      setTemplate(response.data.template.columns);
      setMapping(response.data.job.mapping ?? {});
      setStep(2);
      toast.success(`${response.data.job.rows_total} rows read`, "Nothing has been written yet.");
    } catch (error) {
      toast.error("We could not read that file", error instanceof Error ? error.message : undefined);
    } finally {
      setBusy(false);
    }
  };

  const validate = async () => {
    if (!job) return;
    setBusy(true);
    try {
      const response = await apiRequest<{ data: ImportJobState; meta?: { message?: string } }>(
        `/imports/${job.id}/validate`,
        { method: "POST", body: { mapping } },
      );
      setJob(response.data);
      setStep(3);
    } catch (error) {
      toast.error("We could not check that file", error instanceof Error ? error.message : undefined);
    } finally {
      setBusy(false);
    }
  };

  const commit = async () => {
    if (!job) return;
    setBusy(true);
    try {
      const response = await apiRequest<{ data: ImportJobState; meta?: { message?: string } }>(
        `/imports/${job.id}/commit`,
        { method: "POST" },
      );
      setJob(response.data);
      setStep(4);
      toast.success("Import complete", `${response.data.rows_committed} records created.`);
    } catch (error) {
      toast.error("The import did not finish", error instanceof Error ? error.message : undefined);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="mx-auto max-w-4xl">
      <PageHeader
        backHref="/configuration"
        eyebrow="Administration"
        title="Import data"
        description="Download a template, upload your file, map the columns, check what will happen — and only then commit. Production data is never partly written."
      />

      {/* --------------------------- stepper --------------------------- */}
      <ol className="mb-6 flex flex-wrap gap-2">
        {STEPS.map((label, index) => (
          <li
            key={label}
            className={cn(
              "flex items-center gap-2 rounded-full px-3 py-1.5 text-sm",
              index < step
                ? "bg-success-50 text-success-700"
                : index === step
                  ? "bg-primary text-on-primary"
                  : "bg-ink-100 text-ink-500",
            )}
          >
            <span className="tabular flex h-5 w-5 items-center justify-center rounded-full bg-white/20 text-xs font-semibold">
              {index < step ? "✓" : index + 1}
            </span>
            {label}
          </li>
        ))}
      </ol>

      <div className="space-y-4">
        {/* ------------------- step 1: choose & download ---------------- */}
        <Card>
          <CardHeader title="1. Choose what you are importing" description="Then download a template with the exact columns SASA expects." />
          <CardBody className="space-y-4">
            <Field label="Record type" htmlFor="entity">
              <Select
                id="entity"
                value={entity}
                onChange={(event) => {
                  setEntity(event.target.value);
                  setJob(null);
                  setStep(0);
                }}
              >
                {ENTITIES.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </Select>
            </Field>

            <div className="flex flex-wrap gap-2">
              <Button
                variant="secondary"
                icon={<Download className="h-4 w-4" />}
                onClick={async () => {
                  try {
                    await downloadFile(`/imports/template/${entity}`, `sasa-${entity}-template.xlsx`);
                    setStep(Math.max(step, 1));
                  } catch {
                    toast.error("We could not prepare that template");
                  }
                }}
              >
                Download the template
              </Button>
              <Button variant="ghost" onClick={() => void loadTemplate()}>
                See the expected columns
              </Button>
            </div>

            {template.length > 0 ? (
              <div className="sasa-scroll-x overflow-x-auto rounded-lg border border-hairline">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-hairline bg-surface-sunken">
                      <th className="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-ink-500">Column</th>
                      <th className="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-ink-500">Required</th>
                      <th className="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-ink-500">Notes</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-hairline">
                    {template.map((column) => (
                      <tr key={column.key}>
                        <td className="px-4 py-2.5 font-medium text-ink-800">{column.label}</td>
                        <td className="px-4 py-2.5">
                          {column.required ? (
                            <span className="rounded-full bg-danger-50 px-2 py-0.5 text-xs text-danger-700">Required</span>
                          ) : (
                            <span className="text-xs text-ink-500">Optional</span>
                          )}
                        </td>
                        <td className="px-4 py-2.5 text-ink-600">{column.help}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : null}
          </CardBody>
        </Card>

        {/* ------------------------ step 2: upload ---------------------- */}
        <Card>
          <CardHeader title="2. Upload your file" description="Excel or CSV. Nothing is written to the database at this stage." />
          <CardBody>
            <label
              className={cn(
                "flex cursor-pointer flex-col items-center justify-center gap-3 rounded-xl border-2 border-dashed border-ink-300 px-6 py-10 text-center transition hover:border-brand-400 hover:bg-brand-50/40",
                busy && "pointer-events-none opacity-60",
              )}
            >
              <input
                type="file"
                accept=".csv,.xlsx,.xls"
                className="sr-only"
                onChange={(event) => {
                  const file = event.target.files?.[0];
                  if (file) void upload(file);
                }}
              />
              {busy ? (
                <Scanner label="Reading the file" size={34} />
              ) : (
                <Upload className="h-8 w-8 text-ink-400" aria-hidden />
              )}
              <span>
                <span className="block font-medium text-ink-900">
                  {job ? job.original_name : "Choose a file, or drop it here"}
                </span>
                <span className="mt-1 block text-sm text-ink-500">
                  {job ? `${job.rows_total} rows read` : "Excel (.xlsx) or CSV, up to 20 MB"}
                </span>
              </span>
            </label>
          </CardBody>
        </Card>

        {/* --------------------- step 3: map columns ------------------- */}
        {job ? (
          <Card>
            <CardHeader
              title="3. Map the columns"
              description="We have guessed from your headings. Check each one — anything left blank is simply not imported."
            />
            <CardBody className="space-y-3">
              {template.map((column) => (
                <div key={column.key} className="grid items-center gap-3 sm:grid-cols-[1fr_auto_1fr]">
                  <div>
                    <p className="text-sm font-medium text-ink-800">
                      {column.label}
                      {column.required ? <span className="ml-1 text-danger-500">*</span> : null}
                    </p>
                    <p className="text-xs text-ink-500">{column.help}</p>
                  </div>
                  <span className="hidden text-ink-400 sm:block">←</span>
                  <select
                    value={mapping[column.key] ?? ""}
                    onChange={(event) => setMapping((current) => ({ ...current, [column.key]: event.target.value }))}
                    className="sasa-field"
                    aria-label={`Column for ${column.label}`}
                  >
                    <option value="">Not in my file</option>
                    {job.headers.map((header) => (
                      <option key={header} value={header}>
                        {header}
                      </option>
                    ))}
                  </select>
                </div>
              ))}

              <Button variant="primary" loading={busy} onClick={() => void validate()} className="mt-2">
                Check the file
              </Button>
            </CardBody>
          </Card>
        ) : null}

        {/* ------------------------ step 4: review --------------------- */}
        {job && job.status !== "uploaded" ? (
          <Card>
            <CardHeader title="4. What will happen" description="Still nothing has been written." />
            <CardBody className="space-y-4">
              <div className="grid gap-3 sm:grid-cols-3">
                <div className="rounded-lg bg-surface-sunken p-4">
                  <p className="sasa-eyebrow">Rows in the file</p>
                  <p className="tabular mt-1 text-2xl font-semibold text-ink-900">{job.rows_total}</p>
                </div>
                <div className="rounded-lg bg-success-50 p-4">
                  <p className="sasa-eyebrow text-success-700">Ready to import</p>
                  <p className="tabular mt-1 text-2xl font-semibold text-success-700">{job.rows_valid}</p>
                </div>
                <div className={cn("rounded-lg p-4", job.rows_invalid > 0 ? "bg-danger-50" : "bg-surface-sunken")}>
                  <p className={cn("sasa-eyebrow", job.rows_invalid > 0 && "text-danger-700")}>Need attention</p>
                  <p className={cn("tabular mt-1 text-2xl font-semibold", job.rows_invalid > 0 ? "text-danger-700" : "text-ink-900")}>
                    {job.rows_invalid}
                  </p>
                </div>
              </div>

              {(job.errors ?? []).length > 0 ? (
                <div className="rounded-lg border border-danger-500/25 bg-danger-50 p-4">
                  <p className="flex items-center gap-2 font-medium text-danger-800">
                    <AlertTriangle className="h-4 w-4" aria-hidden />
                    Rows that cannot be imported
                  </p>
                  <p className="mt-1 text-sm text-danger-700">
                    Fix these in your spreadsheet and upload again, or import the valid rows now and add these
                    afterwards — they are skipped, never guessed at.
                  </p>
                  <ul className="mt-3 max-h-56 space-y-1.5 overflow-y-auto text-sm">
                    {(job.errors ?? []).slice(0, 40).map((error) => (
                      <li key={error.row} className="text-danger-800">
                        <strong>Row {error.row}:</strong> {error.errors.join("; ")}
                      </li>
                    ))}
                  </ul>
                </div>
              ) : null}

              {(job.preview ?? []).length > 0 ? (
                <div className="sasa-scroll-x overflow-x-auto rounded-lg border border-hairline">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="border-b border-hairline bg-surface-sunken">
                        {Object.keys(job.preview![0]).map((key) => (
                          <th key={key} className="whitespace-nowrap px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-ink-500">
                            {key.replace(/_/g, " ")}
                          </th>
                        ))}
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-hairline">
                      {(job.preview ?? []).map((row, index) => (
                        <tr key={index}>
                          {Object.values(row).map((value, cellIndex) => (
                            <td key={cellIndex} className="whitespace-nowrap px-3 py-2 text-ink-700">
                              {String(value ?? "—")}
                            </td>
                          ))}
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : null}

              {job.status !== "committed" ? (
                <Button variant="accent" size="lg" loading={busy} onClick={() => void commit()} disabled={job.rows_valid === 0}>
                  Import {job.rows_valid} {job.rows_valid === 1 ? "record" : "records"}
                </Button>
              ) : (
                <div className="flex items-start gap-3 rounded-lg bg-success-50 p-4">
                  <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0 text-success-600" aria-hidden />
                  <div>
                    <p className="font-medium text-success-800">{job.rows_committed} records imported</p>
                    <p className="mt-0.5 text-sm text-success-700">
                      Each one went through the same rules as a record typed in by hand, and the import is in
                      the audit trail.
                    </p>
                  </div>
                </div>
              )}
            </CardBody>
          </Card>
        ) : null}
      </div>
    </div>
  );
}
