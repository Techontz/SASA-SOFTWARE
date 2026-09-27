"use client";

import { useQueryClient } from "@tanstack/react-query";
import {
  AlertTriangle,
  Bell,
  CalendarDays,
  Check,
  Import,
  Layers,
  Lock,
  Save,
  ShieldCheck,
  Timer,
  Users,
} from "lucide-react";
import Link from "next/link";
import { useEffect, useState } from "react";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { PageHeader, Tabs } from "@/components/ui/DetailLayout";
import { Field, FieldRow, Input } from "@/components/ui/Form";
import { StatusBadge } from "@/components/ui/StatusBadge";
import { LoadingState, PermissionDenied } from "@/components/ui/States";
import { apiRequest } from "@/lib/api/client";
import { useConfiguration, useGrievanceCategories, usePriorityModel } from "@/lib/api/hooks";
import { cn, humanise } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";
import { useToast } from "@/providers/ToastProvider";

interface SlaPolicyRow {
  id: number;
  clock: string;
  unit: string;
  target_value: number;
  severity: number | null;
  category: { id: number; name: string } | null;
  reminder_thresholds: number[] | null;
  is_active: boolean;
}

interface CalendarRow {
  id: number;
  name: string;
  timezone: string;
  working_days: number[];
  work_start: string;
  work_end: string;
  holidays: Array<{ id: number; date: string; name: string; recurs_annually: boolean }>;
}

interface NotificationRuleRow {
  event_key: string;
  name: string;
  channels: string[];
  recipient_roles: string[] | null;
  configured: boolean;
  rule: { is_active: boolean; template_subject: string | null } | null;
}

export default function ConfigurationPage() {
  const { can } = useSession();

  if (!can("configuration.view")) return <PermissionDenied what="configuration" />;

  return (
    <div>
      <PageHeader
        eyebrow="Administration"
        title="Configuration"
        description="Anything that differs between projects lives here, not in the code. Every change is recorded in the audit trail, because configuration is powerful enough to break comparability between projects."
      />

      <Tabs
        tabs={[
          { key: "priority", label: "Priority", content: <PrioritySettings /> },
          { key: "categories", label: "Categories", content: <CategorySettings /> },
          { key: "sla", label: "SLA standards", content: <SlaSettings /> },
          { key: "calendar", label: "Working calendar", content: <CalendarSettings /> },
          { key: "notifications", label: "Notifications", content: <NotificationSettings /> },
          { key: "lists", label: "Lists and fields", content: <ListSettings /> },
          { key: "import", label: "Import data", content: <ImportPanel /> },
        ]}
      />
    </div>
  );
}

/* -------------------------------------------------------------- priority */

