"use client";

import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { useCallback, useEffect, useMemo, useState } from "react";

/**
 * List state lives in the URL, so a filtered view can be shared, bookmarked,
 * saved and returned to with the back button.
 */
export function useListState(defaults: Record<string, string> = {}) {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();

  const params = useMemo(() => {
    const entries: Record<string, string> = { ...defaults };
    searchParams.forEach((value, key) => {
      entries[key] = value;
    });
    return entries;
  }, [defaults, searchParams]);

  const [search, setSearch] = useState(params.search ?? "");
  const [debouncedSearch, setDebouncedSearch] = useState(params.search ?? "");

  useEffect(() => {
    const timer = setTimeout(() => setDebouncedSearch(search), 300);
    return () => clearTimeout(timer);
  }, [search]);

  const push = useCallback(
    (next: Record<string, string>) => {
      const query = new URLSearchParams();

      Object.entries(next).forEach(([key, value]) => {
        if (value !== "" && value !== undefined && value !== null) query.set(key, value);
      });

      router.replace(query.toString() ? `${pathname}?${query}` : pathname, { scroll: false });
    },
    [pathname, router],
  );

  const setFilter = useCallback(
    (key: string, value: string) => {
      const next = { ...params, [key]: value, page: "1" };
      if (!value) delete next[key];
      push(next);
    },
    [params, push],
  );

  const setPage = useCallback(
    (page: number) => push({ ...params, page: String(page) }),
    [params, push],
  );

  const setSort = useCallback(
    (sort: string) => push({ ...params, sort, page: "1" }),
    [params, push],
  );

  const clear = useCallback(() => {
    setSearch("");
    push({ ...defaults });
  }, [defaults, push]);

  const applyView = useCallback(
    (filters: Record<string, string | number | boolean>) => {
      const next: Record<string, string> = { ...defaults };
      Object.entries(filters).forEach(([key, value]) => {
        next[key] = String(value);
      });
      setSearch(String(filters.search ?? ""));
      push(next);
    },
    [defaults, push],
  );

  /* Filters that are not the search box or pagination — what a saved view holds. */
  const filterValues = useMemo(() => {
    const values: Record<string, string> = {};
    Object.entries(params).forEach(([key, value]) => {
      if (["page", "per_page", "sort", "search"].includes(key)) return;
      values[key] = value;
    });
    return values;
  }, [params]);

  const query = useMemo(
    () => ({ ...params, search: debouncedSearch || undefined }),
    [debouncedSearch, params],
  );

  return {
    params,
    query,
    search,
    setSearch,
    filterValues,
    setFilter,
    setPage,
    setSort,
    clear,
    applyView,
    page: Number(params.page ?? 1),
    sort: params.sort,
  };
}
