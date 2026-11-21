import type { Metadata } from "next";
import { Suspense } from "react";
import { SetPasswordForm } from "@/components/marketing/SetPasswordForm";

export const metadata: Metadata = { title: "Set your password", robots: { index: false } };

export default function SetPasswordPage() {
  return (
    <div className="grid min-h-dvh place-items-center bg-canvas px-4 py-12">
      <Suspense fallback={<div className="sasa-skeleton h-96 w-full max-w-[26rem] rounded-[1.75rem]" aria-hidden />}>
        <SetPasswordForm />
      </Suspense>
    </div>
  );
}
