"use client";

import { ChevronLeft, ChevronRight, ChevronsUpDown } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import type { ReactNode } from "react";
import { cn } from "@/lib/utils";
import { Button } from "./Button";

export interface Column<T> {
  key: string;
  header: string;
  /** Desktop cell. */
  cell: (row: T) => ReactNode;
  sortable?: boolean;
  align?: "left" | "right";
  /** Hidden below this breakpoint on desktop; the card view carries it instead. */
  hideBelow?: "sm" | "md" | "lg" | "xl";
  width?: string;
}

interface DataTableProps<T> {
  rows: T[];
  columns: Column<T>[];
  rowKey: (row: T) => string | number;
  href?: (row: T) => string;
  /** Mobile rendering. A 12-column table is never squeezed into a phone. */
  mobileCard: (row: T) => ReactNode;
  sort?: string;
  onSortChange?: (sort: string) => void;
  emptyState?: ReactNode;
  loading?: boolean;
  pagination?: {
    currentPage: number;
    lastPage: number;
    total: number;
    from: number | null;
    to: number | null;
    onPageChange: (page: number) => void;
  };
  className?: string;
}

export function DataTable<T>({
  rows,
  columns,
  rowKey,
  href,
  mobileCard,
  sort,
  onSortChange,
  emptyState,
  loading,
  pagination,
  className,
}: DataTableProps<T>) {
  const router = useRouter();
  const currentColumn = sort?.replace(/^-/, "");

  /*
   * Row navigation is handled by a click on the row rather than by wrapping
   * every cell in a link: a cell may legitimately contain its own link, and an
   * anchor inside an anchor is invalid HTML that breaks hydration.
   */
  const openRow = (row: T, event: React.MouseEvent) => {
    const target = event.target as HTMLElement;
    if (target.closest("a, button, input, select, label")) return;

    const url = href?.(row);
    if (url) router.push(url);
  };
  const currentDirection = sort?.startsWith("-") ? "desc" : "asc";

  const toggleSort = (key: string) => {
    if (!onSortChange) return;
    onSortChange(currentColumn === key && currentDirection === "asc" ? `-${key}` : key);
  };

  if (!loading && rows.length === 0 && emptyState) {
    return <div className={cn("sasa-card", className)}>{emptyState}</div>;
  }

  return (
    <div className={cn("sasa-card overflow-hidden", className)}>
      {/* ---------- Desktop: a dense, readable table ---------- */}
      <div className="sasa-scroll-x hidden overflow-x-auto md:block">
        <table className="w-full min-w-[720px] border-collapse text-sm">
          <thead>
            <tr className="border-b border-hairline bg-surface-sunken">
              {columns.map((column) => (
                <th
                  key={column.key}
                  scope="col"
                  aria-sort={
                    column.sortable && onSortChange
                      ? currentColumn === column.key
                        ? currentDirection === "asc"
                          ? "ascending"
                          : "descending"
                        : "none"
                      : undefined
                  }
                  style={column.width ? { width: column.width } : undefined}
                  className={cn(
                    "px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.06em] text-ink-500",
                    column.align === "right" && "text-right",
                    column.hideBelow === "lg" && "hidden lg:table-cell",
                    column.hideBelow === "xl" && "hidden xl:table-cell",
                  )}
                >
                  {column.sortable && onSortChange ? (
                    <button
                      type="button"
                      onClick={() => toggleSort(column.key)}
                      className={cn(
                        "inline-flex items-center gap-1 rounded transition hover:text-ink-800",
                        currentColumn === column.key && "text-brand-700",
                      )}
                    >
                      {column.header}
                      <ChevronsUpDown className="h-3 w-3" aria-hidden />
                    </button>
                  ) : (
                    column.header
                  )}
                </th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-hairline">
            {rows.map((row) => {
              const key = rowKey(row);
              const target = href?.(row);

              return (
                <tr
                  key={key}
                  onClick={target ? (event) => openRow(row, event) : undefined}
                  className={cn(
                    "group transition-colors",
                    target ? "cursor-pointer hover:bg-brand-50/50" : "hover:bg-ink-50/60",
                  )}
                >
                  {columns.map((column, index) => (
                    <td
                      key={column.key}
                      className={cn(
                        "px-4 py-3.5 align-middle text-ink-700",
                        column.align === "right" && "text-right",
                        column.hideBelow === "lg" && "hidden lg:table-cell",
                        column.hideBelow === "xl" && "hidden xl:table-cell",
                      )}
                    >
                      {/* The first cell carries the real link, so the row is
                          reachable and announced by a keyboard and a screen
                          reader — the row click is only a convenience. */}
                      {target && index === 0 ? (
                        <Link href={target} className="block focus-visible:outline-offset-4">
                          {column.cell(row)}
                        </Link>
                      ) : (
                        column.cell(row)
                      )}
                    </td>
                  ))}
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      {/* ---------- Mobile: cards, not a squeezed table ---------- */}
      <ul className="divide-y divide-hairline md:hidden">
        {rows.map((row) => {
          const target = href?.(row);
          const content = <div className="px-4 py-4">{mobileCard(row)}</div>;

          return (
            <li key={rowKey(row)}>
              {target ? (
                <Link href={target} className="block transition active:bg-brand-50">
                  {content}
                </Link>
              ) : (
                content
              )}
            </li>
          );
        })}
      </ul>

      {pagination && pagination.lastPage > 1 ? (
        <nav
          className="flex items-center justify-between gap-3 border-t border-hairline px-4 py-3 sm:px-6"
          aria-label="Pagination"
        >
          <p className="text-sm text-ink-600">
            <span className="tabular font-medium text-ink-800">
              {pagination.from ?? 0}–{pagination.to ?? 0}
            </span>{" "}
            of <span className="tabular font-medium text-ink-800">{pagination.total}</span>
          </p>
          <div className="flex items-center gap-2">
            <Button
              size="sm"
              variant="secondary"
              disabled={pagination.currentPage <= 1}
              onClick={() => pagination.onPageChange(pagination.currentPage - 1)}
              icon={<ChevronLeft className="h-4 w-4" />}
            >
              <span className="sr-only sm:not-sr-only">Previous</span>
            </Button>
            <span className="tabular px-1 text-sm text-ink-600">
              {pagination.currentPage} / {pagination.lastPage}
            </span>
            <Button
              size="sm"
              variant="secondary"
              disabled={pagination.currentPage >= pagination.lastPage}
              onClick={() => pagination.onPageChange(pagination.currentPage + 1)}
              iconRight={<ChevronRight className="h-4 w-4" />}
            >
              <span className="sr-only sm:not-sr-only">Next</span>
            </Button>
          </div>
        </nav>
      ) : null}
    </div>
  );
}
