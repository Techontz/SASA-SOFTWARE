"use client";

import { cn } from "@/lib/utils";

/**
 * A grid of large tap targets for a short list — quicker with a thumb than a
 * native dropdown, and every option is visible at once. It is a real radio
 * group, so it reads correctly with a keyboard and a screen reader.
 */
export function ChoiceGrid({
  name,
  legend,
  options,
  value,
  onChange,
  columns = 2,
  invalid,
  danger,
}: {
  name: string;
  legend: string;
  options: Array<{ value: string; label: string; hint?: string }>;
  value: string;
  onChange: (value: string) => void;
  columns?: 2 | 3;
  invalid?: boolean;
  /** Values that should read as alarming when chosen (e.g. Level 4 and 5). */
  danger?: (value: string) => boolean;
}) {
  return (
    <fieldset aria-invalid={invalid ? "true" : undefined}>
      <legend className="sr-only">{legend}</legend>
      <div className={cn("grid gap-2", columns === 3 ? "grid-cols-2 sm:grid-cols-3" : "grid-cols-1 sm:grid-cols-2")}>
        {options.map((option) => {
          const checked = option.value === value;
          const alarming = checked && danger?.(option.value);

          return (
            <label
              key={option.value}
              className={cn(
                "flex min-h-11 cursor-pointer items-center gap-2.5 rounded-lg border px-3 py-2.5 text-sm transition",
                "focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-brand-500",
                checked
                  ? alarming
                    ? "border-danger-500 bg-danger-50 font-medium text-danger-800 ring-2 ring-danger-500/20"
                    : "border-brand-600 bg-brand-50 font-medium text-brand-800 ring-2 ring-brand-500/20"
                  : invalid
                    ? "border-danger-500 bg-surface text-ink-800"
                    : "border-ink-300 bg-surface text-ink-800 hover:border-ink-400 hover:bg-ink-50",
              )}
            >
              <input
                type="radio"
                name={name}
                value={option.value}
                checked={checked}
                onChange={() => onChange(option.value)}
                className="sr-only"
              />
              <span
                aria-hidden
                className={cn(
                  "flex h-4 w-4 shrink-0 items-center justify-center rounded-full border-2",
                  checked ? "border-current" : "border-ink-300",
                )}
              >
                {checked ? <span className="h-2 w-2 rounded-full bg-current" /> : null}
              </span>
              <span className="min-w-0 leading-snug">
                {option.label}
                {option.hint ? <span className="block text-xs font-normal text-ink-500">{option.hint}</span> : null}
              </span>
            </label>
          );
        })}
      </div>
    </fieldset>
  );
}
