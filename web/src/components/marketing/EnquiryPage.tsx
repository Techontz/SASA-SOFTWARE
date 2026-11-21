import { Info } from "lucide-react";
import Link from "next/link";
import type { EnquiryType } from "@/lib/api/enquiries";
import { ContactCard } from "./ContactCard";
import { EnquiryForm } from "./EnquiryForm";
import { MarketingPage, WRAP } from "./MarketingPage";
import { Eyebrow } from "./Pill";
import { cn } from "@/lib/utils";

/** Register interest and Book a demo: the same form, two promises. */
export function EnquiryPage({
  type,
  eyebrow,
  title,
  intro,
  disclaimer,
}: {
  type: EnquiryType;
  eyebrow: string;
  title: string;
  intro: string;
  disclaimer: string;
}) {
  const other =
    type === "interest"
      ? { href: "/book-demo", label: "Book a demonstration instead" }
      : type === "demo"
        ? { href: "/register-interest", label: "Register your interest instead" }
        : { href: "/book-demo", label: "Prefer to see it first? Book a demonstration" };

  return (
    <MarketingPage>
      <section className={cn(WRAP, "grid gap-12 pb-24 pt-32 md:pt-40 lg:grid-cols-[0.85fr_1.15fr] lg:gap-16")}>
        <div className="lg:sticky lg:top-32 lg:self-start">
          <Eyebrow>{eyebrow}</Eyebrow>
          <h1 className="mt-4 text-[2.5rem] font-semibold leading-[1.04] tracking-[-0.045em] text-ink-900 md:text-[3.5rem]">
            {title}
          </h1>
          <p className="mt-6 text-[1.125rem] leading-[1.55] text-ink-600">{intro}</p>
          <p className="mt-6 flex items-start gap-3 rounded-2xl bg-surface p-4 text-sm leading-relaxed text-ink-700 ring-1 ring-hairline">
            <Info className="mt-0.5 h-4 w-4 shrink-0 text-brand-600" aria-hidden />
            {disclaimer}
          </p>
          <Link href={other.href} className="mt-6 inline-flex text-sm font-semibold text-brand-700 hover:underline">
            {other.label}
          </Link>
          <ContactCard />
        </div>
        <EnquiryForm type={type} />
      </section>
    </MarketingPage>
  );
}
