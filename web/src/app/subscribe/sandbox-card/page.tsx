import type { Metadata } from "next";
import { Suspense } from "react";
import { LandingNav } from "@/components/marketing/LandingNav";
import { SandboxCard } from "@/components/marketing/PaymentResult";

export const metadata: Metadata = { title: "Card payment (test mode)", robots: { index: false } };

export default function SandboxCardPage() {
  return (
    <div id="top" className="min-h-dvh bg-canvas">
      <LandingNav solid />
      <main id="main" className="mx-auto w-full max-w-[32rem] px-4 pb-24 pt-32 sm:px-6 md:pt-40">
        <Suspense fallback={<div className="sasa-skeleton h-72 rounded-[1.5rem]" aria-hidden />}>
          <SandboxCard />
        </Suspense>
      </main>
    </div>
  );
}
