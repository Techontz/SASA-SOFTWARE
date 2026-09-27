"use client";

import { useRouter } from "next/navigation";
import { Scanner } from "@/components/ui/States";
import { useEffect, type ReactNode } from "react";
import { AppShell } from "@/components/app/AppShell";
import { useSession } from "@/providers/SessionProvider";

export default function AuthenticatedLayout({ children }: { children: ReactNode }) {
  const { user, loading, project } = useSession();
  const router = useRouter();

  useEffect(() => {
    if (!loading && !user) router.replace("/sign-in");
  }, [loading, router, user]);

  if (loading) {
    return (
      <div className="flex min-h-dvh flex-col items-center justify-center gap-4">
        <Scanner label="Opening SASA" size={48} on="canvas" />
        <span className="text-sm text-ink-500">Opening SASA…</span>
      </div>
    );
  }

  if (!user) return null;

  if (!project) {
    return (
      <div className="flex min-h-dvh items-center justify-center px-6">
        <div className="max-w-md text-center">
          <h1 className="text-xl font-semibold text-ink-900">You are not on a project yet</h1>
          <p className="mt-2 text-[0.9375rem] leading-relaxed text-ink-600">
            Your account exists, but nobody has added you to a project. Ask your project administrator to
            give you a role — SASA scopes everything to a project, so there is nothing to show until then.
          </p>
        </div>
      </div>
    );
  }

  return <AppShell>{children}</AppShell>;
}
