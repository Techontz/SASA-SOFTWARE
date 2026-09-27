"use client";

import {
  Check,
  ChevronDown,
  LogOut,
  Menu,
  Plus,
  X,
} from "lucide-react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useState, type ReactNode } from "react";
import { GlobalSearch } from "./GlobalSearch";
import { NotificationCenter } from "./NotificationCenter";
import { OfflineBanner, SyncIndicator } from "./SyncIndicator";
import { Button } from "@/components/ui/Button";
import { ThemeToggle } from "@/components/ui/ThemeToggle";
import {
  MOBILE_NAVIGATION,
  PRIMARY_NAVIGATION,
  QUICK_ACTIONS,
  SECONDARY_LINKS,
  isActivePath,
  type NavItem,
} from "@/lib/navigation";
import { cn, initials } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";

export function AppShell({ children }: { children: ReactNode }) {
  const pathname = usePathname();
  const { user, project, canAny } = useSession();

  /*
   * The menus close on navigation. That is derived from the path rather than
   * reset in an effect: an effect would render once with the menu still open.
   */
  const [navOpenAt, setNavOpenAt] = useState<string | null>(null);
  const [actionsOpenAt, setActionsOpenAt] = useState<string | null>(null);

  const navOpen = navOpenAt === pathname;
  const actionsOpen = actionsOpenAt === pathname;

  const setNavOpen = (open: boolean) => setNavOpenAt(open ? pathname : null);
  const setActionsOpen = (open: boolean) => setActionsOpenAt(open ? pathname : null);

  const allowed = (item: NavItem) => !item.permissions?.length || canAny(...item.permissions);
  const quickActions = QUICK_ACTIONS.filter((action) => !action.permissions?.length || canAny(...action.permissions));

  return (
    <div className="min-h-dvh bg-canvas">
      <OfflineBanner />

      <div className="flex">
        {/* ------------------------------ desktop sidebar ------------------ */}
        <aside className="sticky top-0 hidden h-dvh w-[17rem] shrink-0 flex-col border-r border-chrome-line bg-chrome lg:flex">
          <SidebarBrand />
          <ProjectSwitcher />

          <nav className="flex-1 overflow-y-auto px-3 pb-4" aria-label="Main">
            {PRIMARY_NAVIGATION.map((group) => {
              const items = group.items.filter(allowed);
              if (items.length === 0) return null;

              return (
                <div key={group.label ?? "primary"} className="mb-5">
                  {group.label ? (
                    <p className="px-3 pb-2 pt-2 text-[0.6875rem] font-semibold uppercase tracking-[0.09em] text-chrome-subtle">
                      {group.label}
                    </p>
                  ) : null}
                  <ul className="space-y-0.5">
                    {items.map((item) => {
                      const active = isActivePath(pathname, item.href);
                      const Icon = item.icon;

                      return (
                        <li key={item.href}>
                          <Link
                            href={item.href}
                            aria-current={active ? "page" : undefined}
                            className={cn(
                              "group flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors",
                              active
                                ? "bg-chrome-raised text-chrome-fg"
                                : "text-chrome-muted hover:bg-chrome-raised hover:text-chrome-fg",
                            )}
                          >
                            <Icon
                              className={cn("h-[18px] w-[18px] shrink-0", active ? "text-accent-300" : "text-chrome-subtle group-hover:text-chrome-fg")}
                              aria-hidden
                            />
                            {item.label}
                          </Link>
                        </li>
                      );
                    })}
                  </ul>
                </div>
              );
            })}
          </nav>

          <UserMenu />
        </aside>

        {/* ------------------------------ main column ---------------------- */}
        <div className="flex min-w-0 flex-1 flex-col">
          <header className="sticky top-0 z-30 border-b border-hairline bg-surface/90 backdrop-blur">
            <div className="flex h-16 min-w-0 items-center gap-3 px-4 sm:px-6">
              <button
                type="button"
                onClick={() => setNavOpen(true)}
                className="-ml-2 flex h-10 w-10 items-center justify-center rounded-lg text-ink-700 transition hover:bg-ink-100 lg:hidden"
                aria-label="Open menu"
              >
                <Menu className="h-5 w-5" aria-hidden />
              </button>

              <div className="min-w-0 flex-1 lg:hidden">
                <MobileProjectLabel />
              </div>

              <div className="hidden flex-1 lg:block">
                <GlobalSearch />
              </div>

              <div className="ml-auto flex shrink-0 items-center gap-1.5 sm:gap-2">
                <div className="hidden sm:block">
                  <SyncIndicator />
                </div>
                <div className="sm:hidden">
                  <SyncIndicator compact />
                </div>
                <NotificationCenter />
                <ThemeToggle />
                <div className="hidden lg:block">
                  {quickActions.length > 0 ? (
                    <div className="relative">
                      <Button
                        variant="accent"
                        icon={<Plus className="h-4 w-4" />}
                        onClick={() => setActionsOpen(!actionsOpen)}
                        aria-expanded={actionsOpen}
                      >
                        New
                        <ChevronDown className="h-4 w-4" aria-hidden />
                      </Button>
                      {actionsOpen ? (
                        <>
                          <div className="fixed inset-0 z-40" onClick={() => setActionsOpen(false)} aria-hidden />
                          <div className="animate-fade-up absolute right-0 z-50 mt-2 w-80 overflow-hidden rounded-xl border border-hairline bg-surface p-1.5 shadow-[var(--shadow-overlay)]">
                            {quickActions.map((action) => {
                              const Icon = action.icon;
                              return (
                                <Link
                                  key={action.href}
                                  href={action.href}
                                  className="flex items-start gap-3 rounded-lg px-3 py-2.5 transition hover:bg-brand-50"
                                >
                                  <span className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-100 text-brand-700">
                                    <Icon className="h-4 w-4" aria-hidden />
                                  </span>
                                  <span>
                                    <span className="block text-sm font-medium text-ink-900">{action.label}</span>
                                    <span className="mt-0.5 block text-xs text-ink-500">{action.description}</span>
                                  </span>
                                </Link>
                              );
                            })}
                          </div>
                        </>
                      ) : null}
                    </div>
                  ) : null}
                </div>
              </div>
            </div>

            <div className="border-t border-hairline px-4 py-2 lg:hidden">
              <GlobalSearch />
            </div>
          </header>

          <main className="min-w-0 flex-1 px-4 pb-28 pt-6 sm:px-6 lg:px-8 lg:pb-12">
            <div className="mx-auto w-full max-w-[86rem]">{children}</div>
          </main>
        </div>
      </div>

      {/* ------------------------------ mobile navigation ------------------- */}
      <MobileNav quickActionHref={quickActions[0]?.href} />

      {navOpen ? (
        <div className="fixed inset-0 z-[70] lg:hidden">
          <div className="animate-fade-in sasa-scrim absolute inset-0" onClick={() => setNavOpen(false)} aria-hidden />
          <div className="animate-slide-in-right absolute inset-y-0 right-0 flex w-[19rem] max-w-[85vw] flex-col bg-chrome">
            <div className="flex items-center justify-between px-4 py-4">
              <SidebarBrand compact />
              <button
                type="button"
                onClick={() => setNavOpen(false)}
                className="rounded-lg p-2 text-chrome-muted transition hover:bg-chrome-raised hover:text-chrome-fg"
                aria-label="Close menu"
              >
                <X className="h-5 w-5" aria-hidden />
              </button>
            </div>
            <ProjectSwitcher />
            <nav className="flex-1 overflow-y-auto px-3 pb-4" aria-label="Main">
              {PRIMARY_NAVIGATION.map((group) => {
                const items = group.items.filter(allowed);
                if (items.length === 0) return null;

                return (
                  <div key={group.label ?? "primary"} className="mb-5">
                    {group.label ? (
                      <p className="px-3 pb-2 pt-2 text-[0.6875rem] font-semibold uppercase tracking-[0.09em] text-chrome-subtle">
                        {group.label}
                      </p>
                    ) : null}
                    <ul className="space-y-0.5">
                      {items.map((item) => {
                        const active = isActivePath(pathname, item.href);
                        const Icon = item.icon;

                        return (
                          <li key={item.href}>
                            <Link
                              href={item.href}
                              className={cn(
                                "flex items-center gap-3 rounded-lg px-3 py-3 text-[0.9375rem] font-medium transition",
                                active ? "bg-chrome-raised text-chrome-fg" : "text-chrome-muted hover:bg-chrome-raised hover:text-chrome-fg",
                              )}
                            >
                              <Icon className="h-5 w-5 shrink-0" aria-hidden />
                              {item.label}
                            </Link>
                          </li>
                        );
                      })}
                    </ul>
                  </div>
                );
              })}

              <div className="mb-5 border-t border-brand-900 pt-4">
                <ul className="space-y-0.5">
                  {SECONDARY_LINKS.filter(allowed).map((item) => {
                    const Icon = item.icon;
                    return (
                      <li key={item.href}>
                        <Link
                          href={item.href}
                          className="flex items-center gap-3 rounded-lg px-3 py-3 text-[0.9375rem] font-medium text-chrome-muted transition hover:bg-chrome-900"
                        >
                          <Icon className="h-5 w-5 shrink-0" aria-hidden />
                          {item.label}
                        </Link>
                      </li>
                    );
                  })}
                </ul>
              </div>
            </nav>
            <UserMenu />
          </div>
        </div>
      ) : null}

      {/* Screen-reader context for who and where. */}
      <span className="sr-only" aria-live="polite">
        {user ? `Signed in as ${user.name}` : ""}
        {project ? ` on project ${project.name}` : ""}
      </span>
    </div>
  );
}

