"use client";

import { Menu, X } from "lucide-react";
import Link from "next/link";
import { useEffect, useState } from "react";
import { ThemeToggle } from "@/components/ui/ThemeToggle";
import { cn } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";
import { Logo } from "./Brand";
import { Pill } from "./Pill";

const LINKS = [
  { href: "/#platform", label: "Platform" },
  { href: "/#how", label: "How it works" },
  { href: "/#product", label: "Product" },
  { href: "/#field", label: "In the field" },
  { href: "/pricing", label: "Pricing" },
];

/**
 * Clear over the hero film, a solid bar once the page scrolls. Someone already
 * signed in on this device is offered their dashboard instead of a sign-in.
 */
export function LandingNav({ solid: alwaysSolid = false }: { solid?: boolean } = {}) {
  const { user, loading } = useSession();
  const [scrolled, setScrolled] = useState(false);
  const [open, setOpen] = useState(false);

  useEffect(() => {
    const update = () => setScrolled(window.scrollY > 24);
    update();
    window.addEventListener("scroll", update, { passive: true });
    return () => window.removeEventListener("scroll", update);
  }, []);

  const signedIn = !loading && Boolean(user);
  const solid = alwaysSolid || scrolled || open;

  return (
    <header
      className={cn(
        "fixed inset-x-0 top-0 z-50 transition-colors duration-300",
        solid ? "border-b border-hairline bg-surface/95 backdrop-blur-xl" : "border-b border-transparent",
      )}
    >
      <nav className="mx-auto flex h-[4.5rem] max-w-[1240px] items-center gap-6 px-4 sm:px-6 md:px-10" aria-label="Main">
        <Link href="/" aria-label="SASA Compliance home" className="shrink-0">
          <Logo onDark={!solid} />
        </Link>

        <ul className="ml-8 hidden items-center gap-1 xl:flex">
          {LINKS.map((link) => (
            <li key={link.href}>
              <a
                href={link.href}
                className={cn(
                  "rounded-lg px-3 py-2 text-[0.9375rem] font-medium transition-colors",
                  solid ? "text-ink-700 hover:bg-surface-sunken hover:text-ink-900" : "text-chrome-fg/85 hover:text-white",
                )}
              >
                {link.label}
              </a>
            </li>
          ))}
        </ul>

        <div className="ml-auto flex items-center gap-2">
          <div className={cn("hidden sm:block", solid ? "" : "[&_button]:text-white")}>
            <ThemeToggle />
          </div>
          {signedIn ? (
            <Pill href="/dashboard" className="hidden h-10 px-4 sm:inline-flex">
              Open SASA
            </Pill>
          ) : (
            <>
              <Link
                href="/sign-in"
                className={cn(
                  "hidden rounded-lg px-4 py-2.5 text-[0.9375rem] font-semibold transition-colors sm:inline-flex",
                  solid ? "text-ink-900 hover:bg-surface-sunken" : "text-white hover:bg-chrome-fg/10",
                )}
              >
                Log in
              </Link>
              <Pill href="/pricing" className="hidden h-10 px-4 sm:inline-flex" arrow={false}>
                Get started
              </Pill>
            </>
          )}
          <button
            type="button"
            onClick={() => setOpen((value) => !value)}
            className={cn(
              "grid h-11 w-11 place-items-center rounded-lg ring-1 ring-inset xl:hidden",
              solid ? "text-ink-900 ring-hairline-strong" : "text-white ring-chrome-fg/40",
            )}
            aria-expanded={open}
            aria-controls="landing-menu"
            aria-label={open ? "Close the menu" : "Open the menu"}
          >
            {open ? <X className="h-5 w-5" aria-hidden /> : <Menu className="h-5 w-5" aria-hidden />}
          </button>
        </div>
      </nav>

      {open ? (
        <div id="landing-menu" className="max-h-[calc(100dvh-4.5rem)] overflow-y-auto border-t border-hairline px-4 pb-6 pt-2 sm:px-6 xl:hidden">
          <ul>
            {LINKS.map((link) => (
              <li key={link.href} className="border-b border-hairline">
                <a
                  href={link.href}
                  onClick={() => setOpen(false)}
                  className="block py-3.5 text-base font-medium text-ink-900"
                >
                  {link.label}
                </a>
              </li>
            ))}
          </ul>
          <div className="mt-5 grid gap-2.5">
            {signedIn ? (
              <Pill href="/dashboard">Open SASA</Pill>
            ) : (
              <>
                <Pill href="/pricing">Get started</Pill>
                <Pill href="/sign-in" tone="light" arrow={false}>
                  Log in
                </Pill>
              </>
            )}
            <div className="flex items-center justify-between pt-3 text-sm text-ink-600">
              Appearance
              <ThemeToggle />
            </div>
          </div>
        </div>
      ) : null}
    </header>
  );
}
