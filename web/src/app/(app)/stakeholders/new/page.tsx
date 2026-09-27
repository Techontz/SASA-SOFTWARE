"use client";

import { StakeholderForm } from "@/components/app/StakeholderForm";
import { PageHeader } from "@/components/ui/DetailLayout";
import { PermissionDenied } from "@/components/ui/States";
import { useSession } from "@/providers/SessionProvider";

export default function NewStakeholderPage() {
  const { can } = useSession();

  if (!can("stakeholder.create")) {
    return <PermissionDenied what="adding stakeholders" />;
  }

  return (
    <div className="mx-auto max-w-4xl">
      <PageHeader
        backHref="/stakeholders"
        eyebrow="Module 1 · Register"
        title="Add a stakeholder"
        description="Only the name and the type are required. Everything else can be filled in later — a partial record in the register is worth more than a complete one in a notebook."
      />
      <StakeholderForm />
    </div>
  );
}
