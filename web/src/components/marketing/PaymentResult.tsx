"use client";

import { CircleCheck, CircleX, CreditCard, Lock } from "lucide-react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useEffect, useState, type ReactNode } from "react";
import { Button } from "@/components/ui/Button";
import { Scanner } from "@/components/ui/States";
import { completeSandboxPayment, fetchPayment, formatMoney } from "@/lib/api/billing";
import type { Payment } from "@/types/billing";

/** Where the card checkout sends the person back to: it waits for the verdict. */
export function PaymentReturn() {
  const params = useSearchParams();
  const id = params.get("payment");
  const request = params.get("request");
  const [payment, setPayment] = useState<Payment | null>(null);
  const [missing, setMissing] = useState(false);

  useEffect(() => {
    if (!id) return;
    let stopped = false;
    const check = async () => {
      try {
        const { data } = await fetchPayment(id);
        if (stopped) return;
        setPayment(data);
        if (data.status === "pending") window.setTimeout(check, 3000);
      } catch {
        if (!stopped) setMissing(true);
      }
    };
    void check();
    return () => {
      stopped = true;
    };
  }, [id]);

  if (!id || missing) {
    return (
      <ResultCard
        tone="danger"
        title="We could not find that payment"
        body="If money left your account, keep your bank's reference and contact us. Otherwise you can start again."
        action={<Link href="/pricing" className="font-semibold text-brand-700 hover:underline">See the plans</Link>}
      />
    );
  }

  if (!payment || payment.status === "pending") {
    return (
      <div className="sasa-card flex flex-col items-center p-10 text-center" aria-live="polite">
        <Scanner label="Confirming your payment" size={56} />
        <h1 className="mt-8 text-2xl font-bold tracking-[-0.03em] text-ink-900">Confirming your payment</h1>
        <p className="mt-2 text-ink-600">This usually takes a few seconds.</p>
      </div>
    );
  }

  if (payment.status === "succeeded") {
    return (
      <ResultCard
        tone="success"
        title="Payment received"
        body={`Thank you. ${formatMoney(payment.amount, payment.currency)} was paid by card. Our team will set up your organisation and e-mail your sign-in.`}
        action={<Link href="/" className="font-semibold text-brand-700 hover:underline">Back to the home page</Link>}
      />
    );
  }

  return (
    <ResultCard
      tone="danger"
      title="The payment did not go through"
      body={payment.failure_reason ?? "Your card was not charged. You can try again, or pay by mobile money."}
      action={
        request ? (
          <Link href={`/subscribe?request=${request}`} className="font-semibold text-brand-700 hover:underline">
            Try again
          </Link>
        ) : (
          <Link href="/pricing" className="font-semibold text-brand-700 hover:underline">See the plans</Link>
        )
      }
    />
  );
}

function ResultCard({
  tone,
  title,
  body,
  action,
}: {
  tone: "success" | "danger";
  title: string;
  body: string;
  action: ReactNode;
}) {
  const Icon = tone === "success" ? CircleCheck : CircleX;
  return (
    <div className="sasa-card flex flex-col items-center p-10 text-center">
      <span
        className={
          tone === "success"
            ? "grid h-16 w-16 place-items-center rounded-full bg-success-50 text-success-700"
            : "grid h-16 w-16 place-items-center rounded-full bg-danger-50 text-danger-700"
        }
      >
        <Icon className="h-8 w-8" aria-hidden />
      </span>
      <h1 className="mt-6 text-2xl font-bold tracking-[-0.03em] text-ink-900">{title}</h1>
      <p className="mt-3 max-w-[26rem] text-ink-600">{body}</p>
      <div className="mt-8">{action}</div>
    </div>
  );
}

/**
 * Test mode only: stands in for the payment partner's card page, so the whole
 * journey can be tried before live keys exist. The API refuses it in live mode.
 */
export function SandboxCard() {
  const router = useRouter();
  const params = useSearchParams();
  const id = params.get("payment");
  const [payment, setPayment] = useState<Payment | null>(null);
  const [busy, setBusy] = useState<"succeeded" | "failed" | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!id) return;
    fetchPayment(id)
      .then(({ data }) => setPayment(data))
      .catch(() => setError("This test payment could not be found."));
  }, [id]);

  const finish = async (outcome: "succeeded" | "failed") => {
    if (!id) return;
    setBusy(outcome);
    try {
      await completeSandboxPayment(id, outcome);
      router.push(`/subscribe/return?payment=${id}`);
    } catch {
      setError("Test mode is switched off on this server.");
      setBusy(null);
    }
  };

  return (
    <div className="sasa-card p-8">
      <p className="inline-flex rounded-full bg-warning-50 px-3 py-1 text-xs font-semibold text-warning-700">Test mode</p>
      <h1 className="mt-4 flex items-center gap-3 text-2xl font-bold tracking-[-0.03em] text-ink-900">
        <CreditCard className="h-6 w-6 text-brand-700" aria-hidden /> Card payment
      </h1>
      <p className="mt-2 text-ink-600">
        This page stands in for the payment partner&apos;s secure card page while SASA runs in test mode. No card is
        charged.
      </p>
      {payment ? (
        <p className="mt-6 rounded-xl bg-surface-sunken p-4 text-lg font-semibold text-ink-900">
          {formatMoney(payment.amount, payment.currency)}
        </p>
      ) : null}
      {error ? (
        <p className="mt-6 rounded-xl bg-danger-50 p-3.5 text-sm text-danger-700" role="alert">
          {error}
        </p>
      ) : null}
      <div className="mt-8 grid gap-3 sm:grid-cols-2">
        <Button variant="accent" size="lg" loading={busy === "succeeded"} disabled={!payment || busy !== null} onClick={() => finish("succeeded")}>
          Approve payment
        </Button>
        <Button variant="secondary" size="lg" loading={busy === "failed"} disabled={!payment || busy !== null} onClick={() => finish("failed")}>
          Decline
        </Button>
      </div>
      <p className="mt-6 flex items-center gap-2 text-xs text-ink-500">
        <Lock className="h-3.5 w-3.5" aria-hidden /> In live mode this step happens on the payment partner&apos;s page.
      </p>
    </div>
  );
}
