import type { Metadata } from "next";
import { LandingNav } from "@/components/marketing/LandingNav";
import { Eyebrow } from "@/components/marketing/Pill";
import { PricingPlans } from "@/components/marketing/PricingPlans";
import { SiteFooter } from "@/components/marketing/SiteFooter";

export const metadata: Metadata = {
  title: "Pricing",
  description: "SASA Compliance plans in US dollars or Tanzanian shillings, paid by mobile money or card.",
};

export default function PricingPage() {
  return (
    <div id="top" className="min-h-dvh bg-canvas">
      <LandingNav solid />
      <main id="main" className="mx-auto w-full max-w-[1240px] px-4 pb-24 pt-32 sm:px-6 md:px-10 md:pt-40">
        <div className="mx-auto max-w-[44rem] text-center">
          <Eyebrow>Pricing</Eyebrow>
          <h1 className="mt-4 text-[2.5rem] font-bold leading-[1.04] tracking-[-0.04em] text-ink-900 md:text-[3.5rem]">
            Choose the plan that fits your project.
          </h1>
          <p className="mt-5 text-[1.0625rem] leading-relaxed text-ink-600 md:text-lg">
            See prices in US dollars or Tanzanian shillings, and pay in shillings by mobile money or card. Once the
            payment is confirmed, our team sets up your organisation and sends your administrator their sign-in.
          </p>
        </div>
        <div className="mt-12">
          <PricingPlans />
        </div>
        <p className="mx-auto mt-12 max-w-[40rem] text-center text-sm text-ink-500">
          Mobile money works with M-Pesa, Airtel Money, Mixx by Yas and Halotel: type your number and approve the
          prompt on your phone. Cards are paid on our payment partner&apos;s secure page; SASA never sees or stores your
          card number. Payments are processed by Snippe, in Tanzanian shillings.
        </p>
      </main>
      <SiteFooter />
    </div>
  );
}
