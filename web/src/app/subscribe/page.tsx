import type { Metadata } from "next";
import { Suspense } from "react";
import { LandingNav } from "@/components/marketing/LandingNav";
import { SubscribeFlow } from "@/components/marketing/SubscribeFlow";

export const metadata: Metadata = {
  title: "Subscribe",
  description: "Subscribe to SASA Compliance and pay by mobile money or card.",
};

export default function SubscribePage() {
  return (
    <div id="top" className="min-h-dvh bg-canvas">
      <LandingNav solid />
      <main id="main" className="mx-auto w-full max-w-[1120px] px-4 pb-24 pt-28 sm:px-6 md:px-10 md:pt-36">
        <Suspense fallback={<div className="sasa-skeleton h-[36rem] rounded-[1.5rem]" aria-hidden />}>
          <SubscribeFlow />
        </Suspense>
      </main>
    </div>
  );
}
