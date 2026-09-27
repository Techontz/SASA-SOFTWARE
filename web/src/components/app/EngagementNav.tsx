"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { cn } from "@/lib/utils";

const TABS = [
  { href: "/engagements", label: "What happened", exact: true },
  { href: "/engagements/plans", label: "What is planned" },
  { href: "/engagements/calendar", label: "Calendar" },
];

/** Module 2 has two halves — the plan and the log — and they belong together. */
export function EngagementNav() {
  const pathname = usePathname();

  return (
    <div className="mb-5 inline-flex rounded-lg border border-hairline bg-surface p-1">
      {TABS.map((tab) => {
        const active = tab.exact ? pathname === tab.href : pathname.startsWith(tab.href);

        return (
          <Link
            key={tab.href}
            href={tab.href}
            className={cn(
              "rounded-md px-3.5 py-2 text-sm font-medium transition",
              active ? "bg-primary text-on-primary" : "text-ink-600 hover:bg-ink-100 hover:text-ink-900",
            )}
          >
            {tab.label}
          </Link>
        );
      })}
    </div>
  );
}