function SidebarBrand({ compact }: { compact?: boolean }) {
  return (
    <Link href="/" className={cn("flex items-center gap-3", compact ? "" : "px-5 py-5")}>
      <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-accent-500 text-base font-bold text-white">
        S
      </span>
      <span className="leading-tight">
        <span className="block text-[1.0625rem] font-semibold tracking-tight text-white">SASA</span>
        <span className="block text-[0.6875rem] tracking-wide text-chrome-muted">Stakeholder Intelligence</span>
      </span>
    </Link>
  );
}

function ProjectSwitcher() {
  const { project, projects, switchProject } = useSession();
  const [open, setOpen] = useState(false);

  if (!project) return null;

  return (
    <div className="relative px-3 pb-4">
      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        aria-expanded={open}
        className="flex w-full items-center gap-3 rounded-lg border border-chrome-line bg-chrome-raised px-3 py-2.5 text-left transition hover:border-chrome-accent/45"
      >
        <span className="min-w-0 flex-1">
          <span className="block text-[0.6875rem] uppercase tracking-[0.08em] text-chrome-subtle">Project</span>
          <span className="block truncate text-sm font-medium text-white">{project.name}</span>
          <span className="block truncate text-xs text-chrome-muted">{project.role.name}</span>
        </span>
        <ChevronDown className={cn("h-4 w-4 shrink-0 text-chrome-muted transition", open && "rotate-180")} aria-hidden />
      </button>

      {open ? (
        <>
          <div className="fixed inset-0 z-40" onClick={() => setOpen(false)} aria-hidden />
          <div className="animate-fade-up absolute left-3 right-3 z-50 mt-1 overflow-hidden rounded-lg border border-hairline bg-surface p-1.5 shadow-[var(--shadow-overlay)]">
            <p className="px-2.5 py-1.5 text-[0.6875rem] font-semibold uppercase tracking-[0.08em] text-ink-500">
              Switch project
            </p>
            {projects.map((candidate) => (
              <button
                key={candidate.id}
                type="button"
                onClick={() => {
                  void switchProject(candidate.id);
                  setOpen(false);
                }}
                className="flex w-full items-start gap-2 rounded-md px-2.5 py-2 text-left transition hover:bg-brand-50"
              >
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-sm font-medium text-ink-900">{candidate.name}</span>
                  <span className="block truncate text-xs text-ink-500">
                    {candidate.code} · {candidate.role.name}
                  </span>
                </span>
                {candidate.id === project.id ? (
                  <Check className="mt-0.5 h-4 w-4 shrink-0 text-brand-600" aria-hidden />
                ) : null}
              </button>
            ))}
          </div>
        </>
      ) : null}
    </div>
  );
}

