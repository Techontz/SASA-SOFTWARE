"use client";

import { useQueryClient } from "@tanstack/react-query";
import { Mail, ShieldCheck, UserPlus } from "lucide-react";
import { useState } from "react";
import { Button } from "@/components/ui/Button";
import { Card, CardBody } from "@/components/ui/Card";
import { PageHeader } from "@/components/ui/DetailLayout";
import { Drawer } from "@/components/ui/Drawer";
import { Field, Input, Select } from "@/components/ui/Form";
import { StatusBadge } from "@/components/ui/StatusBadge";
import { LoadingState, PermissionDenied } from "@/components/ui/States";
import { apiRequest } from "@/lib/api/client";
import { useProjectMembers } from "@/lib/api/hooks";
import { formatRelative, initials } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";
import { useToast } from "@/providers/ToastProvider";

interface RoleRow {
  id: number;
  key: string;
  name: string;
  description: string | null;
  permissions: Array<{ id: number; key: string; name: string; group: string }>;
}

export default function PeoplePage() {
  const { can } = useSession();
  const toast = useToast();
  const queryClient = useQueryClient();
  const { data, isLoading } = useProjectMembers();

  const [inviteOpen, setInviteOpen] = useState(false);
  const [roles, setRoles] = useState<RoleRow[]>([]);
  const [form, setForm] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  if (!can("user.view")) return <PermissionDenied what="project members" />;

  const openInvite = async () => {
    if (roles.length === 0) {
      const response = await apiRequest<{ data: RoleRow[] }>("/configuration/roles");
      setRoles(response.data);
    }
    setInviteOpen(true);
  };

  const invite = async () => {
    setBusy(true);
    try {
      const response = await apiRequest<{ meta?: { message?: string } }>("/members", {
        method: "POST",
        body: {
          email: form.email,
          name: form.name,
          job_title: form.job_title,
          role_id: Number(form.role_id),
          handling_groups: form.handling_groups ? [form.handling_groups] : ["general"],
        },
      });

      await queryClient.invalidateQueries({ queryKey: ["project-members"] });
      toast.success("Added to the project", response.meta?.message);
      setInviteOpen(false);
      setForm({});
    } catch (error) {
      toast.error("We could not add them", error instanceof Error ? error.message : undefined);
    } finally {
      setBusy(false);
    }
  };

  if (isLoading) return <LoadingState />;

  const members = data?.data ?? [];

  return (
    <div>
      <PageHeader
        eyebrow="Administration"
        title="People on this project"
        description="A role is held per project. The same person can be a grievance officer here and read-only somewhere else."
        actions={
          can("user.manage") ? (
            <Button variant="accent" icon={<UserPlus className="h-4 w-4" />} onClick={() => void openInvite()}>
              Add someone
            </Button>
          ) : null
        }
      />

      <Card>
        <CardBody className="p-0 sm:p-0">
          <ul className="divide-y divide-hairline">
            {members.map((member) => (
              <li key={member.id} className="flex flex-wrap items-center gap-4 px-5 py-4">
                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-800">
                  {initials(member.user.name)}
                </span>
                <div className="min-w-0 flex-1">
                  <p className="font-medium text-ink-900">{member.user.name}</p>
                  <p className="mt-0.5 flex flex-wrap items-center gap-x-2 text-sm text-ink-500">
                    <Mail className="h-3.5 w-3.5" aria-hidden />
                    {member.user.email}
                    {member.user.job_title ? <span>· {member.user.job_title}</span> : null}
                  </p>
                </div>
                <span className="rounded-full bg-brand-50 px-2.5 py-1 text-xs font-medium text-brand-800">
                  {member.role.name}
                </span>
                {(member.handling_groups ?? []).includes("restricted_handling") ? (
                  <span className="inline-flex items-center gap-1.5 rounded-full bg-danger-50 px-2.5 py-1 text-xs font-medium text-danger-700">
                    <ShieldCheck className="h-3 w-3" aria-hidden />
                    Restricted handling
                  </span>
                ) : null}
                <StatusBadge status={member.user.status} size="sm" />
                <span className="w-32 shrink-0 text-right text-xs text-ink-500">
                  {member.user.last_login_at ? `Seen ${formatRelative(member.user.last_login_at)}` : "Never signed in"}
                </span>
              </li>
            ))}
          </ul>
        </CardBody>
      </Card>

      <Drawer
        open={inviteOpen}
        onClose={() => setInviteOpen(false)}
        title="Add someone to this project"
        description="They get a role on this project only. If they already have a SASA account, it is reused."
        width="md"
        footer={
          <>
            <Button variant="secondary" onClick={() => setInviteOpen(false)}>Cancel</Button>
            <Button variant="primary" loading={busy} disabled={!form.email || !form.role_id} onClick={() => void invite()}>
              Add to the project
            </Button>
          </>
        }
      >
        <div className="space-y-5">
          <Field label="Email address" required htmlFor="member-email">
            <Input
              id="member-email"
              type="email"
              autoCapitalize="none"
              value={form.email ?? ""}
              onChange={(event) => setForm((current) => ({ ...current, email: event.target.value }))}
            />
          </Field>
          <Field label="Name" required htmlFor="member-name">
            <Input
              id="member-name"
              value={form.name ?? ""}
              onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))}
            />
          </Field>
          <Field label="Job title" optional htmlFor="member-title">
            <Input
              id="member-title"
              value={form.job_title ?? ""}
              onChange={(event) => setForm((current) => ({ ...current, job_title: event.target.value }))}
            />
          </Field>
          <Field label="Role on this project" required htmlFor="member-role">
            <Select
              id="member-role"
              value={form.role_id ?? ""}
              onChange={(event) => setForm((current) => ({ ...current, role_id: event.target.value }))}
            >
              <option value="">Choose a role</option>
              {roles.map((role) => (
                <option key={role.id} value={role.id}>
                  {role.name}
                </option>
              ))}
            </Select>
          </Field>
          {form.role_id ? (
            <p className="rounded-lg bg-surface-sunken p-3 text-sm text-ink-600">
              {roles.find((role) => String(role.id) === form.role_id)?.description}
            </p>
          ) : null}
          <Field
            label="Handling group"
            optional
            htmlFor="member-handling"
            hint="Restricted categories such as SEA/SH and ethics are visible only to the group named here, whatever the role."
          >
            <Select
              id="member-handling"
              value={form.handling_groups ?? "general"}
              onChange={(event) => setForm((current) => ({ ...current, handling_groups: event.target.value }))}
            >
              <option value="general">General</option>
              <option value="restricted_handling">Restricted handling</option>
            </Select>
          </Field>
        </div>
      </Drawer>
    </div>
  );
}
