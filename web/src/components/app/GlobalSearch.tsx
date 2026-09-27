"use client";

import { useQuery } from "@tanstack/react-query";
import {
  CalendarCheck,
  CornerDownLeft,
  FileText,
  Handshake,
  MessageSquareWarning,
  Search,
  ShieldAlert,
  Users,
  X,
  type LucideIcon,
} from "lucide-react";
import { useRouter } from "next/navigation";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { apiRequest } from "@/lib/api/client";
import { cn } from "@/lib/utils";
import { StatusBadge } from "@/components/ui/StatusBadge";
import type { SearchResponse, SearchResult } from "@/types/api";

/** The icon carries the record type, so a mixed result list can be scanned. */
const GROUP_ICONS: Record<string, LucideIcon> = {
  stakeholders: Users,
  engagements: CalendarCheck,
  concerns: MessageSquareWarning,
  grievances: ShieldAlert,
  commitments: Handshake,
  reports: FileText,
  projects: FileText,
};

/**
 * Search across everything the signed-in user is allowed to see on this
 * project: stakeholders, engagements, concerns, grievances and commitments.
 *
 * The filtering is entirely the server's job. A confidential case never
 * appears here for someone outside its handling group, and a restricted one
 * does not appear at all — this component sends a string and renders whatever
 * comes back, so there is nothing here to get wrong.
 */
