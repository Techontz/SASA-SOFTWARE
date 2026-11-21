"use client";

import { CalendarClock, Mail, Phone, Receipt, Sparkles } from "lucide-react";
import { useState } from "react";
import { Button } from "@/components/ui/Button";
import { PageHeader } from "@/components/ui/DetailLayout";
import { Drawer } from "@/components/ui/Drawer";
import { Field, Select, Textarea } from "@/components/ui/Form";
import { StatusBadge } from "@/components/ui/StatusBadge";
import { EmptyState, LoadingState, PermissionDenied } from "@/components/ui/States";
import { ApiRequestError } from "@/lib/api/client";
import { useEnquiries, useUpdateEnquiry, type Enquiry, type EnquiryStatus, type EnquiryType } from "@/lib/api/enquiries";
import { PLATFORM_MODULES, SECTORS } from "@/lib/content/modules";
import { cn, formatRelative } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";
import { useToast } from "@/providers/ToastProvider";

const STATUS_LABEL: Record<EnquiryStatus, string> = { new: "New", contacted: "Contacted", closed: "Closed" };
const TYPE_LABEL: Record<EnquiryType, string> = { demo: "Demo request", quote: "Quote request", interest: "Interest registration" };

const MODULE_LABEL: Record<string, string> = {
  ...Object.fromEntries(PLATFORM_MODULES.map((module) => [module.key, module.name])),
  not_sure: "Not sure yet",
};

const SECTOR_LABEL: Record<string, string> = Object.fromEntries(SECTORS.map((sector) => [sector.value, sector.label]));

const TYPE_FILTERS = [
  { value: "", label: "All" },
  { value: "demo", label: "Demo requests" },
  { value: "quote", label: "Quote requests" },
  { value: "interest", label: "Interest" },
];

const STATUS_FILTERS = [
  { value: "", label: "Any status" },
  { value: "new", label: "New" },
  { value: "contacted", label: "Contacted" },
  { value: "closed", label: "Closed" },
];

export default function EnquiriesPage() {
  const { can } = useSession();
  const [type, setType] = useState("");
  const [status, setStatus] = useState("");
  const [open, setOpen] = useState<Enquiry | null>(null);
  const { data, isLoading } = useEnquiries({ type, status });
  const rows = data?.data ?? [];

  if (!can("enquiry.manage")) return <PermissionDenied what="website enquiries" />;

  return (
    <div>
      <PageHeader
        eyebrow="Administration"
        title="Enquiries"
        description="Demo requests, quote requests and interest registrations from the website, for your team to follow up."
      />

      <div className="mb-5 flex flex-wrap items-center gap-2">
        {TYPE_FILTERS.map((filter) => (
          <button
            key={filter.value}
            type="button"
            aria-pressed={type === filter.value}
            onClick={() => setType(filter.value)}
            className={cn(
              "rounded-full px-4 py-1.5 text-sm font-medium transition",
              type === filter.value ? "bg-ink-900 text-surface" : "bg-ink-100 text-ink-700 hover:bg-ink-200",
            )}
          >
            {filter.label}
          </button>
        ))}
        <label className="ml-auto">
          <span className="sr-only">Status</span>
          <Select value={status} onChange={(event) => setStatus(event.target.value)} className="h-10 min-h-0 py-1.5">
            {STATUS_FILTERS.map((filter) => (
              <option key={filter.value} value={filter.value}>
                {filter.label}
              </option>
            ))}
          </Select>
        </label>
      </div>

      {isLoading ? (
        <LoadingState label="Loading enquiries" />
      ) : rows.length === 0 ? (
        <EmptyState title="No enquiries yet" description="Forms sent from Register interest and Book a demo appear here." />
      ) : (
        <ul className="sasa-card divide-y divide-hairline overflow-hidden">
          {rows.map((row) => (
            <li key={row.public_id}>
              <button
                type="button"
                onClick={() => setOpen(row)}
                className="grid w-full gap-3 p-5 text-left transition-colors hover:bg-surface-sunken sm:p-6 lg:grid-cols-[1.4fr_1fr_auto] lg:items-center"
              >
                <span className="flex min-w-0 items-start gap-3.5">
                  <span
                    className={cn(
                      "grid h-10 w-10 shrink-0 place-items-center rounded-xl",
                      row.type === "demo" ? "bg-accent-50 text-accent-700" : row.type === "quote" ? "bg-success-50 text-success-700" : "bg-brand-50 text-brand-600",
                    )}
                  >
                    {row.type === "demo" ? (
                      <CalendarClock className="h-5 w-5" aria-hidden />
                    ) : row.type === "quote" ? (
                      <Receipt className="h-5 w-5" aria-hidden />
                    ) : (
                      <Sparkles className="h-5 w-5" aria-hidden />
                    )}
                  </span>
                  <span className="min-w-0">
                    <span className="block text-xs text-ink-500">
                      {row.reference} · {TYPE_LABEL[row.type]}
                    </span>
                    <span className="mt-0.5 block truncate font-semibold text-ink-900">{row.organization}</span>
                    <span className="block truncate text-sm text-ink-600">
                      {row.full_name} · {SECTOR_LABEL[row.sector] ?? row.sector}
                    </span>
                  </span>
                </span>
                <span className="truncate text-sm text-ink-600">
                  {row.modules?.length ? row.modules.map((key) => MODULE_LABEL[key] ?? key).join(", ") : "No modules chosen"}
                </span>
                <span className="flex items-center gap-3">
                  <StatusBadge status={row.status === "new" ? "open" : row.status === "contacted" ? "in_progress" : "closed"} label={STATUS_LABEL[row.status]} />
                  <span className="whitespace-nowrap text-xs text-ink-500">{formatRelative(row.created_at)}</span>
                </span>
              </button>
            </li>
          ))}
        </ul>
      )}

      {open ? <EnquiryDrawer enquiry={open} onClose={() => setOpen(null)} /> : null}
    </div>
  );
}

