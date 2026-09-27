"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { AlertCircle, Loader2, WifiOff } from "lucide-react";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { ThemeToggle } from "@/components/ui/ThemeToggle";
import { Field, Input } from "@/components/ui/Form";
import { Button } from "@/components/ui/Button";
import { ApiRequestError } from "@/lib/api/client";
import { useSession } from "@/providers/SessionProvider";

const schema = z.object({
  email: z.string().min(1, "Enter your email address.").email("That does not look like an email address."),
  password: z.string().min(1, "Enter your password."),
});

type FormValues = z.infer<typeof schema>;

export default function SignInPage() {
  const router = useRouter();
  const { signIn, user, loading } = useSession();
  const [formError, setFormError] = useState<string | null>(null);
  const [online, setOnline] = useState(true);

  useEffect(() => {
    const update = () => setOnline(navigator.onLine);
    update();
    window.addEventListener("online", update);
    window.addEventListener("offline", update);
    return () => {
      window.removeEventListener("online", update);
      window.removeEventListener("offline", update);
    };
  }, []);

  useEffect(() => {
    if (!loading && user) router.replace("/");
  }, [loading, router, user]);

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema) });

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null);

    try {
      await signIn(values.email, values.password);
      router.replace("/");
    } catch (error) {
      if (error instanceof ApiRequestError) {
        setFormError(
          error.isOffline
            ? "You need a connection the first time you sign in on this device. After that, SASA works offline."
            : error.message,
        );
      } else {
        setFormError("We could not sign you in. Please try again.");
      }
    }
  });

  return (
    <div className="grid min-h-dvh lg:grid-cols-[1.1fr_1fr]">
      {/* --------------------------- the story panel --------------------- */}
      <aside className="relative hidden flex-col justify-between overflow-hidden bg-chrome p-12 lg:flex">
        <div
          className="pointer-events-none absolute inset-0 opacity-[0.12]"
          style={{
            backgroundImage:
              "radial-gradient(circle at 20% 20%, #3b8290 0, transparent 45%), radial-gradient(circle at 80% 70%, #d67227 0, transparent 40%)",
          }}
          aria-hidden
        />

        <div className="relative flex items-center gap-3">
          <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-accent-500 text-lg font-bold text-white">
            S
          </span>
          <span className="leading-tight">
            <span className="block text-lg font-semibold tracking-tight text-white">SASA</span>
            <span className="block text-xs tracking-wide text-chrome-muted">Stakeholder Intelligence &amp; Voice</span>
          </span>
        </div>

        <div className="relative max-w-lg">
          <p className="text-[1.75rem] font-semibold leading-snug tracking-tight text-white">
            No commitment and no concern has room to go unheard.
          </p>
          <p className="mt-5 text-[0.9375rem] leading-relaxed text-chrome-fg">
            One chain of records, from the stakeholder register to the engagement, the concern it raised,
            the grievance it became, and the commitment that closed it, with the reporting to prove it.
          </p>

          <dl className="mt-10 grid grid-cols-2 gap-x-6 gap-y-6">
            {[
              ["Register", "Who the stakeholders are, ranked transparently"],
              ["Engagement", "Planned against actual, on every entry"],
              ["Grievances", "Every channel, one case, one clock"],
              ["Reporting", "Generated from live data in minutes"],
            ].map(([term, description]) => (
              <div key={term}>
                <dt className="text-sm font-semibold text-accent-300">{term}</dt>
                <dd className="mt-1 text-sm leading-relaxed text-chrome-muted">{description}</dd>
              </div>
            ))}
          </dl>
        </div>

        <p className="relative text-xs text-chrome-subtle">
          Works offline in the field. Your work is kept on the device and syncs when the signal returns.
        </p>
      </aside>

      {/* --------------------------- the form ---------------------------- */}
      <main className="relative flex items-center justify-center px-5 py-12 sm:px-10">
        {/* Reachable before sign-in: someone on a bright site verandah should
            not have to authenticate before they can turn the lights up. */}
        <div className="absolute right-4 top-4">
          <ThemeToggle />
        </div>
        <div className="w-full max-w-sm">
          <div className="mb-8 flex items-center gap-3 lg:hidden">
            <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-chrome text-lg font-bold text-chrome-fg">
              S
            </span>
            <span className="leading-tight">
              <span className="block text-lg font-semibold tracking-tight text-ink-900">SASA</span>
              <span className="block text-xs text-ink-500">Stakeholder Intelligence &amp; Voice</span>
            </span>
          </div>

          <h1 className="text-2xl font-semibold tracking-tight text-ink-900">Sign in</h1>
          <p className="mt-1.5 text-[0.9375rem] text-ink-600">
            Use the email address your project administrator set up for you.
          </p>

          {!online ? (
            <div className="mt-6 flex items-start gap-2.5 rounded-lg bg-warning-50 p-3.5 text-sm text-warning-700">
              <WifiOff className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
              <p>
                You are offline. Signing in for the first time on a device needs a connection — after that
                SASA opens and works without one.
              </p>
            </div>
          ) : null}

          <form onSubmit={onSubmit} className="mt-7 space-y-5" noValidate>
            <Field label="Email address" error={errors.email?.message} htmlFor="email">
              <Input
                id="email"
                type="email"
                autoComplete="username"
                autoCapitalize="none"
                spellCheck={false}
                placeholder="you@organisation.org"
                invalid={Boolean(errors.email)}
                {...register("email")}
              />
            </Field>

            <Field label="Password" error={errors.password?.message} htmlFor="password">
              <Input
                id="password"
                type="password"
                autoComplete="current-password"
                placeholder="••••••••"
                invalid={Boolean(errors.password)}
                {...register("password")}
              />
            </Field>

            {formError ? (
              <div className="flex items-start gap-2.5 rounded-lg bg-danger-50 p-3.5 text-sm text-danger-700" role="alert">
                <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
                <p>{formError}</p>
              </div>
            ) : null}

            <Button type="submit" variant="primary" size="lg" fullWidth loading={isSubmitting}>
              {isSubmitting ? "Signing in" : "Sign in"}
            </Button>
          </form>

          <p className="mt-6 text-sm text-ink-500">
            Forgotten your password? Ask your project administrator to reset it for you.
          </p>

          {process.env.NEXT_PUBLIC_SHOW_DEMO_LOGINS === "true" ? <DemoAccounts /> : null}

          {loading ? (
            <p className="mt-6 flex items-center gap-2 text-sm text-ink-500">
              <Loader2 className="h-4 w-4 animate-spin" aria-hidden />
              Checking your session…
            </p>
          ) : null}
        </div>
      </main>
    </div>
  );
}

/** Shown only when the demo flag is set, so nobody ships this to production. */
function DemoAccounts() {
  const accounts = [
    ["Executive", "executive@sasa.test"],
    ["Grievance officer", "grievance@sasa.test"],
    ["Community relations", "cro@sasa.test"],
    ["Field officer", "field@sasa.test"],
    ["Auditor (read-only)", "auditor@sasa.test"],
  ];

  return (
    <details className="mt-8 rounded-lg border border-hairline bg-surface-sunken p-4">
      <summary className="cursor-pointer text-sm font-medium text-ink-700">Demo accounts</summary>
      <ul className="mt-3 space-y-1.5 text-sm">
        {accounts.map(([role, email]) => (
          <li key={email} className="flex justify-between gap-3">
            <span className="text-ink-600">{role}</span>
            <code className="text-xs text-ink-800">{email}</code>
          </li>
        ))}
      </ul>
      <p className="mt-3 text-xs text-ink-500">
        The password for every demo account is <code className="text-ink-700">password</code>.
      </p>
    </details>
  );
}