function PrioritySettings() {
  const { can } = useSession();
  const toast = useToast();
  const queryClient = useQueryClient();
  const { data, isLoading } = usePriorityModel();

  /*
   * The saved values are the source of truth until somebody edits them, so the
   * form is derived rather than copied into state by an effect — an effect
   * would render once with the wrong numbers before correcting itself.
   */
  const [edited, setEdited] = useState<{ weights: Record<string, number>; thresholds: { high: number; medium: number } } | null>(null);
  const [saving, setSaving] = useState(false);

  if (isLoading) return <LoadingState />;

  const weights = edited?.weights ?? data?.data.settings.weights ?? { influence: 1, interest: 1, power: 1, impact: 1 };
  const thresholds = edited?.thresholds ?? data?.data.settings.thresholds ?? { high: 10, medium: 7 };

  const setWeights = (next: Record<string, number>) => setEdited({ weights: next, thresholds });
  const setThresholds = (next: { high: number; medium: number }) => setEdited({ weights, thresholds: next });

  const save = async () => {
    setSaving(true);
    try {
      await apiRequest("/configuration", {
        method: "PUT",
        body: { key: "priority", value: { weights, thresholds } },
      });
      await queryClient.invalidateQueries({ queryKey: ["priority-model"] });
      toast.success("Priority weighting saved", "New and recalculated assessments will use these numbers.");
    } catch (error) {
      toast.error("We could not save that", error instanceof Error ? error.message : undefined);
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="grid gap-4 lg:grid-cols-[1.3fr_1fr]">
      <Card>
        <CardHeader
          title="How stakeholder priority is scored"
          description="score = influence + interest + power + impact, each multiplied by its weight. A weight of 0 removes that dimension entirely."
        />
        <CardBody className="space-y-5">
          <div className="grid gap-4 sm:grid-cols-2">
            {(["influence", "interest", "power", "impact"] as const).map((dimension) => (
              <Field key={dimension} label={humanise(dimension)} htmlFor={`weight-${dimension}`} hint="0 switches this dimension off.">
                <Input
                  id={`weight-${dimension}`}
                  type="number"
                  min={0}
                  max={10}
                  value={weights[dimension] ?? 1}
                  disabled={!can("configuration.manage")}
                  onChange={(event) => setWeights({ ...weights, [dimension]: Number(event.target.value) })}
                />
              </Field>
            ))}
          </div>

          <FieldRow>
            <Field label="High from" htmlFor="threshold-high">
              <Input
                id="threshold-high"
                type="number"
                min={1}
                value={thresholds.high}
                disabled={!can("configuration.manage")}
                onChange={(event) => setThresholds({ ...thresholds, high: Number(event.target.value) })}
              />
            </Field>
            <Field label="Medium from" htmlFor="threshold-medium">
              <Input
                id="threshold-medium"
                type="number"
                min={1}
                value={thresholds.medium}
                disabled={!can("configuration.manage")}
                onChange={(event) => setThresholds({ ...thresholds, medium: Number(event.target.value) })}
              />
            </Field>
          </FieldRow>

          {can("configuration.manage") ? (
            <Button variant="primary" loading={saving} icon={<Save className="h-4 w-4" />} onClick={() => void save()}>
              Save the weighting
            </Button>
          ) : null}
        </CardBody>
      </Card>

      <Card>
        <CardHeader title="Why this is configuration" />
        <CardBody className="space-y-4 text-sm leading-relaxed text-ink-700">
          <p className="flex items-start gap-2.5 rounded-lg bg-warning-50 p-3.5 text-warning-800">
            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
            <span>
              The stakeholder matrix in the source specification was internally inconsistent — identical inputs
              mapped to different strategies, and &ldquo;impact&rdquo; appeared in the register but not in the
              matrix. SASA does not silently correct it.
            </span>
          </p>
          <p>
            Instead the calculation is a documented default and the numbers are yours to set. Whatever this
            project agrees, it can be applied here without waiting for a release.
          </p>
          <p className="rounded-lg bg-surface-sunken p-3.5">
            <strong className="text-ink-900">Current formula.</strong> {data?.data.formula}
          </p>
          <p>{data?.data.note}</p>
        </CardBody>
      </Card>
    </div>
  );
}

/* ------------------------------------------------------------ categories */

function CategorySettings() {
  const { can } = useSession();
  const toast = useToast();
  const queryClient = useQueryClient();
  const { data, isLoading } = useGrievanceCategories();

  if (isLoading) return <LoadingState />;

  const retire = async (id: number) => {
    try {
      const response = await apiRequest<{ message?: string }>(`/configuration/categories/${id}/retire`, { method: "POST" });
      await queryClient.invalidateQueries({ queryKey: ["grievance-categories"] });
      toast.success("Category updated", response.message);
    } catch (error) {
      toast.error("We could not change that", error instanceof Error ? error.message : undefined);
    }
  };

  return (
    <Card>
      <CardHeader
        title="Grievance categories and subcategories"
        description="Retiring a category hides it from new cases and keeps it on the historical ones, so old reports stay correct."
      />
      <CardBody className="space-y-4">
        {(data?.data ?? []).map((category) => (
          <div key={category.id} className="rounded-lg border border-hairline">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-hairline bg-surface-sunken px-4 py-3">
              <div className="flex flex-wrap items-center gap-2.5">
                <span className="font-medium text-ink-900">{category.name}</span>
                {category.is_restricted ? (
                  <span className="inline-flex items-center gap-1.5 rounded-full bg-danger-50 px-2 py-0.5 text-xs font-medium text-danger-700">
                    <Lock className="h-3 w-3" aria-hidden />
                    Restricted
                  </span>
                ) : null}
                {category.default_severity ? (
                  <span className="rounded-full bg-ink-100 px-2 py-0.5 text-xs text-ink-600">
                    defaults to Level {category.default_severity}
                  </span>
                ) : null}
              </div>
              {can("configuration.manage") ? (
                <Button size="sm" variant="ghost" onClick={() => void retire(category.id)}>
                  Retire
                </Button>
              ) : null}
            </div>
            <div className="flex flex-wrap gap-2 p-4">
              {(category.subcategories ?? []).map((subcategory) => (
                <span key={subcategory.id} className="rounded-full border border-hairline px-2.5 py-1 text-sm text-ink-700">
                  {subcategory.name}
                </span>
              ))}
              {(category.subcategories ?? []).length === 0 ? (
                <span className="text-sm text-ink-500">No subcategories.</span>
              ) : null}
            </div>
            {category.is_restricted ? (
              <p className="flex items-start gap-2 border-t border-hairline bg-danger-50 px-4 py-2.5 text-xs text-danger-800">
                <ShieldCheck className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden />
                Cases in this category are visible only to the named handling group, excluded from general
                exports, and reported in aggregate only.
              </p>
            ) : null}
          </div>
        ))}
      </CardBody>
    </Card>
  );
}

/* -------------------------------------------------------------------- SLA */

function SlaSettings() {
  const { can } = useSession();
  const toast = useToast();
  const queryClient = useQueryClient();
  const [policies, setPolicies] = useState<SlaPolicyRow[]>([]);
  const [loading, setLoading] = useState(true);

  const load = async () => {
    try {
      const response = await apiRequest<{ data: SlaPolicyRow[] }>("/configuration/sla-policies");
      setPolicies(response.data);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void load();
  }, []);

  if (loading) return <LoadingState />;

  const update = async (policy: SlaPolicyRow, targetValue: number) => {
    try {
      await apiRequest("/configuration/sla-policies", {
        method: "POST",
        body: {
          clock: policy.clock,
          unit: policy.unit,
          target_value: targetValue,
          severity: policy.severity,
          category_id: policy.category?.id ?? null,
          reminder_thresholds: policy.reminder_thresholds,
        },
      });
      await queryClient.invalidateQueries({ queryKey: ["grievances"] });
      toast.success("SLA standard saved", "Clocks started from now on use the new target. Clocks already running keep theirs.");
      void load();
    } catch (error) {
      toast.error("We could not save that", error instanceof Error ? error.message : undefined);
    }
  };

  return (
    <Card>
      <CardHeader
        title="Business standards"
        description={`Nothing is hard-coded to "7 working days". Set the numbers your project has agreed, per clock, per severity and per category.`}
        action={
          <span className="inline-flex items-center gap-1.5 rounded-full bg-brand-50 px-3 py-1.5 text-xs font-medium text-brand-800">
            <Timer className="h-3.5 w-3.5" aria-hidden />
            Most specific rule wins
          </span>
        }
      />
      <CardBody className="p-0 sm:p-0">
        <div className="sasa-scroll-x overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-hairline bg-surface-sunken">
                {["Clock", "Applies to", "Target", "Reminders at", ""].map((heading) => (
                  <th key={heading} className="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-500">
                    {heading}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-hairline">
              {policies.map((policy) => (
                <tr key={policy.id}>
                  <td className="px-5 py-3 font-medium text-ink-900">{humanise(policy.clock)}</td>
                  <td className="px-5 py-3 text-ink-700">
                    {policy.category?.name ?? (policy.severity ? `Level ${policy.severity} cases` : "All cases")}
                  </td>
                  <td className="px-5 py-3">
                    <div className="flex items-center gap-2">
                      <input
                        type="number"
                        min={1}
                        defaultValue={policy.target_value}
                        disabled={!can("configuration.manage")}
                        onBlur={(event) => {
                          const value = Number(event.target.value);
                          if (value !== policy.target_value) void update(policy, value);
                        }}
                        className="sasa-field tabular w-20 px-2 py-1.5 text-sm"
                      />
                      <span className="text-ink-600">{humanise(policy.unit)}</span>
                    </div>
                  </td>
                  <td className="px-5 py-3 text-ink-600">
                    {(policy.reminder_thresholds ?? []).map((threshold) => `${threshold}%`).join(", ") || "—"}
                  </td>
                  <td className="px-5 py-3">
                    <StatusBadge status={policy.is_active ? "active" : "inactive"} size="sm" />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </CardBody>
    </Card>
  );
}

/* --------------------------------------------------------------- calendar */

function CalendarSettings() {
  const { can } = useSession();
  const toast = useToast();
  const [calendars, setCalendars] = useState<CalendarRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [holiday, setHoliday] = useState({ date: "", name: "" });

  const load = async () => {
    try {
      const response = await apiRequest<{ data: CalendarRow[] }>("/configuration/calendars");
      setCalendars(response.data);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void load();
  }, []);

  if (loading) return <LoadingState />;

  const calendar = calendars[0];
  if (!calendar) return <p className="text-sm text-ink-500">No working calendar has been set up for this project.</p>;

  const toggleDay = async (day: number) => {
    const workingDays = calendar.working_days.includes(day)
      ? calendar.working_days.filter((entry) => entry !== day)
      : [...calendar.working_days, day].sort();

    try {
      await apiRequest(`/configuration/calendars/${calendar.id}`, { method: "PATCH", body: { working_days: workingDays } });
      toast.success("Working week updated", "Every SLA deadline is recalculated against it from now on.");
      void load();
    } catch (error) {
      toast.error("We could not save that", error instanceof Error ? error.message : undefined);
    }
  };

  const addHoliday = async () => {
    if (!holiday.date || !holiday.name) return;
    try {
      await apiRequest(`/configuration/calendars/${calendar.id}/holidays`, {
        method: "POST",
        body: { ...holiday, recurs_annually: true },
      });
      toast.success("Public holiday added");
      setHoliday({ date: "", name: "" });
      void load();
    } catch (error) {
      toast.error("We could not add that", error instanceof Error ? error.message : undefined);
    }
  };

  const DAYS = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];

  return (
    <div className="grid gap-4 lg:grid-cols-[1fr_1fr]">
      <Card>
        <CardHeader
          title="The working week"
          description={`"Seven working days" means nothing without this. Every SLA deadline is counted against it.`}
        />
        <CardBody className="space-y-5">
          <div>
            <p className="sasa-eyebrow mb-2">Working days</p>
            <div className="flex flex-wrap gap-2">
              {DAYS.map((label, index) => {
                const day = index + 1;
                const active = calendar.working_days.includes(day);

                return (
                  <button
                    key={label}
                    type="button"
                    disabled={!can("configuration.manage")}
                    onClick={() => void toggleDay(day)}
                    className={cn(
                      "h-11 w-14 rounded-lg border text-sm font-medium transition",
                      active ? "border-primary bg-primary text-on-primary" : "border-ink-300 text-ink-600 hover:border-ink-400",
                    )}
                  >
                    {label}
                  </button>
                );
              })}
            </div>
          </div>

          <FieldRow>
            <Field label="Work starts" htmlFor="work-start">
              <Input id="work-start" type="time" defaultValue={calendar.work_start.slice(0, 5)} disabled={!can("configuration.manage")} />
            </Field>
            <Field label="Work ends" htmlFor="work-end">
              <Input id="work-end" type="time" defaultValue={calendar.work_end.slice(0, 5)} disabled={!can("configuration.manage")} />
            </Field>
          </FieldRow>

          <p className="rounded-lg bg-surface-sunken p-3 text-sm text-ink-600">
            Timezone: <strong className="text-ink-800">{calendar.timezone}</strong>
          </p>
        </CardBody>
      </Card>

      <Card>
        <CardHeader title="Public holidays" description="Days the clock does not run." />
        <CardBody className="space-y-4">
          {can("configuration.manage") ? (
            <div className="flex flex-wrap gap-2">
              <input
                type="date"
                value={holiday.date}
                onChange={(event) => setHoliday((current) => ({ ...current, date: event.target.value }))}
                className="sasa-field flex-1"
                aria-label="Holiday date"
              />
              <input
                type="text"
                value={holiday.name}
                onChange={(event) => setHoliday((current) => ({ ...current, name: event.target.value }))}
                placeholder="Holiday name"
                className="sasa-field flex-1"
                aria-label="Holiday name"
              />
              <Button variant="secondary" icon={<Check className="h-4 w-4" />} onClick={() => void addHoliday()}>
                Add
              </Button>
            </div>
          ) : null}

          <ul className="divide-y divide-hairline">
            {calendar.holidays.length === 0 ? (
              <li className="py-6 text-center text-sm text-ink-500">No holidays recorded.</li>
            ) : (
              calendar.holidays.map((entry) => (
                <li key={entry.id} className="flex items-center justify-between gap-3 py-2.5">
                  <span className="flex items-center gap-2.5">
                    <CalendarDays className="h-4 w-4 shrink-0 text-ink-400" aria-hidden />
                    <span className="text-sm text-ink-800">{entry.name}</span>
                  </span>
                  <span className="text-sm text-ink-500">
                    {entry.date}
                    {entry.recurs_annually ? " · every year" : ""}
                  </span>
                </li>
              ))
            )}
          </ul>
        </CardBody>
      </Card>
    </div>
  );
}

/* ---------------------------------------------------------- notifications */

function NotificationSettings() {
  const [rules, setRules] = useState<NotificationRuleRow[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    void apiRequest<{ data: NotificationRuleRow[] }>("/configuration/notification-rules")
      .then((response) => setRules(response.data))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <LoadingState />;

  return (
    <Card>
      <CardHeader
        title="When SASA tells someone"
        description="Every rule — the trigger, who is told, on which channel and in what words — is configurable per project. SMS and WhatsApp are additive: the rule already carries the channel list."
      />
      <CardBody className="p-0 sm:p-0">
        <ul className="divide-y divide-hairline">
          {rules.map((rule) => (
            <li key={rule.event_key} className="flex flex-wrap items-center gap-3 px-5 py-3.5">
              <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700">
                <Bell className="h-4 w-4" aria-hidden />
              </span>
              <div className="min-w-0 flex-1">
                <p className="font-medium text-ink-900">{rule.name}</p>
                <p className="mt-0.5 text-xs text-ink-500">
                  {(rule.recipient_roles ?? []).map(humanise).join(", ") || "The owner and the assignee"}
                </p>
              </div>
              <div className="flex flex-wrap gap-1.5">
                {(rule.channels ?? []).map((channel) => (
                  <span key={channel} className="rounded-full bg-ink-100 px-2 py-0.5 text-xs text-ink-600">
                    {humanise(channel)}
                  </span>
                ))}
              </div>
              <StatusBadge status={rule.rule?.is_active === false ? "inactive" : "active"} size="sm" />
            </li>
          ))}
        </ul>
      </CardBody>
    </Card>
  );
}

/* ------------------------------------------------------------------ lists */

function ListSettings() {
  const { data, isLoading } = useConfiguration();

  if (isLoading) return <LoadingState />;

  const lists = data?.data.lists;

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      {[
        ["Stakeholder types", lists?.stakeholder_types ?? [], Users],
        ["Engagement methods", lists?.engagement_methods ?? [], Layers],
        ["Project phases", lists?.project_phases ?? [], Layers],
        ["Vulnerability categories", lists?.vulnerability_categories ?? [], Users],
        ["Languages", lists?.languages ?? [], Layers],
      ].map(([title, values, Icon]) => {
        const IconComponent = Icon as typeof Users;

        return (
          <Card key={String(title)}>
            <CardHeader title={String(title)} icon={<IconComponent className="h-4 w-4" />} />
            <CardBody>
              <div className="flex flex-wrap gap-2">
                {(values as string[]).map((value) => (
                  <span key={value} className="rounded-full border border-hairline px-2.5 py-1 text-sm text-ink-700">
                    {humanise(value)}
                  </span>
                ))}
              </div>
            </CardBody>
          </Card>
        );
      })}

      <Card className="lg:col-span-2">
        <CardHeader
          title="Disaggregation dimensions"
          description="A dashboard cannot disaggregate on data that is not collected. Sensitive dimensions are never mandatory and are switched off by default — collecting them lawfully, voluntarily and safely is a decision for the project."
        />
        <CardBody>
          <ul className="divide-y divide-hairline">
            {(lists?.disaggregation_dimensions ?? []).map((dimension) => (
              <li key={dimension.key} className="flex flex-wrap items-center gap-3 py-3">
                <div className="min-w-0 flex-1">
                  <p className="font-medium text-ink-900">{dimension.label}</p>
                  {dimension.options.length > 0 ? (
                    <p className="mt-0.5 text-xs text-ink-500">{dimension.options.map(humanise).join(" · ")}</p>
                  ) : null}
                </div>
                {dimension.sensitive ? (
                  <span className="inline-flex items-center gap-1.5 rounded-full bg-warning-50 px-2 py-0.5 text-xs font-medium text-warning-700">
                    <Lock className="h-3 w-3" aria-hidden />
                    Sensitive
                  </span>
                ) : null}
                <StatusBadge status={dimension.enabled ? "active" : "inactive"} label={dimension.enabled ? "Collected" : "Not collected"} size="sm" />
              </li>
            ))}
          </ul>
        </CardBody>
      </Card>
    </div>
  );
}

function ImportPanel() {
  return (
    <Card>
      <CardHeader
        title="Import data"
        description="Bring in a register, a location hierarchy or historical records from a spreadsheet your team already keeps."
      />
      <CardBody>
        <Link href="/configuration/import">
          <Button variant="accent" icon={<Import className="h-4 w-4" />}>
            Open the import wizard
          </Button>
        </Link>
        <p className="mt-4 max-w-2xl text-sm leading-relaxed text-ink-600">
          The wizard downloads a template with the exact expected columns, checks every row before anything is
          written, shows you what will be created, and only then commits — in resumable batches, so a failure
          halfway through never leaves the data half-written.
        </p>
      </CardBody>
    </Card>
  );
}