function MobileProjectLabel() {
  const { project } = useSession();

  if (!project) {
    return <span className="text-sm font-semibold text-ink-900">SASA</span>;
  }

  return (
    <Link href="/projects" className="block min-w-0 leading-tight">
      <span className="block truncate text-sm font-semibold text-ink-900">{project.name}</span>
      <span className="block truncate text-[0.6875rem] text-ink-500">{project.role.name}</span>
    </Link>
  );
}

function UserMenu() {
  const { user, signOut } = useSession();
  const [open, setOpen] = useState(false);

  if (!user) return null;

  return (
    <div className="relative border-t border-brand-900 p-3">
      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        aria-expanded={open}
        className="flex w-full items-center gap-3 rounded-lg px-2 py-2 text-left transition hover:bg-chrome-raised"
      >
        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-on-primary">
          {initials(user.name)}
        </span>
        <span className="min-w-0 flex-1">
          <span className="block truncate text-sm font-medium text-white">{user.name}</span>
          <span className="block truncate text-xs text-chrome-muted">{user.job_title ?? user.email}</span>
        </span>
        <ChevronDown className={cn("h-4 w-4 shrink-0 text-chrome-muted transition", open && "rotate-180")} aria-hidden />
      </button>

      {open ? (
        <>
          <div className="fixed inset-0 z-40" onClick={() => setOpen(false)} aria-hidden />
          <div className="animate-fade-up absolute bottom-full left-3 right-3 z-50 mb-1 overflow-hidden rounded-lg border border-hairline bg-surface p-1.5 shadow-[var(--shadow-overlay)]">
            <Link href="/account" className="block rounded-md px-2.5 py-2 text-sm text-ink-800 transition hover:bg-ink-100">
              My account
            </Link>
            <Link href="/projects" className="block rounded-md px-2.5 py-2 text-sm text-ink-800 transition hover:bg-ink-100">
              My projects
            </Link>
            <button
              type="button"
              onClick={() => void signOut()}
              className="flex w-full items-center gap-2 rounded-md px-2.5 py-2 text-left text-sm text-danger-600 transition hover:bg-danger-50"
            >
              <LogOut className="h-4 w-4" aria-hidden />
              Sign out
            </button>
          </div>
        </>
      ) : null}
    </div>
  );
}