export function GlobalSearch() {
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [term, setTerm] = useState("");
  const [debounced, setDebounced] = useState("");
  /* The highlighted row is stored together with the result set it belongs to,
     and read back only when those still match. That way a new set of results
     starts from the top without an effect having to reset anything. */
  const [cursorState, setCursorState] = useState({ key: "", index: 0 });
  const inputRef = useRef<HTMLInputElement>(null);
  const listRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(term), 220);
    return () => clearTimeout(timer);
  }, [term]);

  useEffect(() => {
    if (open) {
      const timer = setTimeout(() => inputRef.current?.focus(), 40);
      return () => clearTimeout(timer);
    }
    return undefined;
  }, [open]);

  const enabled = open && debounced.trim().length >= 2;

  const { data, isFetching } = useQuery({
    queryKey: ["search", debounced],
    queryFn: () => apiRequest<{ data: SearchResponse }>("/search", { query: { q: debounced } }),
    enabled,
    staleTime: 20_000,
  });

  const groups = useMemo(
    () => (data?.data.groups ?? []).filter((group) => group.results.length > 0),
    [data],
  );

  /* One flat list behind the grouped display, so ↑/↓ can cross a heading. */
  const flat = useMemo(
    () => groups.flatMap((group) => group.results.map((result) => ({ group: group.key, result }))),
    [groups],
  );

  const resultKey = `${debounced}:${flat.length}`;
  const cursor = cursorState.key === resultKey ? cursorState.index : 0;

  const setCursor = useCallback(
    (next: number | ((current: number) => number)) =>
      setCursorState((previous) => {
        const base = previous.key === resultKey ? previous.index : 0;
        return { key: resultKey, index: typeof next === "function" ? next(base) : next };
      }),
    [resultKey],
  );

  const close = useCallback(() => {
    setOpen(false);
    setTerm("");
    setDebounced("");
  }, []);

  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "k") {
        event.preventDefault();
        setOpen(true);
        return;
      }

      /* Escape is bound to the window rather than the dialog: in the moment
         between opening and the input taking focus, a key pressed on the body
         would otherwise do nothing and the overlay would feel stuck. */
      if (event.key === "Escape") close();
    };

    window.addEventListener("keydown", onKeyDown);
    return () => window.removeEventListener("keydown", onKeyDown);
  }, [close]);

  const go = useCallback(
    (href: string) => {
      close();
      router.push(href);
    },
    [close, router],
  );

  const onKeyDown = (event: React.KeyboardEvent) => {
    if (flat.length === 0) return;

    if (event.key === "ArrowDown") {
      event.preventDefault();
      setCursor((current) => (current + 1) % flat.length);
    } else if (event.key === "ArrowUp") {
      event.preventDefault();
      setCursor((current) => (current - 1 + flat.length) % flat.length);
    } else if (event.key === "Enter") {
      event.preventDefault();
      const target = flat[cursor];
      if (target) go(target.result.href);
    }
  };

  /* Keep the highlighted row in view when the cursor is driven by the keyboard. */
  useEffect(() => {
    listRef.current
      ?.querySelector<HTMLElement>('[data-active="true"]')
      ?.scrollIntoView({ block: "nearest" });
  }, [cursor]);

  const showScanner = isFetching && flat.length === 0;
  const tooShort = debounced.trim().length < 2;
  const nothingFound = !tooShort && !isFetching && flat.length === 0;

  let index = -1;

  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        className="group flex h-10 w-full max-w-md items-center gap-2.5 rounded-lg border border-ink-200 bg-surface-sunken px-3 text-left text-sm text-ink-500 transition hover:border-ink-300 hover:bg-surface"
      >
        <Search className="h-4 w-4 shrink-0" aria-hidden />
        <span className="flex-1 truncate">Search stakeholders, cases, commitments…</span>
        <kbd className="hidden shrink-0 rounded border border-ink-200 bg-surface px-1.5 py-0.5 text-[0.6875rem] font-medium text-ink-500 lg:inline-block">
          ⌘K
        </kbd>
      </button>

      {open ? (
        <div className="fixed inset-0 z-[60] flex items-start justify-center p-0 sm:p-6 sm:pt-24">
          <div className="sasa-scrim animate-fade-in absolute inset-0" onClick={close} aria-hidden />
          <div
            role="dialog"
            aria-modal="true"
            aria-label="Search"
            onKeyDown={onKeyDown}
            className="animate-pop-in relative flex h-full w-full flex-col overflow-hidden rounded-none bg-surface shadow-[var(--shadow-overlay)] sm:h-auto sm:max-h-[70vh] sm:max-w-2xl sm:rounded-xl"
          >
            <div className="relative flex items-center gap-3 border-b border-hairline px-4 py-3">
              <Search className="h-5 w-5 shrink-0 text-ink-400" aria-hidden />
              <input
                ref={inputRef}
                value={term}
                onChange={(event) => setTerm(event.target.value)}
                placeholder="Search by name, case ID, phone number or place"
                className="h-9 flex-1 border-0 bg-transparent text-base text-ink-900 outline-none placeholder:text-ink-400"
                aria-label="Search"
                /* The list is rendered below, not in a popup, so the input owns
                   the active-descendant relationship for screen readers. */
                role="combobox"
                aria-expanded={flat.length > 0}
                aria-controls="sasa-search-results"
                aria-activedescendant={flat[cursor] ? `sasa-search-option-${cursor}` : undefined}
                autoComplete="off"
              />
              {/* The scanner keeps turning while a refetch happens behind
                  results that are already on screen, so the small one sits in
                  the bar and the big one only appears on an empty panel. */}
              {isFetching && flat.length > 0 ? (
                <span className="sasa-scanner" style={{ fontSize: 3 }} role="status">
                  Searching
                </span>
              ) : null}
              <button
                type="button"
                onClick={close}
                className="-m-1 rounded p-1 text-ink-500 transition hover:bg-ink-100 hover:text-ink-900"
                aria-label="Close search"
              >
                <X className="h-5 w-5" aria-hidden />
              </button>
            </div>

            <div id="sasa-search-results" ref={listRef} className="relative flex-1 overflow-y-auto" role="listbox" aria-label="Search results">
              {/* A sweep down the panel while the query is in flight: the wait
                  is visible without the results being replaced by a spinner. */}
              {isFetching ? <span className="sasa-scanline" aria-hidden /> : null}

              {showScanner ? (
                <div className="flex flex-col items-center gap-4 px-5 py-14">
                  <span className="sasa-scanner" style={{ fontSize: 7 }} role="status">
                    Searching
                  </span>
                  <p className="text-sm text-ink-500">
                    Searching this project for &ldquo;{debounced}&rdquo;…
                  </p>
                </div>
              ) : tooShort ? (
                <div className="px-5 py-12 text-center">
                  <Search className="mx-auto h-7 w-7 text-ink-300" aria-hidden />
                  <p className="mt-3 text-sm text-ink-500">
                    Type at least two characters. You can search a case ID like GRV-0012, a
                    person&apos;s name, a phone number or a village.
                  </p>
                </div>
              ) : nothingFound ? (
                <div className="px-5 py-12 text-center">
                  <Search className="mx-auto h-7 w-7 text-ink-300" aria-hidden />
                  <p className="mt-3 text-[0.9375rem] font-medium text-ink-900">
                    Nothing matched &ldquo;{debounced}&rdquo;
                  </p>
                  <p className="mx-auto mt-1.5 max-w-sm text-sm text-ink-500">
                    Only this project is searched, and only records you have permission to open.
                    Check the spelling, or try a phone number or a case ID.
                  </p>
                </div>
              ) : (
                groups.map((group) => {
                  const Icon = GROUP_ICONS[group.key] ?? FileText;
                  return (
                    <div key={group.key} className="border-b border-hairline last:border-0">
                      <p className="sasa-eyebrow flex items-center gap-1.5 px-5 pb-1 pt-4">
                        <Icon className="h-3.5 w-3.5" aria-hidden />
                        {group.label}
                        <span className="text-ink-400">({group.results.length})</span>
                      </p>
                      <ul>
                        {group.results.map((result) => {
                          index += 1;
                          const active = index === cursor;
                          return (
                            <SearchRow
                              key={`${group.key}-${result.id}`}
                              id={`sasa-search-option-${index}`}
                              result={result}
                              active={active}
                              onSelect={() => go(result.href)}
                              onHover={() => setCursor(index)}
                            />
                          );
                        })}
                      </ul>
                    </div>
                  );
                })
              )}
            </div>

            {flat.length > 0 ? (
              <div className="hidden items-center justify-between gap-4 border-t border-hairline bg-surface-sunken px-5 py-2 text-xs text-ink-500 sm:flex">
                <span className="flex items-center gap-3">
                  <Hint keys="↑ ↓">to move</Hint>
                  <Hint keys="↵">to open</Hint>
                  <Hint keys="esc">to close</Hint>
                </span>
                <span>
                  {flat.length} {flat.length === 1 ? "result" : "results"}
                </span>
              </div>
            ) : null}
          </div>
        </div>
      ) : null}
    </>
  );
}

