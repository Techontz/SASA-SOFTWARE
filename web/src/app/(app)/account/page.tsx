"use client";

import { KeyRound, Save, ShieldCheck } from "lucide-react";
import { useState } from "react";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { ThemeChoiceGroup } from "@/components/ui/ThemeToggle";
import { PageHeader } from "@/components/ui/DetailLayout";
import { Checkbox, Field, FieldRow, Input, Select } from "@/components/ui/Form";
import { apiRequest } from "@/lib/api/client";
import { useSession } from "@/providers/SessionProvider";
import { useToast } from "@/providers/ToastProvider";

export default function AccountPage() {
  const { user, refresh, projects } = useSession();
  const toast = useToast();

  const [profile, setProfile] = useState({
    name: user?.name ?? "",
    phone: user?.phone ?? "",
    job_title: user?.job_title ?? "",
    locale: user?.locale ?? "en",
  });
  const [muted, setMuted] = useState(Boolean(user?.notification_preferences?.muted));
  const [passwords, setPasswords] = useState({ current_password: "", password: "", password_confirmation: "" });
  const [busy, setBusy] = useState(false);

  const saveProfile = async () => {
    setBusy(true);
    try {
      await apiRequest("/auth/profile", {
        method: "PATCH",
        withoutProject: true,
        body: { ...profile, notification_preferences: { ...(user?.notification_preferences ?? {}), muted } },
      });
      await refresh();
      toast.success("Saved");
    } catch (error) {
      toast.error("We could not save that", error instanceof Error ? error.message : undefined);
    } finally {
      setBusy(false);
    }
  };

  const changePassword = async () => {
    setBusy(true);
    try {
      await apiRequest("/auth/change-password", { method: "POST", withoutProject: true, body: passwords });
      toast.success("Password changed", "Every other device has been signed out.");
      setPasswords({ current_password: "", password: "", password_confirmation: "" });
    } catch (error) {
      toast.error("We could not change your password", error instanceof Error ? error.message : undefined);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="mx-auto max-w-3xl">
      <PageHeader title="My account" description="Your details, your projects and your notification preferences." />

      <div className="space-y-4">
        <Card>
          <CardHeader title="Your details" />
          <CardBody className="space-y-5">
            <FieldRow>
              <Field label="Name" htmlFor="name">
                <Input id="name" value={profile.name} onChange={(event) => setProfile((c) => ({ ...c, name: event.target.value }))} />
              </Field>
              <Field label="Job title" optional htmlFor="job_title">
                <Input id="job_title" value={profile.job_title} onChange={(event) => setProfile((c) => ({ ...c, job_title: event.target.value }))} />
              </Field>
            </FieldRow>
            <FieldRow>
              <Field label="Phone" optional htmlFor="phone">
                <Input id="phone" type="tel" value={profile.phone} onChange={(event) => setProfile((c) => ({ ...c, phone: event.target.value }))} />
              </Field>
              <Field label="Language" htmlFor="locale">
                <Select id="locale" value={profile.locale} onChange={(event) => setProfile((c) => ({ ...c, locale: event.target.value }))}>
                  <option value="en">English</option>
                  <option value="sw">Kiswahili</option>
                </Select>
              </Field>
            </FieldRow>
            <Field label="Email">
              <Input value={user?.email ?? ""} disabled />
            </Field>
            <Checkbox
              checked={muted}
              onChange={setMuted}
              label="Pause all notifications"
              description="You will still see everything in SASA — you just will not be emailed or alerted."
            />
            <Button variant="primary" loading={busy} icon={<Save className="h-4 w-4" />} onClick={() => void saveProfile()}>
              Save
            </Button>
          </CardBody>
        </Card>

        <Card>
          <CardHeader
            title="Appearance"
            description="Saved in this browser, so a shared office machine does not change what you see on your own."
          />
          <CardBody>
            <ThemeChoiceGroup />
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Your projects" description="Your role is per project, and so are your permissions." />
          <CardBody className="p-0 sm:p-0">
            <ul className="divide-y divide-hairline">
              {projects.map((project) => (
                <li key={project.id} className="flex flex-wrap items-center gap-3 px-5 py-3.5">
                  <div className="min-w-0 flex-1">
                    <p className="font-medium text-ink-900">{project.name}</p>
                    <p className="mt-0.5 text-sm text-ink-500">
                      {project.code} · {project.permissions.length} permissions
                    </p>
                  </div>
                  <span className="rounded-full bg-brand-50 px-2.5 py-1 text-xs font-medium text-brand-800">{project.role.name}</span>
                  {project.handling_groups.includes("restricted_handling") ? (
                    <span className="inline-flex items-center gap-1.5 rounded-full bg-danger-50 px-2.5 py-1 text-xs font-medium text-danger-700">
                      <ShieldCheck className="h-3 w-3" aria-hidden />
                      Restricted handling
                    </span>
                  ) : null}
                </li>
              ))}
            </ul>
          </CardBody>
        </Card>

        <Card>
          <CardHeader
            title="Change your password"
            description="Changing it signs out every other device you are using."
          />
          <CardBody className="space-y-5">
            <Field label="Current password" htmlFor="current_password">
              <Input
                id="current_password"
                type="password"
                autoComplete="current-password"
                value={passwords.current_password}
                onChange={(event) => setPasswords((c) => ({ ...c, current_password: event.target.value }))}
              />
            </Field>
            <FieldRow>
              <Field label="New password" htmlFor="password" hint="At least 10 characters, with upper and lower case and a number.">
                <Input
                  id="password"
                  type="password"
                  autoComplete="new-password"
                  value={passwords.password}
                  onChange={(event) => setPasswords((c) => ({ ...c, password: event.target.value }))}
                />
              </Field>
              <Field label="Confirm it" htmlFor="password_confirmation">
                <Input
                  id="password_confirmation"
                  type="password"
                  autoComplete="new-password"
                  value={passwords.password_confirmation}
                  onChange={(event) => setPasswords((c) => ({ ...c, password_confirmation: event.target.value }))}
                />
              </Field>
            </FieldRow>
            <Button variant="secondary" loading={busy} icon={<KeyRound className="h-4 w-4" />} onClick={() => void changePassword()}>
              Change my password
            </Button>
          </CardBody>
        </Card>
      </div>
    </div>
  );
}
