"use client";

import { PapProfileForm } from "@/components/pap/PapProfileForm";
import { PageHeader } from "@/components/ui/DetailLayout";
import { PermissionDenied } from "@/components/ui/States";
import { useSession } from "@/providers/SessionProvider";

export default function NewPapPage() {
  const { can } = useSession();

  if (!can("pap.manage")) return <PermissionDenied what="adding PAPs" />;

  return (
    <div className="mx-auto max-w-3xl">
      <PageHeader
        backHref="/paps"
        eyebrow="PAP master register · Part A"
        title="Add a PAP"
        description="The profile of a Project Affected Person. Saving it also adds them to the stakeholder register as a PAP, so engagement and grievances can link to them."
      />
      <PapProfileForm pap={null} />
    </div>
  );
}
