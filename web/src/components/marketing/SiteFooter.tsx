import { ArrowUp } from "lucide-react";
import Link from "next/link";
import { cn } from "@/lib/utils";
import { Logo } from "./Brand";

const WRAP = "mx-auto w-full max-w-[1240px] px-4 sm:px-6 md:px-10";

export function SiteFooter() {
  return (
    <footer className="border-t border-chrome-line bg-chrome-deep text-chrome-muted">
      <div className={cn(WRAP, "grid gap-10 py-14 sm:grid-cols-2 lg:grid-cols-[1.6fr_1fr_1fr_1fr]")}>
        <div>
          <Logo onDark />
          <p className="mt-5 max-w-[22rem] text-sm leading-relaxed">
            Environmental and social performance, connected, for infrastructure and extractive projects.
          </p>
        </div>
        <nav aria-label="Footer">
          <p className="text-sm font-semibold text-white">Explore</p>
          <ul className="mt-4 space-y-2.5 text-sm">
            <li><Link href="/platform" className="hover:text-white">Platform</Link></li>
            <li><Link href="/industries" className="hover:text-white">Industries</Link></li>
            <li><Link href="/about" className="hover:text-white">About</Link></li>
            <li><Link href="/request-quote" className="hover:text-white">Request a quote</Link></li>
          </ul>
        </nav>
        <div>
          <p className="text-sm font-semibold text-white">Get in touch</p>
          <ul className="mt-4 space-y-2.5 text-sm">
            <li><Link href="/book-demo" className="hover:text-white">Book a demo</Link></li>
            <li><Link href="/register-interest" className="hover:text-white">Register interest</Link></li>
            <li><Link href="/sign-in" className="hover:text-white">Log in</Link></li>
          </ul>
        </div>
        <div>
          <p className="text-sm font-semibold text-white">Legal</p>
          <ul className="mt-4 space-y-2.5 text-sm">
            <li><Link href="/data-protection" className="hover:text-white">Data protection</Link></li>
            <li><Link href="/privacy" className="hover:text-white">Privacy and website terms</Link></li>
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
