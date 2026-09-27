"use client";

import { useParams } from "next/navigation";
import { StakeholderForm } from "@/components/app/StakeholderForm";
import { PageHeader } from "@/components/ui/DetailLayout";
import { ErrorState, LoadingState, PermissionDenied } from "@/components/ui/States";
import { useStakeholder } from "@/lib/api/hooks";
import { useSession } from "@/providers/SessionProvider";

export default function EditStakeholderPage() {
  const params = useParams<{ id: string }>();
  const id = Number(params.id);
  const { can } = useSession();
  const { data, isLoading, isError, refetch } = useStakeholder(id);

  if (!can("stakeholder.update")) return <PermissionDenied what="editing stakeholders" />;
  if (isLoading) return <LoadingState label="Opening the record" />;
  if (isError || !data) return <ErrorState onRetry={() => void refetch()} />;

  return (
    <div className="mx-auto max-w-4xl">
      <PageHeader
        backHref={`/stakeholders/${id}`}
        eyebrow={`Module 1 · Register · ${data.data.reference}`}
        title={`Edit ${data.data.name}`}
        description="Changes are recorded in the audit trail with the previous value."
      />
      <StakeholderForm stakeholder={data.data} />
    </div>
  );
}
