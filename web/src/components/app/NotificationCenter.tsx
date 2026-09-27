"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { AlertTriangle, Bell, CheckCheck, Info, ShieldAlert } from "lucide-react";
import Link from "next/link";
import { useState } from "react";
import { apiRequest } from "@/lib/api/client";
import { cn, formatRelative } from "@/lib/utils";
import type { NotificationItem, Paginated } from "@/types/api";

const TONE_ICON = {
  info: { icon: Info, className: "text-info-600 bg-info-50" },
  warning: { icon: AlertTriangle, className: "text-warning-600 bg-warning-50" },
  danger: { icon: ShieldAlert, className: "text-danger-600 bg-danger-50" },
};

export function NotificationCenter() {
  const [open, setOpen] = useState(false);
  const queryClient = useQueryClient();

  const { data: unread } = useQuery({
    queryKey: ["notifications", "unread-count"],
    queryFn: () => apiRequest<{ data: { unread_count: number } }>("/notifications/unread-count", { withoutProject: true }),
    refetchInterval: 60_000,
  });

  const { data } = useQuery({
    queryKey: ["notifications", "list"],
    queryFn: () =>
      apiRequest<{ data: Paginated<NotificationItem> }>("/notifications", {
        withoutProject: true,
        query: { per_page: 12 },
      }),
    enabled: open,
  });

  const markAll = useMutation({
    mutationFn: () => apiRequest("/notifications/read-all", { method: "POST", withoutProject: true }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["notifications"] });
    },
  });

  const markOne = useMutation({
    mutationFn: (id: string) => apiRequest(`/notifications/${id}/read`, { method: "POST", withoutProject: true }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["notifications"] });
    },
  });

  const count = unread?.data.unread_count ?? 0;
  const items = data?.data.data ?? [];

  return (
    <div className="relative">
      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        aria-expanded={open}
        aria-label={count > 0 ? `Notifications, ${count} unread` : "Notifications"}
        className="relative flex h-10 w-10 items-center justify-center rounded-lg text-ink-600 transition hover:bg-ink-100 hover:text-ink-900"
      >
        <Bell className="h-5 w-5" aria-hidden />
        {count > 0 ? (
          <span className="tabular absolute -right-0.5 -top-0.5 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-accent-solid px-1 text-[0.625rem] font-semibold text-on-primary">
            {count > 99 ? "99+" : count}
          </span>
        ) : null}
      </button>

      {open ? (
        <>
          <div className="fixed inset-0 z-40" onClick={() => setOpen(false)} aria-hidden />
          <div className="animate-fade-up absolute right-0 z-50 mt-2 flex max-h-[32rem] w-[22rem] flex-col overflow-hidden rounded-xl border border-hairline bg-surface shadow-[var(--shadow-overlay)] sm:w-96">
            <header className="flex items-center justify-between border-b border-hairline px-4 py-3">
              <h2 className="font-semibold text-ink-900">Notifications</h2>
              {count > 0 ? (
                <button
                  type="button"
                  onClick={() => markAll.mutate()}
                  className="inline-flex items-center gap-1 text-xs font-medium text-brand-700 transition hover:text-brand-900"
                >
                  <CheckCheck className="h-3.5 w-3.5" aria-hidden />
                  Mark all read
                </button>
              ) : null}
            </header>

            <div className="flex-1 overflow-y-auto">
              {items.length === 0 ? (
                <p className="px-5 py-10 text-center text-sm text-ink-500">
                  Nothing new. Notifications appear here when a case is assigned to you, an SLA is
                  approaching, or a commitment falls due.
                </p>
              ) : (
                <ul className="divide-y divide-hairline">
                  {items.map((item) => {
                    const tone = TONE_ICON[item.severity] ?? TONE_ICON.info;
                    const Icon = tone.icon;

                    const inner = (
                      <div className={cn("flex gap-3 px-4 py-3 transition", !item.read_at && "bg-brand-50/50")}>
                        <span className={cn("mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full", tone.className)}>
                          <Icon className="h-4 w-4" aria-hidden />
                        </span>
                        <div className="min-w-0 flex-1">
                          <p className="text-sm font-medium text-ink-900">{item.subject}</p>
                          {item.body ? <p className="mt-0.5 text-sm text-ink-600">{item.body}</p> : null}
                          <p className="mt-1 text-xs text-ink-400">{formatRelative(item.created_at)}</p>
                        </div>
                        {!item.read_at ? <span className="mt-2 h-2 w-2 shrink-0 rounded-full bg-accent-500" aria-label="Unread" /> : null}
                      </div>
                    );

                    return (
                      <li key={item.id}>
                        {item.url ? (
                          <Link
                            href={item.url}
                            onClick={() => {
                              if (!item.read_at) markOne.mutate(item.id);
                              setOpen(false);
                            }}
                            className="block hover:bg-ink-50"
                          >
                            {inner}
                          </Link>
                        ) : (
                          <button
                            type="button"
                            className="block w-full text-left hover:bg-ink-50"
                            onClick={() => !item.read_at && markOne.mutate(item.id)}
                          >
                            {inner}
                          </button>
                        )}
                      </li>
                    );
                  })}
                </ul>
              )}
            </div>

            <footer className="border-t border-hairline px-4 py-2.5">
              <Link
                href="/notifications"
                onClick={() => setOpen(false)}
                className="block text-center text-sm font-medium text-brand-700 transition hover:text-brand-900"
              >
                See all notifications
              </Link>
            </footer>
          </div>
        </>
      ) : null}
    </div>
  );
}
