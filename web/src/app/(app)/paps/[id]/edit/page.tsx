"use client";

import { useParams } from "next/navigation";
import { PapProfileForm } from "@/components/pap/PapProfileForm";
import { PageHeader } from "@/components/ui/DetailLayout";
import { ErrorState, LoadingState, PermissionDenied } from "@/components/ui/States";
import { usePap } from "@/lib/api/pap";
import { useSession } from "@/providers/SessionProvider";

export default function EditPapPage() {
  const params = useParams<{ id: string }>();
  const id = Number(params.id);
  const { can } = useSession();
  const { data, isLoading, isError, error, refetch } = usePap(id);

  if (!can("pap.manage")) return <PermissionDenied what="editing PAPs" />;
  if (isLoading) return <LoadingState label="Opening the PAP" />;
  if (isError || !data) return <ErrorState error={error} onRetry={() => void refetch()} />;

  const pap = data.data;

  if (pap.archived_at) {
    return <ErrorState title="This PAP is archived" description="An archived record is read-only." />;
  }

  return (
    <div className="mx-auto max-w-3xl">
      <PageHeader
        backHref={`/paps/${id}`}
        eyebrow={`PAP master register · ${pap.reference}`}
        title={`Edit ${pap.full_name}`}
        description="Changes to name, contact, location and vulnerability flow into the PAP's stakeholder record when you save."
      />
      {/* Keyed so the form re-initialises if the record is refetched with new values. */}
      <PapProfileForm key={pap.updated_at ?? pap.id} pap={pap} />
    </div>
  );
}
