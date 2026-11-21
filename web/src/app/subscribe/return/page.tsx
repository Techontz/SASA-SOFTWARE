import type { Metadata } from "next";
import { Suspense } from "react";
import { LandingNav } from "@/components/marketing/LandingNav";
import { PaymentReturn } from "@/components/marketing/PaymentResult";

export const metadata: Metadata = { title: "Payment" };

export default function PaymentReturnPage() {
  return (
    <div id="top" className="min-h-dvh bg-canvas">
      <LandingNav solid />
      <main id="main" className="mx-auto w-full max-w-[36rem] px-4 pb-24 pt-32 sm:px-6 md:pt-40">
        <Suspense fallback={<div className="sasa-skeleton h-72 rounded-[1.5rem]" aria-hidden />}>
          <PaymentReturn />
        </Suspense>
      </main>
    </div>
  );
}
