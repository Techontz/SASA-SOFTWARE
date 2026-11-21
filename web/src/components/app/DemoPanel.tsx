"use client";

import { Check, Copy, Mail, MessageCircle, MonitorPlay, Plus, Send, Square } from "lucide-react";
import { useState } from "react";
import { Button } from "@/components/ui/Button";
import { Checkbox, Field, Select } from "@/components/ui/Form";
import { StatusBadge } from "@/components/ui/StatusBadge";
import { EmptyState, LoadingState } from "@/components/ui/States";
import { ApiRequestError } from "@/lib/api/client";
import {
  DEMO_STATUS,
  useDemos,
  useEndDemo,
  useExtendDemo,
  useGiveDemo,
  useResendInvite,
  type Demo,
  type DemoStatus,
} from "@/lib/api/demos";
import type { Enquiry } from "@/lib/api/enquiries";
import { cn } from "@/lib/utils";
import { useToast } from "@/providers/ToastProvider";

const longDate = (iso: string) => new Date(iso).toLocaleDateString("en-GB", { day: "numeric", month: "long", year: "numeric" });
const tone = (status: DemoStatus) => (status === "active" ? "active" : status === "converted" ? "closed" : "archived");

function problemText(problem: unknown, fallback: string) {
  return problem instanceof ApiRequestError ? problem.message : fallback;
}

/* ------------------------------------------------------------ the link */

/** The sign-in link for the demo, ready to copy, WhatsApp or e-mail. */
export function InviteLink({ demo }: { demo: Demo }) {
  const [copied, setCopied] = useState(false);
  if (!demo.invite_url) return null;

  const ends = longDate(demo.ends_at);
  const message = `Hello ${demo.user.name}, your SASA demo for ${demo.organisation.name} is ready until ${ends}. Open this link to choose your password and start: ${demo.invite_url}`;

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(demo.invite_url ?? "");
      setCopied(true);
      window.setTimeout(() => setCopied(false), 2000);
    } catch {
      /* the field stays selectable */
    }
  };

  return (
    <div className="rounded-2xl bg-success-50 p-4 ring-1 ring-success-100">
      <p className="flex items-center gap-2 text-sm font-semibold text-success-800">
        <Check className="h-4 w-4" aria-hidden />
        {demo.email_sent ? `The sign-in link was e-mailed to ${demo.user.email}.` : "The demo is ready. Send this link to the company."}
      </p>
      <div className="mt-3 flex gap-2">
        <input
          readOnly
          value={demo.invite_url}
          onFocus={(event) => event.currentTarget.select()}
          className="sasa-field min-h-0 h-10 flex-1 text-xs"
          aria-label="Sign-in link"
        />
        <Button size="sm" variant="secondary" icon={copied ? <Check className="h-3.5 w-3.5" /> : <Copy className="h-3.5 w-3.5" />} onClick={copy}>
          {copied ? "Copied" : "Copy"}
        </Button>
      </div>
      <div className="mt-3 flex flex-wrap gap-2">
        <a
          href={`https://wa.me/?text=${encodeURIComponent(message)}`}
          target="_blank"
          rel="noopener noreferrer"
          className="inline-flex h-9 items-center gap-1.5 rounded-full bg-surface px-3.5 text-sm font-medium text-ink-800 ring-1 ring-hairline hover:bg-ink-100"
        >
          <MessageCircle className="h-4 w-4 text-success-700" aria-hidden /> Send on WhatsApp
        </a>
        <a
          href={`mailto:${demo.user.email}?subject=${encodeURIComponent("Your SASA demo is ready")}&body=${encodeURIComponent(message)}`}
          className="inline-flex h-9 items-center gap-1.5 rounded-full bg-surface px-3.5 text-sm font-medium text-ink-800 ring-1 ring-hairline hover:bg-ink-100"
        >
          <Mail className="h-4 w-4 text-brand-600" aria-hidden /> Send by e-mail
        </a>
      </div>
      <p className="mt-3 text-xs text-success-800">The link works once and expires after 3 days. You can send a new one at any time.</p>
    </div>
  );
}

/* ------------------------------------------------------------ actions */

function DemoActions({ demo, onLink }: { demo: Demo; onLink: (demo: Demo) => void }) {
  const toast = useToast();
  const extend = useExtendDemo();
  const end = useEndDemo();
  const resend = useResendInvite();
  const open = demo.status === "active" || demo.status === "expired";
  if (!open) return null;

  return (
    <div className="flex flex-wrap gap-2">
      <Button
        size="sm"
        variant="secondary"
        icon={<Plus className="h-3.5 w-3.5" />}
        loading={extend.isPending}
        onClick={() =>
          extend.mutate(
            { publicId: demo.public_id, days: 7 },
            {
              onSuccess: ({ data }) => toast.success(`The demo now runs until ${longDate(data.ends_at)}.`),
              onError: (problem) => toast.error(problemText(problem, "The demo could not be extended.")),
            },
          )
        }
      >
        Extend 7 days
      </Button>
      {demo.status === "active" ? (
        <Button
          size="sm"
          variant="secondary"
          icon={<Send className="h-3.5 w-3.5" />}
          loading={resend.isPending}
          onClick={() =>
            resend.mutate(demo.public_id, {
              onSuccess: ({ data }) => {
                onLink(data);
                toast.success("A new sign-in link is ready.");
              },
              onError: (problem) => toast.error(problemText(problem, "A new link could not be made.")),
            })
          }
        >
          New sign-in link
        </Button>
      ) : null}
      {demo.status === "active" ? (
        <Button
          size="sm"
          variant="ghost"
          icon={<Square className="h-3.5 w-3.5" />}
          loading={end.isPending}
          onClick={() => {
            if (!window.confirm(`End the demo for ${demo.organisation.name} now? Their records are kept.`)) return;
            end.mutate(demo.public_id, {
              onSuccess: () => toast.success("The demo has ended."),
              onError: (problem) => toast.error(problemText(problem, "The demo could not be ended.")),
            });
          }}
        >
          End demo
        </Button>
      ) : null}
    </div>
  );
}

