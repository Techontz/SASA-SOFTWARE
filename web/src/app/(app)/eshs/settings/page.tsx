"use client";

import { Save } from "lucide-react";
import { useState } from "react";
import { Button } from "@/components/ui/Button";
import { PageHeader } from "@/components/ui/DetailLayout";
import { Field, FormActions, FormSection, Textarea } from "@/components/ui/Form";
import { ErrorState, LoadingState, PermissionDenied } from "@/components/ui/States";
import { useEshsSettings, useUpdateEshsSettings } from "@/lib/api/eshs";
import { parseList } from "@/lib/eshs";
import { useSession } from "@/providers/SessionProvider";
import { useSync } from "@/providers/SyncProvider";
import { useToast } from "@/providers/ToastProvider";
import type { EshsSettings } from "@/types/eshs";

export default function EshsSettingsPage() {
  const { can } = useSession();
  const settings = useEshsSettings();

  if (!can("configuration.manage")) return <PermissionDenied what="the ESHS contractor lists" />;
  if (settings.isLoading) return <LoadingState label="Loading the lists" />;
  if (settings.isError || !settings.data) return <ErrorState error={settings.error} onRetry={() => void settings.refetch()} />;

  return <SettingsForm initial={settings.data.data} />;
}

function SettingsForm({ initial }: { initial: EshsSettings }) {
  const toast = useToast();
  const sync = useSync();
  const update = useUpdateEshsSettings();
  const [contractors, setContractors] = useState(initial.contractors.join("\n"));
  const [subcontractors, setSubcontractors] = useState(initial.subcontractors.join("\n"));

  const save = async () => {
    try {
      const result = await update.mutateAsync({
        contractors: parseList(contractors),
        subcontractors: parseList(subcontractors),
      });
      setContractors(result.data.contractors.join("\n"));
      setSubcontractors(result.data.subcontractors.join("\n"));
      toast.success("Lists saved", "Saved on the server. The change is recorded in the audit trail.");
    } catch (error) {
      toast.error("Nothing was saved", error instanceof Error ? error.message : undefined);
    }
  };

  return (
    <div className="mx-auto max-w-3xl">
      <PageHeader
        backHref="/eshs"
        eyebrow="ESHS contractor assurance"
        title="Contractor lists"
        description="The contractors and subcontractors on this project. They appear in the dropdowns when an anomaly is raised. Renaming a company here does not change anomalies already raised against the old name."
      />

      <div className="space-y-5 pb-6">
        <FormSection title="Contractors" description="One company per line.">
          <Field label="Contractors" htmlFor="eshs-contractors" hint="To limit a contractor's people to their own anomalies, set their contractor on the People page.">
            <Textarea id="eshs-contractors" rows={8} value={contractors} onChange={(event) => setContractors(event.target.value)} />
          </Field>
        </FormSection>

        <FormSection title="Subcontractors" description="One company per line.">
          <Field label="Subcontractors" htmlFor="eshs-subcontractors">
            <Textarea id="eshs-subcontractors" rows={6} value={subcontractors} onChange={(event) => setSubcontractors(event.target.value)} />
          </Field>
        </FormSection>

        <FormActions note={!sync.online ? "Offline — saving needs a connection. Nothing has been saved." : undefined}>
          <Button
            variant="primary"
            icon={<Save className="h-4 w-4" />}
            loading={update.isPending}
            disabled={!sync.online}
            onClick={() => void save()}
          >
            Save lists
          </Button>
        </FormActions>
      </div>
    </div>
  );
}
