"use client";

import { Menu, X } from "lucide-react";
import Link from "next/link";
import { useEffect, useState } from "react";
import { ThemeToggle } from "@/components/ui/ThemeToggle";
import { cn } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";
import { BrandMark } from "./Brand";
import { Pill } from "./Pill";

const LINKS = [
  { href: "#platform", label: "Platform" },
  { href: "#how", label: "How it works" },
  { href: "#safeguards", label: "Safeguards" },
  { href: "#faq", label: "FAQ" },
];

/**
 * Transparent over the hero, glass once the page scrolls. Someone already
 * signed in on this device is offered their dashboard instead of a sign-in.
 */
export function LandingNav() {
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

  return (
    <header
      className={cn(
        "fixed inset-x-0 top-0 z-50 transition-colors duration-300",
        scrolled || open ? "border-b border-chrome-line bg-chrome-deep/85 backdrop-blur-xl" : "border-b border-transparent",
      )}
    >
      <nav className="mx-auto flex h-[4.25rem] max-w-[1240px] items-center gap-6 px-4 sm:px-6 md:px-10" aria-label="Main">
        <Link href="/" className="flex items-center gap-2.5" aria-label="SASA home">
          <BrandMark className="h-9 w-9 text-base" />
          <span className="text-lg font-semibold tracking-tight text-white">SASA</span>
        </Link>

        <ul className="ml-6 hidden items-center gap-1 lg:flex">
          {LINKS.map((link) => (
            <li key={link.href}>
              <a
                href={link.href}
                className="rounded-full px-3.5 py-2 text-[0.9375rem] text-chrome-fg/80 transition-colors hover:bg-chrome-raised/60 hover:text-white"
              >
                {link.label}
              </a>
            </li>
          ))}
        </ul>

        <div className="ml-auto flex items-center gap-2">
          <div className="hidden sm:block">
            <ThemeToggle />
          </div>
          {signedIn ? (
            <Pill href="/dashboard" className="hidden h-11 sm:inline-flex" arrow={false}>
              Open SASA
            </Pill>
          ) : (
            <>
              <Link
                href="/sign-in"
                className="hidden rounded-full px-4 py-2.5 text-[0.9375rem] font-medium text-white transition-colors hover:bg-chrome-raised/60 sm:inline-flex"
              >
                Log in
              </Link>
              <Pill href="/sign-in" className="hidden h-11 sm:inline-flex" arrow={false}>
                Get started
              </Pill>
            </>
          )}
          <button
            type="button"
            onClick={() => setOpen((value) => !value)}
            className="grid h-11 w-11 place-items-center rounded-full text-white ring-1 ring-chrome-line lg:hidden"
            aria-expanded={open}
            aria-controls="landing-menu"
            aria-label={open ? "Close the menu" : "Open the menu"}
          >
            {open ? <X className="h-5 w-5" aria-hidden /> : <Menu className="h-5 w-5" aria-hidden />}
          </button>
        </div>
      </nav>

      {open ? (
        <div id="landing-menu" className="border-t border-chrome-line px-4 pb-6 pt-2 sm:px-6 lg:hidden">
          <ul className="space-y-1">
            {LINKS.map((link) => (
              <li key={link.href}>
                <a
                  href={link.href}
                  onClick={() => setOpen(false)}
                  className="block rounded-lg px-3 py-3 text-base text-chrome-fg hover:bg-chrome-raised/60"
                >
                  {link.label}
                </a>
              </li>
            ))}
          </ul>
          <div className="mt-4 flex flex-col gap-2.5">
            {signedIn ? (
              <Pill href="/dashboard" className="justify-between">
                Open SASA
              </Pill>
            ) : (
              <>
                <Pill href="/sign-in" className="justify-between">
                  Get started
                </Pill>
                <Pill href="/sign-in" tone="glass" className="justify-between">
                  Log in
                </Pill>
              </>
            )}
            <div className="pt-2">
              <ThemeToggle />
            </div>
          </div>
        </div>
      ) : null}
    </header>
  );
}
