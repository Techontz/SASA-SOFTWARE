"use client";

import { Bot, CheckCircle2, Mic, PhoneOff, ShieldCheck, Sparkles } from "lucide-react";
import Link from "next/link";
import { useState } from "react";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { PageHeader, Tabs } from "@/components/ui/DetailLayout";
import { ProgressMeter } from "@/components/charts/Charts";
import { StatusBadge } from "@/components/ui/StatusBadge";
import { EmptyState, LoadingState, PermissionDenied } from "@/components/ui/States";
import { useAiAccuracy, useAiStatus, useVoiceCalls } from "@/lib/api/hooks";
import { formatDateTime, humanise } from "@/lib/utils";
import { useSession } from "@/providers/SessionProvider";

export default function AiPage() {
  const { can } = useSession();

  if (!can("grievance.view")) return <PermissionDenied what="the AI and voice screens" />;

  return (
    <div>
      <PageHeader
        eyebrow="AI"
        title="AI and voice"
        description="The AI takes the call, transcribes it and proposes a classification. A person confirms it, and every finding, resolution and closure stays human."
      />

      <Tabs
        tabs={[
          { key: "boundary", label: "What the AI does", content: <AiBoundary /> },
          { key: "calls", label: "Voice calls", content: <VoiceCallList /> },
          ...(can("ai.review") ? [{ key: "accuracy", label: "Accuracy", content: <AiAccuracy /> }] : []),
        ]}
      />
    </div>
  );
}

function AiBoundary() {
  const { data, isLoading } = useAiStatus();

  if (isLoading) return <LoadingState />;

  const status = data?.data;

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Card>
        <CardHeader title="Where the AI's authority ends" description="Stated plainly, because this is the part that matters." />
        <CardBody className="space-y-4">
          <div className="rounded-lg border border-brand-200 bg-brand-50 p-4">
            <p className="flex items-center gap-2 font-semibold text-brand-900">
              <Sparkles className="h-4 w-4" aria-hidden />
              The AI may propose
            </p>
            <ul className="mt-2 space-y-1 text-sm text-brand-800">
              <li>· A category and subcategory</li>
              <li>· A severity level</li>
              <li>· A plain-language summary of a long account</li>
              <li>· Which team should probably own the case</li>
            </ul>
          </div>

          <div className="rounded-lg border border-danger-500/25 bg-danger-50 p-4">
            <p className="flex items-center gap-2 font-semibold text-danger-900">
              <ShieldCheck className="h-4 w-4" aria-hidden />
              The AI never owns
            </p>
            <ul className="mt-2 space-y-1 text-sm text-danger-800">
              {(status?.never_owns ?? []).map((item) => (
                <li key={item}>· {humanise(item)}</li>
              ))}
            </ul>
          </div>

          <p className="text-sm leading-relaxed text-ink-600">{status?.note}</p>
        </CardBody>
      </Card>

      <div className="space-y-4">
        <Card>
          <CardHeader title="Provider" description="SASA is written against an interface, not a vendor's SDK." />
          <CardBody>
            <dl className="space-y-3 text-sm">
              <div className="flex items-center justify-between gap-3">
                <dt className="text-ink-600">Classification provider</dt>
                <dd className="font-medium text-ink-900">{humanise(status?.provider)}</dd>
              </div>
              <div className="flex items-center justify-between gap-3">
                <dt className="text-ink-600">Available</dt>
                <dd>
                  <StatusBadge status={status?.available ? "active" : "inactive"} size="sm" />
                </dd>
              </div>
              <div className="flex items-center justify-between gap-3">
                <dt className="text-ink-600">Minimum confidence to suggest</dt>
                <dd className="tabular font-medium text-ink-900">
                  {status ? `${Math.round((status as unknown as { min_confidence?: number }).min_confidence ?? 0.35 * 100)}%` : "—"}
                </dd>
              </div>
            </dl>
            <p className="mt-4 rounded-lg bg-surface-sunken p-3 text-sm leading-relaxed text-ink-600">
              With no AI subscription configured, SASA falls back to a deterministic keyword classifier. It is
              deliberately modest about its confidence, and it still routes every proposal to a person.
            </p>
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Consent" description="Mandatory, and the one thing the caller decides." />
          <CardBody className="space-y-3 text-sm leading-relaxed text-ink-700">
            <p>
              The agent introduces itself and asks permission before anything is recorded. If the caller
              declines, SASA logs <strong>Consent Not Granted</strong>, routes them to a human call-back queue,
              and does <strong>not</strong> create a grievance.
            </p>
            <p className="rounded-lg bg-warning-50 p-3 text-warning-800">
              The two-minute limit in the source specification had no defined behaviour at the limit. SASA&apos;s
              default is a <strong>soft cap</strong>: the agent warns the caller, offers to continue or arrange a
              call-back, and flags every truncated call for human review. The cap and the behaviour are both
              configurable per project.
            </p>
          </CardBody>
        </Card>
      </div>
    </div>
  );
}

