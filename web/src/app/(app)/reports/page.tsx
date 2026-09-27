"use client";

import { useQueryClient } from "@tanstack/react-query";
import { Download, FileSpreadsheet, FileText, FileType, Sparkles } from "lucide-react";
import { useState } from "react";
import { PeriodPicker, presetPeriods, type Period } from "@/components/app/PeriodPicker";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { PageHeader } from "@/components/ui/DetailLayout";
import { Field, Select } from "@/components/ui/Form";
import { StatusBadge } from "@/components/ui/StatusBadge";
import { EmptyState, PermissionDenied, Scanner, TableSkeleton } from "@/components/ui/States";
import { apiRequest, downloadFile } from "@/lib/api/client";
import { useMetricDefinitions, useReportTemplates, useReports } from "@/lib/api/hooks";
import { cn, formatDateTime, humanise } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";
import { useToast } from "@/providers/ToastProvider";

const FORMAT_META: Record<string, { label: string; icon: typeof FileText; hint: string }> = {
  pdf: { label: "PDF", icon: FileText, hint: "Presentation-ready, branded" },
  docx: { label: "Word", icon: FileType, hint: "Editable narrative with tables" },
  xlsx: { label: "Excel", icon: FileSpreadsheet, hint: "Raw data, one sheet per section" },
  csv: { label: "CSV", icon: FileSpreadsheet, hint: "Plain data for another system" },
};