/**
 * Bottom navigation with a central action button — the pattern a field officer
 * can drive one-handed. Never a shrunken desktop sidebar.
 */
function MobileNav({ quickActionHref }: { quickActionHref?: string }) {
  const pathname = usePathname();
  const router = useRouter();
  const { canAny } = useSession();

  const items = MOBILE_NAVIGATION.filter((item) => !item.permissions?.length || canAny(...item.permissions));
  const left = items.slice(0, 2);
  const right = items.slice(2, 4);

  return (
    <nav
      className="fixed inset-x-0 bottom-0 z-40 border-t border-hairline bg-surface/95 pb-[env(safe-area-inset-bottom)] backdrop-blur lg:hidden"
      aria-label="Primary"
    >
      <div className="grid grid-cols-5 items-end">
        {left.map((item) => (
          <MobileNavLink key={item.href} item={item} active={isActivePath(pathname, item.href)} />
        ))}

        <div className="flex justify-center">
          {quickActionHref ? (
            <button
              type="button"
              onClick={() => router.push(quickActionHref)}
              aria-label="New record"
              className="-mt-5 flex h-14 w-14 items-center justify-center rounded-full bg-accent-solid text-on-primary shadow-[var(--shadow-raised)] transition hover:bg-accent-solid-hover active:scale-95"
            >
              <Plus className="h-6 w-6" aria-hidden />
            </button>
          ) : null}
        </div>

        {right.map((item) => (
          <MobileNavLink key={item.href} item={item} active={isActivePath(pathname, item.href)} />
        ))}
      </div>
    </nav>
  );
}

function MobileNavLink({ item, active }: { item: NavItem; active: boolean }) {
  const Icon = item.icon;

  return (
    <Link
      href={item.href}
      aria-current={active ? "page" : undefined}
      className={cn(
        "flex min-h-[3.75rem] flex-col items-center justify-center gap-1 px-1 py-2 text-[0.6875rem] font-medium transition",
        active ? "text-brand-700" : "text-ink-500",
      )}
    >
      <Icon className={cn("h-5 w-5", active && "text-brand-600")} aria-hidden />
      {item.label}
    </Link>
  );
}
