"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import {
  AlertCircle,
  ArrowLeft,
  ArrowRight,
  Eye,
  EyeOff,
  Loader2,
  Lock,
  Mail,
  ScrollText,
  ShieldCheck,
  WifiOff,
} from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { ThemeToggle } from "@/components/ui/ThemeToggle";
import { Field, Input } from "@/components/ui/Form";
import { Button } from "@/components/ui/Button";
import { ApiRequestError } from "@/lib/api/client";
import { BackgroundVideo } from "@/components/marketing/BackgroundVideo";
import { Logo, LogoMark } from "@/components/marketing/Brand";
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
  const [showPassword, setShowPassword] = useState(false);

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
    if (!loading && user) router.replace("/dashboard");
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
      router.replace("/dashboard");
    } catch (error) {
      if (error instanceof ApiRequestError) {
        setFormError(
          error.isOffline
            ? "You need a connection the first time you sign in on this device. After that, SASA works offline."
            : error.isUnreachable
              ? /* The device has signal, so this is not something the person
                   can fix by moving. Say so, rather than sending them outside
                   to look for a better connection. */
                "We could not reach the SASA server. Your connection is working, so this is a problem at the server end. Please tell your administrator."
              : error.message,
        );
      } else {
        setFormError("We could not sign you in. Please try again.");
      }
    }
  });

  return (
    <div className="relative isolate flex h-dvh min-h-[34rem] flex-col overflow-hidden bg-chrome-deep text-white">
      <BackgroundVideo
        src="/landing/hero-film.mp4"
        srcSmall="/landing/hero-film-sm.mp4"
        poster="/landing/hero-film.webp"
        eager
        controlClassName="bottom-5 left-4 sm:left-6 md:left-10"
      />
      <div className="absolute inset-0 -z-10 bg-chrome-deep/50" aria-hidden />
      <div className="absolute inset-0 -z-10 bg-gradient-to-tr from-chrome-deep via-chrome-deep/40 to-chrome-deep/30" aria-hidden />

      <header className="mx-auto flex w-full max-w-[1240px] items-center justify-between gap-4 px-4 py-4 sm:px-6 md:px-10">
        <Link href="/" aria-label="SASA Compliance home">
          <Logo onDark />
        </Link>
        <div className="flex items-center gap-1 [&_button]:text-white">
          <Link
            href="/"
            className="hidden items-center gap-1.5 rounded-full px-3.5 py-2 text-sm font-medium text-chrome-fg transition-colors hover:bg-chrome-fg/10 sm:inline-flex"
          >
            <ArrowLeft className="h-4 w-4" aria-hidden />
            Back to home
          </Link>
          {/* Reachable before sign-in: someone on a bright site verandah should
              not have to authenticate before they can turn the lights up. */}
          <ThemeToggle />
        </div>
      </header>

      <div className="mx-auto grid w-full max-w-[1240px] min-h-0 flex-1 items-center gap-10 px-4 pb-6 sm:px-6 md:px-10 lg:grid-cols-[1fr_26rem] lg:gap-16">
        <div className="hidden max-w-[34rem] lg:block">
          <p className="text-[0.9375rem] font-medium text-chrome-fg">Stakeholder intelligence for infrastructure projects</p>
          <h2 className="mt-4 text-[3.5rem] font-semibold leading-[1] tracking-[-0.05em] text-white">
            Every stakeholder voice, <span className="text-accent-300">one trusted record.</span>
          </h2>
          <ul className="mt-10 space-y-3">
            {HIGHLIGHTS.map(({ icon: Icon, label }) => (
              <li key={label} className="flex items-center gap-3 text-[0.9375rem] text-chrome-fg">
                <span className="grid h-8 w-8 place-items-center rounded-full bg-chrome-fg/10 ring-1 ring-inset ring-chrome-fg/20 backdrop-blur-md">
                  <Icon className="h-4 w-4 text-chrome-accent" aria-hidden />
                </span>
                {label}
              </li>
            ))}
          </ul>
        </div>

        {/* --------------------------- the form: frosted glass ------------- */}
        <main className="sasa-signin-card mx-auto w-full max-w-[26rem] rounded-[1.75rem] p-6 text-ink-900 sm:p-8">
          <LogoMark className="h-10 w-10" />
          <h1 className="mt-4 text-[1.75rem] font-semibold leading-tight tracking-[-0.04em] text-ink-900">
            Welcome back
          </h1>
          <p className="mt-1 text-[0.9375rem] text-ink-600">Sign in to pick up where your team left off.</p>

          {!online ? (
            <div className="mt-4 flex items-start gap-2.5 rounded-xl bg-warning-50 p-3 text-sm text-warning-700">
              <WifiOff className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
              <p>
                You are offline. Signing in for the first time on a device needs a connection. After that, SASA opens
                and works without one.
              </p>
            </div>
          ) : null}

          <form onSubmit={onSubmit} className="mt-6 space-y-4" noValidate>
            <Field label="Email address" error={errors.email?.message} htmlFor="email">
              <div className="relative">
                <Mail className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-500" aria-hidden />
                <Input
                  id="email"
                  type="email"
                  autoComplete="username"
                  autoCapitalize="none"
                  spellCheck={false}
                  placeholder="you@organisation.org"
                  className="h-11 rounded-[0.625rem] pl-10"
                  invalid={Boolean(errors.email)}
                  {...register("email")}
                />
              </div>
            </Field>

            <Field label="Password" error={errors.password?.message} htmlFor="password">
              <div className="relative">
                <Lock className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-500" aria-hidden />
                <Input
                  id="password"
                  type={showPassword ? "text" : "password"}
                  autoComplete="current-password"
                  placeholder="••••••••"
                  className="h-11 rounded-[0.625rem] pl-10 pr-12"
                  invalid={Boolean(errors.password)}
                  {...register("password")}
                />
                <button
                  type="button"
                  onClick={() => setShowPassword((value) => !value)}
                  className="absolute right-2 top-1/2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-lg text-ink-500 transition hover:bg-ink-100 hover:text-ink-800"
                  aria-label={showPassword ? "Hide password" : "Show password"}
                  aria-pressed={showPassword}
                >
                  {showPassword ? <EyeOff className="h-4 w-4" aria-hidden /> : <Eye className="h-4 w-4" aria-hidden />}
                </button>
              </div>
            </Field>

            {formError ? (
              <div className="flex items-start gap-2.5 rounded-xl bg-danger-50 p-3.5 text-sm text-danger-700" role="alert">
                <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
                <p>{formError}</p>
              </div>
            ) : null}

            <Button
              type="submit"
              variant="primary"
              size="lg"
              fullWidth
              loading={isSubmitting}
              className="h-11 rounded-full"
              iconRight={isSubmitting ? undefined : <ArrowRight className="h-4 w-4" />}
            >
              {isSubmitting ? "Signing in" : "Sign in"}
            </Button>
          </form>

          <div className="mt-5 space-y-1.5 text-center text-sm text-ink-600">
            <p>Forgotten your password? Your project administrator can reset it.</p>
            <p>
              New to SASA?{" "}
              <Link href="/pricing" className="font-semibold text-brand-700 hover:underline">
                See the plans
              </Link>
            </p>
          </div>

          {loading ? (
            <p className="mt-4 flex items-center justify-center gap-2 text-sm text-ink-500">
              <Loader2 className="h-4 w-4 animate-spin" aria-hidden />
              Checking your session…
            </p>
          ) : null}

          {process.env.NEXT_PUBLIC_SHOW_DEMO_LOGINS === "true" ? <DemoAccounts /> : null}

        </main>
      </div>
    </div>
  );
}

const HIGHLIGHTS = [
  { icon: WifiOff, label: "Works offline in the field" },
  { icon: ShieldCheck, label: "Confidential by design" },
  { icon: ScrollText, label: "Every change audited" },
];

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
    <details className="mt-6 rounded-lg border border-hairline bg-surface-sunken p-4">
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