export default function ReportsPage() {
  const { can } = useSession();
  const toast = useToast();
  const queryClient = useQueryClient();

  const [period, setPeriod] = useState<Period>(() => presetPeriods()[2]);
  const [template, setTemplate] = useState("executive_summary");
  const [format, setFormat] = useState("pdf");
  const [generating, setGenerating] = useState(false);

  const { data: templates } = useReportTemplates();
  const { data: reports, isLoading } = useReports({ per_page: 20 });
  const { data: definitions } = useMetricDefinitions();

  if (!can("report.generate")) return <PermissionDenied what="reporting" />;

  const generate = async () => {
    setGenerating(true);
    try {
      await apiRequest("/reports", {
        method: "POST",
        body: { template, format, from: period.from, to: period.to },
      });
      await queryClient.invalidateQueries({ queryKey: ["reports"] });
      toast.success(
        "Report generated",
        "It was built from the records that exist right now, and is kept with its parameters so the numbers can always be explained.",
      );
    } catch (error) {
      toast.error("We could not build that report", error instanceof Error ? error.message : undefined);
    } finally {
      setGenerating(false);
    }
  };

  return (
    <div>
      <PageHeader
        eyebrow="Module 4 · Dashboards, analytics and reporting"
        title="Reports"
        description="Generated from live records — there is no second reporting database to reconcile. The same definition run twice a week apart legitimately produces different numbers, and both are traceable."
      />

      <div className="grid gap-4 lg:grid-cols-[1fr_1.3fr]">
        <Card>
          <CardHeader title="Generate a report" description="Choose what, over what period, and in which format." />
          <CardBody className="space-y-5">
            <Field label="Report" htmlFor="template">
              <Select id="template" value={template} onChange={(event) => setTemplate(event.target.value)}>
                {(templates?.data ?? []).map((entry) => (
                  <option key={entry.key} value={entry.key}>
                    {entry.name}
                  </option>
                ))}
              </Select>
            </Field>

            <Field label="Reporting period" htmlFor="period">
              <PeriodPicker period={period} onChange={setPeriod} className="w-full" />
            </Field>

            <Field label="Format">
              <div className="grid grid-cols-2 gap-2">
                {Object.entries(FORMAT_META).map(([key, meta]) => {
                  const Icon = meta.icon;
                  const active = format === key;

                  return (
                    <button
                      key={key}
                      type="button"
                      onClick={() => setFormat(key)}
                      className={cn(
                        "flex items-start gap-2.5 rounded-lg border p-3 text-left transition",
                        active ? "border-brand-600 bg-brand-50 ring-2 ring-brand-500/20" : "border-ink-300 hover:border-ink-400",
                      )}
                    >
                      <Icon className={cn("mt-0.5 h-4 w-4 shrink-0", active ? "text-brand-700" : "text-ink-500")} aria-hidden />
                      <span>
                        <span className="block text-sm font-medium text-ink-900">{meta.label}</span>
                        <span className="mt-0.5 block text-xs text-ink-500">{meta.hint}</span>
                      </span>
                    </button>
                  );
                })}
              </div>
            </Field>

            <Button variant="accent" fullWidth size="lg" loading={generating} onClick={() => void generate()}>
              {generating ? "Building the report" : "Generate"}
            </Button>

            <p className="rounded-lg bg-surface-sunken p-3 text-sm text-ink-600">
              Every report carries the project, the period, the filters, who generated it and when — plus the
              definition of each number in its footer.
            </p>
          </CardBody>
        </Card>

        <Card>
          <CardHeader
            title="Generated reports"
            description="Kept with their parameters, so a number in a past report can always be explained."
          />
          <CardBody className="p-0 sm:p-0">
            {isLoading ? (
              <TableSkeleton rows={5} columns={3} />
            ) : (reports?.data.data ?? []).length === 0 ? (
              <EmptyState
                icon={FileText}
                title="No reports yet"
                description="Generate one on the left. It is produced from the records that exist at that moment, not from a stored copy."
              />
            ) : (
              <ul className="divide-y divide-hairline">
                {(reports?.data.data ?? []).map((report) => {
                  const meta = FORMAT_META[report.format] ?? FORMAT_META.pdf;
                  const Icon = meta.icon;

                  return (
                    <li key={report.id} className="flex flex-wrap items-center gap-3 px-5 py-3.5">
                      <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700">
                        <Icon className="h-4 w-4" aria-hidden />
                      </span>
                      <div className="min-w-0 flex-1">
                        <p className="truncate font-medium text-ink-900">{report.name}</p>
                        <p className="mt-0.5 text-xs text-ink-500">
                          {report.reference} · {formatDateTime(report.generated_at ?? report.created_at)}
                          {report.generator ? ` · ${report.generator.name}` : ""}
                        </p>
                      </div>
                      <StatusBadge status={report.status === "ready" ? "synced" : report.status} size="sm" />
                      {report.status === "ready" ? (
                        <Button
                          size="sm"
                          variant="secondary"
                          icon={<Download className="h-3.5 w-3.5" />}
                          onClick={async () => {
                            try {
                              await downloadFile(`/reports/${report.id}/download`, `${report.reference}.${report.format}`);
                            } catch {
                              toast.error("We could not download that report");
                            }
                          }}
                        >
                          Download
                        </Button>
                      ) : report.status === "generating" ? (
                        <Scanner label="Generating" size={18} />
                      ) : null}
                    </li>
                  );
                })}
              </ul>
            )}
          </CardBody>
        </Card>
      </div>

      <Card className="mt-6">
        <CardHeader
          title="How every number is defined"
          description="One definition per metric, so two reports never disagree. These definitions appear in the footer of every export."
          action={
            <span className="inline-flex items-center gap-1.5 rounded-full bg-brand-50 px-3 py-1.5 text-xs font-medium text-brand-800">
              <Sparkles className="h-3.5 w-3.5" aria-hidden />
              {(definitions?.data ?? []).length} metrics
            </span>
          }
        />
        <CardBody>
          <dl className="grid gap-x-8 gap-y-5 sm:grid-cols-2">
            {(definitions?.data ?? []).map((definition) => (
              <div key={definition.key}>
                <dt className="text-sm font-semibold text-ink-900">
                  {definition.label}
                  <span className="ml-2 rounded bg-ink-100 px-1.5 py-0.5 text-[0.625rem] font-normal uppercase tracking-wide text-ink-500">
                    {humanise(definition.unit)}
                  </span>
                </dt>
                <dd className="mt-1 text-sm leading-relaxed text-ink-600">{definition.definition}</dd>
              </div>
            ))}
          </dl>
        </CardBody>
      </Card>
    </div>
  );
}