function VoiceCallList() {
  const [needsReview, setNeedsReview] = useState(false);
  const { data, isLoading } = useVoiceCalls(needsReview);

  const calls = data?.data.data ?? [];

  return (
    <Card>
      <CardHeader
        title="Calls to the grievance line"
        description="Every call, whether or not it became a case. A call where consent was refused is on the record as exactly that."
        action={
          <Button size="sm" variant={needsReview ? "primary" : "secondary"} onClick={() => setNeedsReview((value) => !value)}>
            {needsReview ? "Showing calls needing review" : "Only calls needing review"}
          </Button>
        }
      />
      <CardBody className="p-0 sm:p-0">
        {isLoading ? (
          <LoadingState />
        ) : calls.length === 0 ? (
          <EmptyState
            icon={Mic}
            title={needsReview ? "No calls need review" : "No calls yet"}
            description="When the voice line is connected to a telephony provider, calls appear here with their transcript and the case they produced."
          />
        ) : (
          <ul className="divide-y divide-hairline">
            {calls.map((call) => (
              <li key={call.id} className="px-5 py-4">
                <div className="flex flex-wrap items-start gap-3">
                  <span
                    className={
                      call.consent_granted
                        ? "mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700"
                        : "mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-warning-50 text-warning-700"
                    }
                  >
                    {call.consent_granted ? <Bot className="h-4 w-4" aria-hidden /> : <PhoneOff className="h-4 w-4" aria-hidden />}
                  </span>
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <StatusBadge status={call.status === "completed" ? "synced" : call.status} size="sm" />
                      {!call.consent_granted ? (
                        <span className="rounded-full bg-warning-50 px-2 py-0.5 text-xs font-medium text-warning-700">
                          Consent not granted — no case created
                        </span>
                      ) : null}
                      {call.was_truncated ? (
                        <span className="rounded-full bg-danger-50 px-2 py-0.5 text-xs font-medium text-danger-700">
                          Cut short — needs a person
                        </span>
                      ) : null}
                      {call.grievance ? (
                        <Link href={`/grievances/${call.grievance.id}`} className="font-mono text-xs text-brand-700 hover:underline">
                          {call.grievance.reference}
                        </Link>
                      ) : null}
                    </div>
                    {call.transcript ? (
                      <p className="mt-2 whitespace-pre-line rounded-lg bg-surface-sunken p-3 text-sm leading-relaxed text-ink-700">
                        {call.transcript}
                      </p>
                    ) : null}
                    <p className="mt-2 text-xs text-ink-500">
                      {formatDateTime(call.started_at)} · {call.duration_seconds}s ·{" "}
                      {call.language ? humanise(call.language) : "language not detected"} · via {call.provider}
                    </p>
                  </div>
                </div>
              </li>
            ))}
          </ul>
        )}
      </CardBody>
    </Card>
  );
}

function AiAccuracy() {
  const { data, isLoading } = useAiAccuracy();
  if (isLoading) return <LoadingState />;

  const accuracy = data?.data;

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Card>
        <CardHeader
          title="How often the AI is right"
          description="Measurable precisely because a proposal is stored apart from the confirmed value, and a person always decides."
        />
        <CardBody className="space-y-5">
          <ProgressMeter
            label="Accepted without change"
            value={accuracy?.acceptance_rate ?? null}
            caption={`${accuracy?.accepted ?? 0} of ${accuracy?.reviewed ?? 0} reviewed`}
            tone={
              accuracy?.acceptance_rate === null || accuracy?.acceptance_rate === undefined
                ? "brand"
                : accuracy.acceptance_rate >= 70
                  ? "success"
                  : "warning"
            }
          />

          <dl className="grid grid-cols-2 gap-3">
            {[
              ["Reviewed", accuracy?.reviewed],
              ["Accepted", accuracy?.accepted],
              ["Changed by a person", accuracy?.modified],
              ["Rejected", accuracy?.rejected],
              ["Waiting for review", accuracy?.pending],
            ].map(([label, value]) => (
              <div key={String(label)} className="rounded-lg border border-hairline p-3">
                <dt className="sasa-eyebrow">{label}</dt>
                <dd className="tabular mt-1 text-xl font-semibold text-ink-900">{(value as number) ?? 0}</dd>
              </div>
            ))}
          </dl>
        </CardBody>
      </Card>

      <Card>
        <CardHeader title="What to do with this" />
        <CardBody className="space-y-3 text-sm leading-relaxed text-ink-700">
          <p>
            A low acceptance rate is not a reason to stop using the AI — it is information. If officers keep
            changing the severity in the same direction, that usually means the severity descriptions on this
            project need editing, not that the model is broken.
          </p>
          <p>
            If it is consistently proposing the wrong category, check whether the category names describe what
            people actually complain about. Both are configuration, and both are yours to change.
          </p>
          <div className="flex items-center gap-2 rounded-lg bg-brand-50 p-3 text-brand-800">
            <CheckCircle2 className="h-4 w-4 shrink-0" aria-hidden />
            <span>A case is never classified by the AI alone. It reads &ldquo;AI suggested — confirm&rdquo; until somebody does.</span>
          </div>
        </CardBody>
      </Card>
    </div>
  );
}
