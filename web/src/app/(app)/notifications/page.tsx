"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { AlertTriangle, Bell, CheckCheck, Info, ShieldAlert } from "lucide-react";
import Link from "next/link";
import { Button } from "@/components/ui/Button";
import { Card, CardBody } from "@/components/ui/Card";
import { PageHeader } from "@/components/ui/DetailLayout";
import { EmptyState, LoadingState } from "@/components/ui/States";
import { apiRequest } from "@/lib/api/client";
import { cn, formatRelative } from "@/lib/utils";
import type { NotificationItem, Paginated } from "@/types/api";

const TONE = {
  info: { icon: Info, className: "bg-info-50 text-info-600" },
  warning: { icon: AlertTriangle, className: "bg-warning-50 text-warning-600" },
  danger: { icon: ShieldAlert, className: "bg-danger-50 text-danger-600" },
};

export default function NotificationsPage() {
  const queryClient = useQueryClient();

  const { data, isLoading } = useQuery({
    queryKey: ["notifications", "page"],
    queryFn: () =>
      apiRequest<{ data: Paginated<NotificationItem> }>("/notifications", {
        withoutProject: true,
        query: { per_page: 50 },
      }),
  });

  const markAll = useMutation({
    mutationFn: () => apiRequest("/notifications/read-all", { method: "POST", withoutProject: true }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["notifications"] }),
  });

  if (isLoading) return <LoadingState />;

  const items = data?.data.data ?? [];
  const unread = items.filter((item) => !item.read_at).length;

  return (
    <div className="mx-auto max-w-3xl">
      <PageHeader
        title="Notifications"
        description="What SASA has told you: cases assigned, deadlines approaching, commitments falling due, and anything escalated."
        actions={
          unread > 0 ? (
            <Button variant="secondary" icon={<CheckCheck className="h-4 w-4" />} onClick={() => markAll.mutate()}>
              Mark all read
            </Button>
          ) : null
        }
      />

      <Card>
        <CardBody className="p-0 sm:p-0">
          {items.length === 0 ? (
            <EmptyState
              icon={Bell}
              title="Nothing yet"
              description="Notifications arrive when a case is assigned to you, an SLA is approaching, a commitment falls due, or something is escalated."
            />
          ) : (
            <ul className="divide-y divide-hairline">
              {items.map((item) => {
                const tone = TONE[item.severity] ?? TONE.info;
                const Icon = tone.icon;

                const content = (
                  <div className={cn("flex gap-3 px-5 py-4 transition", !item.read_at && "bg-brand-50/40")}>
                    <span className={cn("mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full", tone.className)}>
                      <Icon className="h-4 w-4" aria-hidden />
                    </span>
                    <div className="min-w-0 flex-1">
                      <p className="font-medium text-ink-900">{item.subject}</p>
                      {item.body ? <p className="mt-0.5 text-sm leading-relaxed text-ink-600">{item.body}</p> : null}
                      <p className="mt-1 text-xs text-ink-400">{formatRelative(item.created_at)}</p>
                    </div>
                    {!item.read_at ? <span className="mt-2 h-2 w-2 shrink-0 rounded-full bg-accent-500" aria-label="Unread" /> : null}
                  </div>
                );

                return (
                  <li key={item.id}>
                    {item.url ? (
                      <Link href={item.url} className="block hover:bg-ink-50">
                        {content}
                      </Link>
                    ) : (
                      content
                    )}
                  </li>
                );
              })}
            </ul>
          )}
        </CardBody>
      </Card>
    </div>
  );
}