/* ------------------------------------------------------------ in an enquiry */

/** Inside an enquiry: give a demo in one step, or manage the one running. */
export function DemoPanel({ enquiry }: { enquiry: Enquiry }) {
  const toast = useToast();
  const give = useGiveDemo();
  const [days, setDays] = useState("14");
  const [sample, setSample] = useState(true);
  const [created, setCreated] = useState<Demo | null>(null);
  const { data } = useDemos({});
  const current =
    created ?? (enquiry.demo ? (data?.data ?? []).find((item) => item.public_id === enquiry.demo?.public_id) ?? null : null);

  if (current) {
    return (
      <section className="space-y-4 rounded-2xl bg-surface-sunken p-5 ring-1 ring-hairline">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <p className="flex items-center gap-2 text-sm font-semibold text-ink-900">
            <MonitorPlay className="h-4 w-4 text-brand-600" aria-hidden /> Demo workspace
          </p>
          <StatusBadge status={tone(current.status)} label={DEMO_STATUS[current.status]} />
        </div>
        <p className="text-sm text-ink-600">
          {current.project.name} for {current.user.name} ({current.user.email}).{" "}
          {current.status === "active" ? `Runs until ${longDate(current.ends_at)}.` : `Ended ${longDate(current.ends_at)}.`}
        </p>
        <InviteLink demo={current} />
        <DemoActions demo={current} onLink={setCreated} />
      </section>
    );
  }

  const submit = () =>
    give.mutate(
      { enquiryId: enquiry.public_id, body: { days: Number(days), sample_data: sample } },
      {
        onSuccess: ({ data: demo }) => {
          setCreated(demo);
          toast.success(`A demo for ${demo.organisation.name} is ready.`);
        },
        onError: (problem) => toast.error(problemText(problem, "The demo could not be set up.")),
      },
    );

  return (
    <section className="space-y-4 rounded-2xl bg-surface-sunken p-5 ring-1 ring-hairline">
      <div>
        <p className="flex items-center gap-2 text-sm font-semibold text-ink-900">
          <MonitorPlay className="h-4 w-4 text-brand-600" aria-hidden /> Give a demo
        </p>
        <p className="mt-1 text-sm text-ink-600">
          Sets up a workspace for {enquiry.organization}, with {enquiry.full_name} as its administrator, and gives you a
          sign-in link to send.
        </p>
      </div>
      <div className="grid gap-4 sm:grid-cols-[10rem_1fr] sm:items-end">
        <Field label="Length" htmlFor="demo-days">
          <Select id="demo-days" value={days} onChange={(event) => setDays(event.target.value)}>
            <option value="7">7 days</option>
            <option value="14">14 days</option>
            <option value="30">30 days</option>
          </Select>
        </Field>
        <div className="pb-2.5">
          <Checkbox label="Fill it with sample records" checked={sample} onChange={setSample} />
        </div>
      </div>
      <Button variant="accent" icon={<MonitorPlay className="h-4 w-4" />} loading={give.isPending} onClick={submit}>
        Give a demo
      </Button>
    </section>
  );
}

/* ------------------------------------------------------------ all demos */

export function DemoList() {
  const [status, setStatus] = useState("");
  const [link, setLink] = useState<Demo | null>(null);
  const { data, isLoading } = useDemos({ status });
  const rows = data?.data ?? [];

  return (
    <div>
      <div className="mb-5 flex flex-wrap gap-2">
        {[
          { value: "", label: "All" },
          { value: "active", label: "Running" },
          { value: "expired", label: "Expired" },
          { value: "converted", label: "Became customers" },
        ].map((filter) => (
          <button
            key={filter.value}
            type="button"
            aria-pressed={status === filter.value}
            onClick={() => setStatus(filter.value)}
            className={cn(
              "rounded-full px-4 py-1.5 text-sm font-medium transition",
              status === filter.value ? "bg-ink-900 text-surface" : "bg-ink-100 text-ink-700 hover:bg-ink-200",
            )}
          >
            {filter.label}
          </button>
        ))}
      </div>

      {link ? (
        <div className="mb-5">
          <InviteLink demo={link} />
        </div>
      ) : null}

      {isLoading ? (
        <LoadingState label="Loading demos" />
      ) : rows.length === 0 ? (
        <EmptyState title="No demos yet" description="Open a demo request under Enquiries and choose Give a demo." />
      ) : (
        <ul className="sasa-card divide-y divide-hairline overflow-hidden">
          {rows.map((demo) => (
            <li key={demo.public_id} className="grid gap-3 p-5 sm:p-6 lg:grid-cols-[1.3fr_1fr_auto] lg:items-center">
              <div className="min-w-0">
                <p className="text-xs text-ink-500">{demo.enquiry?.reference ?? demo.project.code}</p>
                <p className="truncate font-semibold text-ink-900">{demo.organisation.name}</p>
                <p className="truncate text-sm text-ink-600">
                  {demo.user.name} · {demo.user.email}
                </p>
              </div>
              <div>
                <StatusBadge status={tone(demo.status)} label={DEMO_STATUS[demo.status]} />
                <p className="mt-1 text-xs text-ink-500">
                  {demo.status === "active" ? "Until" : "Since"} {longDate(demo.ends_at)}
                </p>
              </div>
              <DemoActions demo={demo} onLink={setLink} />
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