function EnquiryDrawer({ enquiry, onClose }: { enquiry: Enquiry; onClose: () => void }) {
  const toast = useToast();
  const update = useUpdateEnquiry();
  const [status, setStatus] = useState<EnquiryStatus>(enquiry.status === "new" ? "contacted" : enquiry.status);
  const [notes, setNotes] = useState(enquiry.admin_notes ?? "");

  const save = () =>
    update.mutate(
      { publicId: enquiry.public_id, status, admin_notes: notes.trim() || null },
      {
        onSuccess: () => {
          toast.success(`${enquiry.reference} is now ${STATUS_LABEL[status].toLowerCase()}.`);
          onClose();
        },
        onError: (error) => toast.error(error instanceof ApiRequestError ? error.message : "The change could not be saved."),
      },
    );

  return (
    <Drawer
      open
      onClose={onClose}
      title={enquiry.organization}
      description={`${enquiry.reference} · ${TYPE_LABEL[enquiry.type]}`}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button variant="primary" loading={update.isPending} onClick={save}>
            Save
          </Button>
        </div>
      }
    >
      <div className="space-y-6">
        <div className="space-y-2 text-sm">
          <p className="text-base font-semibold text-ink-900">{enquiry.full_name}</p>
          <a href={`mailto:${enquiry.email}`} className="flex items-center gap-2 text-brand-700 hover:underline">
            <Mail className="h-4 w-4" aria-hidden /> {enquiry.email}
          </a>
          {enquiry.phone ? (
            <a href={`tel:${enquiry.phone}`} className="flex items-center gap-2 text-brand-700 hover:underline">
              <Phone className="h-4 w-4" aria-hidden /> {enquiry.phone}
            </a>
          ) : null}
        </div>

        <dl className="grid grid-cols-2 gap-4 text-sm">
          <div>
            <dt className="text-ink-500">Sector</dt>
            <dd className="font-medium text-ink-900">{SECTOR_LABEL[enquiry.sector] ?? enquiry.sector}</dd>
          </div>
          <div>
            <dt className="text-ink-500">Received</dt>
            <dd className="font-medium text-ink-900">{formatRelative(enquiry.created_at)}</dd>
          </div>
          <div className="col-span-2">
            <dt className="text-ink-500">Modules of interest</dt>
            <dd className="font-medium text-ink-900">
              {enquiry.modules?.length ? enquiry.modules.map((key) => MODULE_LABEL[key] ?? key).join(", ") : "None chosen"}
            </dd>
          </div>
          {enquiry.details ? (
            <>
              <div>
                <dt className="text-ink-500">Company size</dt>
                <dd className="font-medium text-ink-900">{enquiry.details.company_size ?? "Not given"}</dd>
              </div>
              <div>
                <dt className="text-ink-500">Projects and users</dt>
                <dd className="font-medium text-ink-900">
                  {enquiry.details.projects_count ?? "?"} projects · {enquiry.details.users_count ?? "?"} users
                </dd>
              </div>
              <div>
                <dt className="text-ink-500">Location</dt>
                <dd className="font-medium text-ink-900">{enquiry.details.project_location || "Not given"}</dd>
              </div>
              <div>
                <dt className="text-ink-500">Stage and start</dt>
                <dd className="font-medium text-ink-900">
                  {[enquiry.details.project_stage, enquiry.details.start_timeframe?.replace(/_/g, " ")].filter(Boolean).join(" · ") ||
                    "Not given"}
                </dd>
              </div>
            </>
          ) : null}
          {enquiry.message ? (
            <div className="col-span-2">
              <dt className="text-ink-500">Message</dt>
              <dd className="whitespace-pre-line text-ink-800">{enquiry.message}</dd>
            </div>
          ) : null}
          {enquiry.handled_by ? (
            <div className="col-span-2">
              <dt className="text-ink-500">Last handled by</dt>
              <dd className="text-ink-800">
                {enquiry.handled_by.name}
                {enquiry.handled_at ? `, ${formatRelative(enquiry.handled_at)}` : ""}
              </dd>
            </div>
          ) : null}
        </dl>

        <Field label="Status" htmlFor="enquiry-status">
          <Select id="enquiry-status" value={status} onChange={(event) => setStatus(event.target.value as EnquiryStatus)}>
            {(Object.keys(STATUS_LABEL) as EnquiryStatus[]).map((value) => (
              <option key={value} value={value}>
                {STATUS_LABEL[value]}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Notes" optional hint="For your team only." htmlFor="enquiry-notes">
          <Textarea id="enquiry-notes" rows={4} value={notes} onChange={(event) => setNotes(event.target.value)} />
        </Field>
      </div>
    </Drawer>
  );
}
