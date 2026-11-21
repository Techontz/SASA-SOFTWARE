"use client";

import { Eye, EyeOff, Lock } from "lucide-react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useState, type FormEvent } from "react";
import { Button } from "@/components/ui/Button";
import { Field, Input } from "@/components/ui/Form";
import { ApiRequestError, apiRequest } from "@/lib/api/client";
import { useSession } from "@/providers/SessionProvider";
import { LogoMark } from "./Brand";

const MIN_LENGTH = 10;

/**
 * Where an invitation or a reset e-mail leads: choose a password, then go
 * straight in. A demo invitation arrives with `welcome=1`.
 */
export function SetPasswordForm() {
  const params = useSearchParams();
  const router = useRouter();
  const { signIn } = useSession();
  const token = params.get("token") ?? "";
  const email = params.get("email") ?? "";
  const welcome = params.get("welcome") === "1";

  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const [show, setShow] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  if (!token || !email) {
    return (
      <div className="sasa-card w-full max-w-[26rem] p-8 text-center">
        <LogoMark className="mx-auto h-10 w-10" />
        <h1 className="mt-5 text-2xl font-semibold tracking-[-0.03em] text-ink-900">This link is incomplete</h1>
        <p className="mt-2 text-ink-600">Open the link from your e-mail again, or ask the SASA team for a new one.</p>
        <Link href="/sign-in" className="mt-6 inline-flex text-sm font-semibold text-brand-700 hover:underline">
          Go to sign in
        </Link>
      </div>
    );
  }

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setError(null);
    if (password.length < MIN_LENGTH) return setError(`Use at least ${MIN_LENGTH} characters.`);
    if (!/[a-z]/.test(password) || !/[A-Z]/.test(password) || !/\d/.test(password)) {
      return setError("Use upper and lower case letters and at least one number.");
    }
    if (password !== confirm) return setError("The two passwords do not match.");

    setBusy(true);
    try {
      await apiRequest("/auth/reset-password", {
        method: "POST",
        withoutProject: true,
        allowUnauthenticated: true,
        body: { token, email, password, password_confirmation: confirm },
      });
      await signIn(email, password);
      router.replace("/dashboard");
    } catch (problem) {
      const fields = problem instanceof ApiRequestError ? problem.validationErrors : undefined;
      setError(
        fields?.password?.[0] ??
          (problem instanceof ApiRequestError ? problem.message : "Your password could not be set. Please try again."),
      );
      setBusy(false);
    }
  };

  return (
    <form onSubmit={submit} noValidate className="sasa-card w-full max-w-[26rem] p-7 sm:p-8">
      <LogoMark className="h-10 w-10" />
      <h1 className="mt-5 text-[1.75rem] font-semibold leading-tight tracking-[-0.04em] text-ink-900">
        {welcome ? "Welcome to your SASA demo" : "Choose a new password"}
      </h1>
      <p className="mt-1.5 text-[0.9375rem] text-ink-600">
        {welcome ? "Choose a password to open your workspace. " : "For "}
        <span className="font-medium text-ink-800">{email}</span>
      </p>

      <div className="mt-6 space-y-4">
        <Field label="Password" hint={`At least ${MIN_LENGTH} characters, with upper and lower case letters and a number.`} htmlFor="new-password">
          <div className="relative">
            <Lock className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-500" aria-hidden />
            <Input
              id="new-password"
              type={show ? "text" : "password"}
              autoComplete="new-password"
              className="h-11 pl-10 pr-12"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
            />
            <button
              type="button"
              onClick={() => setShow((value) => !value)}
              className="absolute right-2 top-1/2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-lg text-ink-500 hover:bg-ink-100"
              aria-label={show ? "Hide password" : "Show password"}
              aria-pressed={show}
            >
              {show ? <EyeOff className="h-4 w-4" aria-hidden /> : <Eye className="h-4 w-4" aria-hidden />}
            </button>
          </div>
        </Field>
        <Field label="Confirm password" htmlFor="confirm-password">
          <Input
            id="confirm-password"
            type={show ? "text" : "password"}
            autoComplete="new-password"
            className="h-11"
            value={confirm}
            onChange={(event) => setConfirm(event.target.value)}
          />
        </Field>
      </div>

      {error ? (
        <p className="mt-4 rounded-xl bg-danger-50 p-3 text-sm text-danger-700" role="alert">
          {error}
        </p>
      ) : null}

      <Button type="submit" variant="primary" size="lg" fullWidth loading={busy} className="mt-6 h-11">
        {welcome ? "Open my demo" : "Set password and sign in"}
      </Button>
    </form>
  );
}
