"use client";

import { Search, SlidersHorizontal, X } from "lucide-react";
import { useState, type ReactNode } from "react";
import { cn, humanise } from "@/lib/utils";
import { Button } from "./Button";

export interface FilterOption {
  value: string;
  label: string;
}

export interface FilterDefinition {
  key: string;
  label: string;
  options: FilterOption[];
  placeholder?: string;
}

/**
 * One filtering vocabulary across every list. On a phone the filters collapse
 * behind a single button so the list itself keeps the screen.
 */
export function FilterBar({
  search,
  onSearchChange,
  searchPlaceholder = "Search",
  filters,
  values,
  onChange,
  onClear,
  actions,
  children,
}: {
  search: string;
  onSearchChange: (value: string) => void;
  searchPlaceholder?: string;
  filters: FilterDefinition[];
  values: Record<string, string>;
  onChange: (key: string, value: string) => void;
  onClear: () => void;
  actions?: ReactNode;
  children?: ReactNode;
}) {
  const [expanded, setExpanded] = useState(false);
  const activeCount = Object.values(values).filter(Boolean).length;

  return (
    <div className="sasa-card overflow-hidden">
      <div className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:p-4">
        <div className="relative flex-1">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-400" aria-hidden />
          <input
            type="search"
            value={search}
            onChange={(event) => onSearchChange(event.target.value)}
            placeholder={searchPlaceholder}
            aria-label={searchPlaceholder}
            className="sasa-field pl-9"
          />
        </div>

        <div className="flex items-center gap-2">
          <Button
            variant={activeCount > 0 ? "primary" : "secondary"}
            onClick={() => setExpanded((value) => !value)}
            icon={<SlidersHorizontal className="h-4 w-4" />}
            aria-expanded={expanded}
            className="flex-1 sm:flex-none"
          >
            Filters
            {activeCount > 0 ? (
              <span className="ml-1 rounded-full bg-white/20 px-1.5 text-xs tabular">{activeCount}</span>
            ) : null}
          </Button>
          {actions}
        </div>
      </div>

      {activeCount > 0 ? (
        <div className="flex flex-wrap items-center gap-2 border-t border-hairline bg-surface-sunken px-4 py-2.5">
          {Object.entries(values)
            .filter(([, value]) => Boolean(value))
            .map(([key, value]) => {
              const definition = filters.find((filter) => filter.key === key);
              const option = definition?.options.find((candidate) => candidate.value === value);

              return (
                <button
                  key={key}
                  type="button"
                  onClick={() => onChange(key, "")}
                  className="inline-flex items-center gap-1.5 rounded-full bg-brand-50 py-1 pl-2.5 pr-2 text-xs font-medium text-brand-800 ring-1 ring-inset ring-brand-100 transition hover:bg-brand-100"
                >
                  <span className="text-brand-600">{definition?.label ?? humanise(key)}:</span>
                  {option?.label ?? humanise(value)}
                  <X className="h-3 w-3" aria-hidden />
                </button>
              );
            })}
          <button
            type="button"
            onClick={onClear}
            className="rounded px-2 py-1 text-xs font-medium text-ink-600 underline-offset-2 transition hover:text-ink-900 hover:underline"
          >
            Clear all
          </button>
        </div>
      ) : null}

      <div className={cn("border-t border-hairline bg-surface-sunken", expanded ? "block" : "hidden")}>
        <div className="grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-4">
          {filters.map((filter) => (
            <div key={filter.key}>
              <label className="mb-1.5 block text-xs font-semibold uppercase tracking-[0.06em] text-ink-500">
                {filter.label}
              </label>
              <select
                value={values[filter.key] ?? ""}
                onChange={(event) => onChange(filter.key, event.target.value)}
                className="sasa-field"
              >
                <option value="">{filter.placeholder ?? `All ${filter.label.toLowerCase()}`}</option>
                {filter.options.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </select>
            </div>
          ))}
          {children}
        </div>
      </div>
    </div>
  );
}
