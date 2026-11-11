"use client";

import { Building2, HardHat, Save, UserCog } from "lucide-react";
import { useState, type ReactNode } from "react";
import { Button } from "@/components/ui/Button";
import { PageHeader } from "@/components/ui/DetailLayout";
import { Field, FormActions, FormSection, Textarea } from "@/components/ui/Form";
import { ErrorState, LoadingState, PermissionDenied } from "@/components/ui/States";
import { useIncidentOptions, useUpdateIncidentLists } from "@/lib/api/incidents";
import { parseNamesList } from "@/lib/incidents";
import { useSession } from "@/providers/SessionProvider";
import { useSync } from "@/providers/SyncProvider";
import { useToast } from "@/providers/ToastProvider";
import type { IncidentOptions } from "@/types/incident";

type ListKey = "companies" | "contractor_managers" | "resident_engineers";

const LISTS: Array<{ key: ListKey; title: string; description: string; icon: ReactNode }> = [
  {
    key: "companies",
    title: "Companies",
    description: "The companies a reporter can choose on the form — the contractor, its subcontractors, the client.",
    icon: <Building2 className="h-4 w-4" aria-hidden />,
  },
  {
    key: "contractor_managers",
    title: "Contractor managers",
    description: "The contractor's managers an incident can be raised with.",
    icon: <HardHat className="h-4 w-4" aria-hidden />,
  },
  {
    key: "resident_engineers",
    title: "Consultant's resident engineers",
    description: "The supervision consultant's resident engineers on this project.",
    icon: <UserCog className="h-4 w-4" aria-hidden />,
  },
];

export default function IncidentSettingsPage() {
  const { can } = useSession();
  const { data, isLoading, isError, error, refetch } = useIncidentOptions();

  if (!can("configuration.manage")) return <PermissionDenied what="changing the incident lists" />;
  if (isLoading) return <LoadingState label="Loading the incident lists" />;
  if (isError || !data) return <ErrorState error={error} onRetry={() => void refetch()} />;

  return <ListsForm options={data.data} />;
}

function ListsForm({ options }: { options: IncidentOptions }) {
  const toast = useToast();
  const sync = useSync();
  const update = useUpdateIncidentLists();
  const [text, setText] = useState<Record<ListKey, string>>({
    companies: options.companies.join("\n"),
    contractor_managers: options.contractor_managers.join("\n"),
    resident_engineers: options.resident_engineers.join("\n"),
  });

  const save = async () => {
    try {
      const result = await update.mutateAsync({
        companies: parseNamesList(text.companies),
        contractor_managers: parseNamesList(text.contractor_managers),
        resident_engineers: parseNamesList(text.resident_engineers),
      });
      setText({
        companies: result.data.companies.join("\n"),
        contractor_managers: result.data.contractor_managers.join("\n"),
        resident_engineers: result.data.resident_engineers.join("\n"),
      });
      toast.success("Lists saved", "This change is recorded in the audit trail.");
    } catch (failure) {
      toast.error("The lists were not saved", failure instanceof Error ? failure.message : undefined);
    }
  };

  return (
    <div className="mx-auto max-w-3xl">
      <PageHeader
        backHref="/incidents"
        eyebrow="Incident management · configuration"
        title="Incident form lists"
        description="These dropdowns differ on every project. One name per line. Records already submitted keep the name they were filed with."
      />

      <div className="space-y-5 pb-6">
        {LISTS.map((list) => (
          <FormSection key={list.key} title={list.title} description={list.description} aside={list.icon}>
            <Field
              label={`${list.title} — one per line`}
              htmlFor={`list-${list.key}`}
              hint={`${parseNamesList(text[list.key]).length} on the list`}
            >
              <Textarea
                id={`list-${list.key}`}
                rows={5}
                value={text[list.key]}
                onChange={(event) => setText((current) => ({ ...current, [list.key]: event.target.value }))}
              />
            </Field>
          </FormSection>
        ))}

        <FormActions note={!sync.online ? "Offline — a connection is needed to save. Nothing has been saved." : undefined}>
          <Button variant="primary" loading={update.isPending} disabled={!sync.online} icon={<Save className="h-4 w-4" />} onClick={() => void save()}>
            Save lists
          </Button>
        </FormActions>
      </div>
    </div>
  );
}