function Hint({ keys, children }: { keys: string; children: string }) {
  return (
    <span className="flex items-center gap-1">
      <kbd className="rounded border border-ink-200 bg-surface px-1.5 py-0.5 font-sans text-[0.6875rem] font-medium text-ink-600">
        {keys}
      </kbd>
      {children}
    </span>
  );
}

function SearchRow({
  id,
  result,
  active,
  onSelect,
  onHover,
}: {
  id: string;
  result: SearchResult;
  active: boolean;
  onSelect: () => void;
  onHover: () => void;
}) {
  return (
    <li id={id} role="option" aria-selected={active}>
      <button
        type="button"
        onClick={onSelect}
        onMouseMove={onHover}
        data-active={active}
        className={cn(
          "flex w-full items-start gap-3 px-5 py-3 text-left transition-colors",
          active ? "bg-brand-50" : "hover:bg-brand-50",
        )}
      >
        <span className="mt-0.5 shrink-0 rounded bg-ink-100 px-1.5 py-0.5 font-mono text-[0.6875rem] text-ink-600">
          {result.reference}
        </span>
        <span className="min-w-0 flex-1">
          <span className="block truncate font-medium text-ink-900">{result.title}</span>
          <span className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-ink-500">
            {result.subtitle ? <span>{result.subtitle}</span> : null}
            {result.location ? <span>· {result.location}</span> : null}
          </span>
        </span>
        {result.status ? <StatusBadge status={result.status} size="sm" /> : null}
        {active ? (
          <CornerDownLeft className="mt-1 h-3.5 w-3.5 shrink-0 text-ink-400" aria-hidden />
        ) : null}
      </button>
    </li>
  );
}
