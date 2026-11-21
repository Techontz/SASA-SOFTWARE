"use client";

import { Check, CircleCheck } from "lucide-react";
import Link from "next/link";
import { useState, type FormEvent } from "react";
import { Button } from "@/components/ui/Button";
import { Field, FieldRow, Input, Select, Textarea } from "@/components/ui/Form";
import { ApiRequestError } from "@/lib/api/client";
import { submitEnquiry, type EnquiryType } from "@/lib/api/enquiries";
import { PLATFORM_MODULES, SECTORS } from "@/lib/content/modules";
import { cn } from "@/lib/utils";

const NOT_SURE = "not_sure";

type Values = {
  company_size: string;
  projects_count: string;
  users_count: string;
  project_location: string;
  project_stage: string;
  start_timeframe: string;
  full_name: string;
  email: string;
  organization: string;
  sector: string;
  phone: string;
  message: string;
  website: string;
};

const EMPTY: Values = {
  company_size: "",
  projects_count: "",
  users_count: "",
  project_location: "",
  project_stage: "",
  start_timeframe: "",
  full_name: "",
  email: "",
  organization: "",
  sector: "",
  phone: "",
  message: "",
  website: "",
};

const COMPANY_SIZES = [
  { value: "1-50", label: "1 to 50 people" },
  { value: "51-200", label: "51 to 200 people" },
  { value: "201-1000", label: "201 to 1,000 people" },
  { value: "1000+", label: "More than 1,000 people" },
];

const STAGES = [
  { value: "planning", label: "Planning" },
  { value: "construction", label: "Construction" },
  { value: "operation", label: "Operation" },
  { value: "mixed", label: "Several stages" },
];

const TIMEFRAMES = [
  { value: "now", label: "As soon as possible" },
  { value: "1-3_months", label: "In 1 to 3 months" },
  { value: "3-6_months", label: "In 3 to 6 months" },
  { value: "later", label: "Later" },
];

