import { ArrowUp } from "lucide-react";
import Link from "next/link";
import { cn } from "@/lib/utils";
import { Logo } from "./Brand";

const WRAP = "mx-auto w-full max-w-[1240px] px-4 sm:px-6 md:px-10";

export function SiteFooter() {
  return (
    <footer className="border-t border-chrome-line bg-chrome-deep text-chrome-muted">
      <div className={cn(WRAP, "grid gap-10 py-14 md:grid-cols-[1.5fr_1fr_1fr]")}>
        <div>
          <Logo onDark />
          <p className="mt-5 max-w-[22rem] text-sm leading-relaxed">
            Stakeholder intelligence, engagement, grievance and reporting for infrastructure and extractive projects.
          </p>
        </div>
        <nav aria-label="Footer">
          <p className="text-sm font-semibold text-white">Explore</p>
          <ul className="mt-4 space-y-2.5 text-sm">
            <li><Link href="/#platform" className="hover:text-white">Platform</Link></li>
            <li><Link href="/#how" className="hover:text-white">How it works</Link></li>
            <li><Link href="/#product" className="hover:text-white">Product</Link></li>
            <li><Link href="/#field" className="hover:text-white">In the field</Link></li>
            <li><Link href="/#safeguards" className="hover:text-white">Safeguards</Link></li>
            <li><Link href="/pricing" className="hover:text-white">Pricing</Link></li>
          </ul>
        </nav>
        <div>
          <p className="text-sm font-semibold text-white">Your account</p>
          <ul className="mt-4 space-y-2.5 text-sm">
            <li><Link href="/sign-in" className="hover:text-white">Log in</Link></li>
            <li><Link href="/pricing" className="hover:text-white">Subscribe</Link></li>
          </ul>
        </div>
      </div>
      <div className="border-t border-chrome-line">
        <div className={cn(WRAP, "flex items-center justify-between gap-4 py-6 text-xs")}>
          <p>© {new Date().getFullYear()} SASA Compliance.</p>
          <a href="#top" className="flex items-center gap-1.5 hover:text-white">
            Back to top <ArrowUp className="h-3.5 w-3.5" aria-hidden />
          </a>
        </div>
      </div>
    </footer>
  );
}
