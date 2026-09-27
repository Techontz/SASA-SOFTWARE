"use client";

import { AlertCircle, ChevronDown } from "lucide-react";
import {
  forwardRef,
  useId,
  type InputHTMLAttributes,
  type ReactNode,
  type SelectHTMLAttributes,
  type TextareaHTMLAttributes,
} from "react";
import { cn } from "@/lib/utils";

/* A form section keeps a long record from arriving as one wall of fields. */
export function FormSection({
  title,
  description,
  children,
  className,
  aside,
}: {
  title: string;
  description?: string;
  children: ReactNode;
  className?: string;
  aside?: ReactNode;
}) {
  return (
    <section className={cn("sasa-card overflow-hidden", className)}>
      <header className="flex flex-col gap-2 border-b border-hairline bg-surface-sunken px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
        <div>
          <h2 className="text-[0.9375rem] font-semibold text-ink-900">{title}</h2>
          {description ? <p className="mt-0.5 text-sm text-ink-600">{description}</p> : null}
        </div>
        {aside}
      </header>
      <div className="grid gap-5 px-5 py-5 sm:px-6">{children}</div>
    </section>
  );
}

export function FieldRow({ children, columns = 2 }: { children: ReactNode; columns?: 1 | 2 | 3 }) {
  return (
    <div
      className={cn(
        "grid gap-5",
        columns === 1 && "sm:grid-cols-1",
        columns === 2 && "sm:grid-cols-2",
        columns === 3 && "sm:grid-cols-2 lg:grid-cols-3",
      )}
    >
      {children}
    </div>
  );
}

interface FieldProps {
  label: string;
  hint?: string;
  error?: string;
  required?: boolean;
  optional?: boolean;
  children: ReactNode;
  htmlFor?: string;
  className?: string;
}

export function Field({ label, hint, error, required, optional, children, htmlFor, className }: FieldProps) {
  return (
    <div className={cn("min-w-0", className)}>
      <label htmlFor={htmlFor} className="mb-1.5 flex items-baseline gap-2 text-sm font-medium text-ink-800">
        <span>{label}</span>
        {required ? <span className="text-danger-500" aria-hidden>*</span> : null}
        {optional ? <span className="text-xs font-normal text-ink-500">optional</span> : null}
      </label>
      {children}
      {error ? (
        <p className="mt-1.5 flex items-start gap-1.5 text-sm text-danger-600" role="alert">
          <AlertCircle className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden />
          {error}
        </p>
      ) : hint ? (
        <p className="mt-1.5 text-sm text-ink-500">{hint}</p>
      ) : null}
    </div>
  );
}

export const Input = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement> & { invalid?: boolean }>(
  function Input({ className, invalid, ...props }, ref) {
    return (
      <input
        ref={ref}
        aria-invalid={invalid ? "true" : undefined}
        className={cn("sasa-field", className)}
        {...props}
      />
    );
  },
);

export const Textarea = forwardRef<
  HTMLTextAreaElement,
  TextareaHTMLAttributes<HTMLTextAreaElement> & { invalid?: boolean }
>(function Textarea({ className, invalid, rows = 4, ...props }, ref) {
  return (
    <textarea
      ref={ref}
      rows={rows}
      aria-invalid={invalid ? "true" : undefined}
      className={cn("sasa-field resize-y leading-relaxed", className)}
      {...props}
    />
  );
});

export const Select = forwardRef<
  HTMLSelectElement,
  SelectHTMLAttributes<HTMLSelectElement> & { invalid?: boolean; placeholder?: string }
>(function Select({ className, invalid, placeholder, children, ...props }, ref) {
  return (
    <div className="relative">
      <select
        ref={ref}
        aria-invalid={invalid ? "true" : undefined}
        className={cn("sasa-field appearance-none pr-9", className)}
        {...props}
      >
        {placeholder ? (
          <option value="">{placeholder}</option>
        ) : null}
        {children}
      </select>
      <ChevronDown className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-500" aria-hidden />
    </div>
  );
});

/** A large tap target used where the choice matters more than the space. */
export function ChoiceCard({
  checked,
  onSelect,
  title,
  description,
  icon,
  tone = "neutral",
  name,
  value,
}: {
  checked: boolean;
  onSelect: () => void;
  title: string;
  description?: string;
  icon?: ReactNode;
  tone?: "neutral" | "warning" | "danger";
  name?: string;
  value?: string;
}) {
  const id = useId();

  const toneRing =
    tone === "danger"
      ? "border-danger-500 bg-danger-50 ring-danger-500/20"
      : tone === "warning"
        ? "border-warning-500 bg-warning-50 ring-warning-500/20"
        : "border-brand-600 bg-brand-50 ring-brand-500/20";

  return (
    <label
      htmlFor={id}
      className={cn(
        "flex cursor-pointer items-start gap-3 rounded-lg border p-4 transition-all duration-[var(--duration-fast)]",
        checked ? cn(toneRing, "ring-2") : "border-ink-300 bg-surface hover:border-ink-400 hover:bg-ink-50",
      )}
    >
      <input
        id={id}
        type="radio"
        name={name}
        value={value}
        checked={checked}
        onChange={onSelect}
        className="sr-only"
      />
      <span
        className={cn(
          "mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 transition",
          checked ? "border-current text-current" : "border-ink-300",
        )}
        aria-hidden
      >
        {checked ? <span className="h-2.5 w-2.5 rounded-full bg-current" /> : null}
      </span>
      <span className="min-w-0">
        <span className="flex items-center gap-2 font-medium text-ink-900">
          {icon}
          {title}
        </span>
        {description ? <span className="mt-1 block text-sm text-ink-600">{description}</span> : null}
      </span>
    </label>
  );
}

export function Checkbox({
  checked,
  onChange,
  label,
  description,
  disabled,
}: {
  checked: boolean;
  onChange: (checked: boolean) => void;
  label: string;
  description?: string;
  disabled?: boolean;
}) {
  const id = useId();

  return (
    <label
      htmlFor={id}
      className={cn(
        "flex cursor-pointer items-start gap-3 rounded-md p-1 transition",
        disabled && "cursor-not-allowed opacity-60",
      )}
    >
      <input
        id={id}
        type="checkbox"
        checked={checked}
        disabled={disabled}
        onChange={(event) => onChange(event.target.checked)}
        className="mt-0.5 h-5 w-5 shrink-0 rounded border-ink-300 text-brand-700 accent-[var(--color-brand-700)] focus-visible:outline-2 focus-visible:outline-brand-500"
      />
      <span className="min-w-0">
        <span className="block text-sm font-medium text-ink-800">{label}</span>
        {description ? <span className="mt-0.5 block text-sm text-ink-500">{description}</span> : null}
      </span>
    </label>
  );
}

/** A sticky action bar so Save is always within thumb reach on a phone. */
export function FormActions({
  children,
  note,
}: {
  children: ReactNode;
  note?: ReactNode;
}) {
  return (
    <div className="sticky bottom-0 z-20 -mx-4 mt-2 border-t border-hairline bg-surface/95 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-lg sm:border sm:px-5 sm:shadow-[var(--shadow-raised)]">
      <div className="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
        {note ? <div className="text-sm text-ink-500">{note}</div> : <span />}
        <div className="flex flex-col-reverse gap-2 sm:flex-row sm:items-center">{children}</div>
      </div>
    </div>
  );
}