export function EnquiryForm({ type }: { type: EnquiryType }) {
  const [values, setValues] = useState<Values>(EMPTY);
  const [modules, setModules] = useState<string[]>([]);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [reference, setReference] = useState<string | null>(null);

  const set = (key: keyof Values) => (value: string) => setValues((current) => ({ ...current, [key]: value }));

  /* "Not sure yet" stands alone: choosing it clears the modules, and choosing
     a module clears it. */
  const toggle = (key: string) =>
    setModules((current) => {
      if (key === NOT_SURE) return current.includes(NOT_SURE) ? [] : [NOT_SURE];
      const without = current.filter((item) => item !== NOT_SURE && item !== key);
      return current.includes(key) ? without : [...without, key];
    });

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    const next: Record<string, string> = {};
    if (!values.full_name.trim()) next.full_name = "Enter your full name.";
    if (!/^\S+@\S+\.\S+$/.test(values.email.trim())) next.email = "Enter a valid e-mail address.";
    if (!values.organization.trim()) next.organization = "Enter your organization.";
    if (!values.sector) next.sector = "Choose your sector.";
    if (type === "quote") {
      if (!values.company_size) next.company_size = "Choose the size of your company.";
      const projects = Number(values.projects_count);
      if (!Number.isInteger(projects) || projects < 1) next.projects_count = "Enter how many projects, for example 2.";
      const users = Number(values.users_count);
      if (!Number.isInteger(users) || users < 1) next.users_count = "Enter roughly how many people will use SASA.";
    }
    setErrors(next);
    if (Object.keys(next).length) return;

    setBusy(true);
    setFormError(null);
    try {
      const { data } = await submitEnquiry({
        type,
        full_name: values.full_name.trim(),
        email: values.email.trim(),
        organization: values.organization.trim(),
        sector: values.sector,
        phone: values.phone.trim() || undefined,
        modules: modules.length ? modules : undefined,
        message: values.message.trim() || undefined,
        website: values.website || undefined,
        ...(type === "quote"
          ? {
              company_size: values.company_size,
              projects_count: Number(values.projects_count),
              users_count: Number(values.users_count),
              project_location: values.project_location.trim() || undefined,
              project_stage: values.project_stage || undefined,
              start_timeframe: values.start_timeframe || undefined,
            }
          : {}),
      });
      setReference(data.reference);
    } catch (error) {
      if (error instanceof ApiRequestError && error.validationErrors) {
        setErrors(Object.fromEntries(Object.entries(error.validationErrors).map(([key, value]) => [key, value[0] ?? ""])));
      }
      setFormError(
        error instanceof ApiRequestError && error.isOffline
          ? "You are offline. Connect to the internet and send the form again."
          : "We could not send the form just now. Please try again.",
      );
    } finally {
      setBusy(false);
    }
  };

  if (reference) {
    return (
      <div className="sasa-card flex flex-col items-center p-8 text-center sm:p-12" role="status">
        <span className="grid h-16 w-16 place-items-center rounded-full bg-success-50 text-success-700">
          <CircleCheck className="h-8 w-8" aria-hidden />
        </span>
        <h2 className="mt-6 text-2xl font-semibold tracking-[-0.03em] text-ink-900">
          {type === "interest" ? "Thank you for registering your interest." : "Thank you. We have your request."}
        </h2>
        <p className="mt-3 max-w-[26rem] text-ink-600">
          {type === "demo"
            ? "Our team will contact you to arrange a suitable time for the demonstration."
            : type === "quote"
              ? "Our team will review your project needs and contact you with a quote."
              : "We will contact you about platform availability and relevant pilot opportunities."}
        </p>
        <p className="mt-6 rounded-full bg-ink-100 px-4 py-2 text-sm font-medium text-ink-800">Reference {reference}</p>
        <Link href="/" className="mt-8 text-sm font-semibold text-brand-700 hover:underline">
          Back to the home page
        </Link>
      </div>
    );
  }

  const moduleOptions = [
    ...PLATFORM_MODULES.map((module) => ({ key: module.key as string, label: module.name })),
    { key: NOT_SURE, label: "Not sure yet" },
  ];

  return (
    <form onSubmit={submit} noValidate className="sasa-card p-6 sm:p-8">
      <div className="space-y-5">
        <Field label="Full name" required error={errors.full_name} htmlFor="full_name">
          <Input
            id="full_name"
            autoComplete="name"
            value={values.full_name}
            onChange={(event) => set("full_name")(event.target.value)}
            invalid={Boolean(errors.full_name)}
          />
        </Field>
        <FieldRow>
          <Field label="E-mail address" required error={errors.email} htmlFor="email">
            <Input
              id="email"
              type="email"
              autoComplete="email"
              autoCapitalize="none"
              value={values.email}
              onChange={(event) => set("email")(event.target.value)}
              invalid={Boolean(errors.email)}
            />
          </Field>
          <Field label="Phone number" optional error={errors.phone} htmlFor="phone">
            <Input
              id="phone"
              type="tel"
              inputMode="tel"
              autoComplete="tel"
              value={values.phone}
              onChange={(event) => set("phone")(event.target.value)}
            />
          </Field>
        </FieldRow>
        <FieldRow>
          <Field label="Organization" required error={errors.organization} htmlFor="organization">
            <Input
              id="organization"
              autoComplete="organization"
              value={values.organization}
              onChange={(event) => set("organization")(event.target.value)}
              invalid={Boolean(errors.organization)}
            />
          </Field>
          <Field label="Sector" required error={errors.sector} htmlFor="sector">
            <Select
              id="sector"
              value={values.sector}
              onChange={(event) => set("sector")(event.target.value)}
              invalid={Boolean(errors.sector)}
            >
              <option value="">Choose</option>
              {SECTORS.map((sector) => (
                <option key={sector.value} value={sector.value}>
                  {sector.label}
                </option>
              ))}
            </Select>
          </Field>
        </FieldRow>

        {type === "quote" ? (
          <div className="space-y-5 rounded-2xl bg-surface-sunken p-5 ring-1 ring-hairline">
            <p className="text-sm font-semibold text-ink-900">About your project</p>
            <FieldRow>
              <Field label="Company size" required error={errors.company_size} htmlFor="company_size">
                <Select
                  id="company_size"
                  value={values.company_size}
                  onChange={(event) => set("company_size")(event.target.value)}
                  invalid={Boolean(errors.company_size)}
                >
                  <option value="">Choose</option>
                  {COMPANY_SIZES.map((option) => (
                    <option key={option.value} value={option.value}>
                      {option.label}
                    </option>
                  ))}
                </Select>
              </Field>
              <Field label="Project stage" optional htmlFor="project_stage">
                <Select id="project_stage" value={values.project_stage} onChange={(event) => set("project_stage")(event.target.value)}>
                  <option value="">Choose</option>
                  {STAGES.map((option) => (
                    <option key={option.value} value={option.value}>
                      {option.label}
                    </option>
                  ))}
                </Select>
              </Field>
            </FieldRow>
            <FieldRow>
              <Field label="Number of projects" required error={errors.projects_count} hint="Projects you want on SASA." htmlFor="projects_count">
                <Input
                  id="projects_count"
                  inputMode="numeric"
                  value={values.projects_count}
                  onChange={(event) => set("projects_count")(event.target.value.replace(/[^\d]/g, ""))}
                  invalid={Boolean(errors.projects_count)}
                />
              </Field>
              <Field label="Expected users" required error={errors.users_count} hint="People who will sign in." htmlFor="users_count">
                <Input
                  id="users_count"
                  inputMode="numeric"
                  value={values.users_count}
                  onChange={(event) => set("users_count")(event.target.value.replace(/[^\d]/g, ""))}
                  invalid={Boolean(errors.users_count)}
                />
              </Field>
            </FieldRow>
            <FieldRow>
              <Field label="Project location" optional htmlFor="project_location">
                <Input
                  id="project_location"
                  placeholder="Region or corridor"
                  value={values.project_location}
                  onChange={(event) => set("project_location")(event.target.value)}
                />
              </Field>
              <Field label="When would you like to start?" optional htmlFor="start_timeframe">
                <Select id="start_timeframe" value={values.start_timeframe} onChange={(event) => set("start_timeframe")(event.target.value)}>
                  <option value="">Choose</option>
                  {TIMEFRAMES.map((option) => (
                    <option key={option.value} value={option.value}>
                      {option.label}
                    </option>
                  ))}
                </Select>
              </Field>
            </FieldRow>
          </div>
        ) : null}

        <fieldset>
          <legend className="mb-1.5 flex items-baseline gap-2 text-sm font-medium text-ink-800">
            Modules of interest <span className="text-xs font-normal text-ink-500">optional, choose any</span>
          </legend>
          <div className="flex flex-wrap gap-2">
            {moduleOptions.map((option) => {
              const selected = modules.includes(option.key);
              return (
                <button
                  key={option.key}
                  type="button"
                  role="checkbox"
                  aria-checked={selected}
                  onClick={() => toggle(option.key)}
                  className={cn(
                    "inline-flex items-center gap-1.5 rounded-full px-3.5 py-2 text-sm font-medium transition-colors",
                    selected ? "bg-primary text-on-primary" : "bg-ink-100 text-ink-800 hover:bg-ink-200",
                  )}
                >
                  {selected ? <Check className="h-3.5 w-3.5" aria-hidden /> : null}
                  {option.label}
                </button>
              );
            })}
          </div>
          {errors.modules ? <p className="mt-1.5 text-sm text-danger-600">{errors.modules}</p> : null}
        </fieldset>

        <Field label="Brief message" optional error={errors.message} htmlFor="message">
          <Textarea
            id="message"
            rows={4}
            maxLength={2000}
            value={values.message}
            onChange={(event) => set("message")(event.target.value)}
            placeholder={
              type === "demo"
                ? "The workflows you want to explore, and times that suit you"
                : type === "quote"
                  ? "Your project, how many people will use SASA, and when you would like to start"
                  : "Your project, its location and what your team needs"
            }
          />
        </Field>

        {/* A field people never see; bots fill it in. */}
        <div className="absolute -left-[9999px] h-0 w-0 overflow-hidden" aria-hidden>
          <label htmlFor="website">Website</label>
          <input
            id="website"
            tabIndex={-1}
            autoComplete="off"
            value={values.website}
            onChange={(event) => set("website")(event.target.value)}
          />
        </div>
      </div>

      {formError ? (
        <p className="mt-6 rounded-xl bg-danger-50 p-3.5 text-sm text-danger-700" role="alert">
          {formError}
        </p>
      ) : null}

      <Button type="submit" variant="accent" size="lg" fullWidth loading={busy} className="mt-8">
        {type === "demo" ? "Request a demonstration" : type === "quote" ? "Request a quote" : "Register interest"}
      </Button>
      <p className="mt-4 text-center text-xs text-ink-500">
        We use these details only to respond to you. See{" "}
        <Link href="/privacy" className="font-medium text-brand-700 hover:underline">
          Privacy and website terms
        </Link>
        .
      </p>
    </form>
  );
}
