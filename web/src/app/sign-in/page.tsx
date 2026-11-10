"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import {
  Activity,
  AlertCircle,
  ArrowRight,
  ChartColumn,
  CircleCheck,
  Eye,
  EyeOff,
  FileBarChart,
  Handshake,
  Loader2,
  Lock,
  Mail,
  TriangleAlert,
  Users,
  WifiOff,
} from "lucide-react";
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
            : error.isUnreachable
              ? /* The device has signal, so this is not something the person
                   can fix by moving. Say so, rather than sending them outside
                   to look for a better connection. */
                "We could not reach the SASA server. Your connection is working, so this is a problem at the server end — please tell your administrator."
              : error.message,
        );
      } else {
        setFormError("We could not sign you in. Please try again.");
      }
    }
  });

  return (
    <div className="grid min-h-dvh bg-canvas lg:grid-cols-[minmax(0,1fr)_minmax(26rem,32rem)]">
      <StoryPanel />

      {/* --------------------------- the form ---------------------------- */}
      <main className="relative flex flex-col items-center justify-center px-4 py-12 sm:px-10">
        {/* Reachable before sign-in: someone on a bright site verandah should
            not have to authenticate before they can turn the lights up. */}
        <div className="absolute right-4 top-4">
          <ThemeToggle />
        </div>

        <div className="w-full max-w-sm">
          <div className="mb-6 flex items-center gap-3 lg:hidden">
            <BrandMark />
            <span className="text-lg font-semibold tracking-tight text-ink-900">SASA</span>
          </div>

          <div className="sasa-card px-6 py-7 sm:px-8 sm:py-8">
            <h1 className="text-2xl font-semibold tracking-tight text-ink-900">Welcome back</h1>
            <p className="mt-1.5 text-[0.9375rem] text-ink-600">Sign in to your SASA account.</p>

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
                <div className="relative">
                  <Mail className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-500" aria-hidden />
                  <Input
                    id="email"
                    type="email"
                    autoComplete="username"
                    autoCapitalize="none"
                    spellCheck={false}
                    placeholder="you@organisation.org"
                    className="pl-10"
                    invalid={Boolean(errors.email)}
                    {...register("email")}
                  />
                </div>
              </Field>

              <Field label="Password" error={errors.password?.message} htmlFor="password">
                <div className="relative">
                  <Lock className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-500" aria-hidden />
                  <Input
                    id="password"
                    type={showPassword ? "text" : "password"}
                    autoComplete="current-password"
                    placeholder="••••••••"
                    className="pl-10 pr-11"
                    invalid={Boolean(errors.password)}
                    {...register("password")}
                  />
                  <button
                    type="button"
                    onClick={() => setShowPassword((value) => !value)}
                    className="absolute right-1.5 top-1/2 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-md text-ink-500 transition hover:bg-ink-100 hover:text-ink-800"
                    aria-label={showPassword ? "Hide password" : "Show password"}
                    aria-pressed={showPassword}
                  >
                    {showPassword ? <EyeOff className="h-4 w-4" aria-hidden /> : <Eye className="h-4 w-4" aria-hidden />}
                  </button>
                </div>
              </Field>

              {formError ? (
                <div className="flex items-start gap-2.5 rounded-lg bg-danger-50 p-3.5 text-sm text-danger-700" role="alert">
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
                iconRight={isSubmitting ? undefined : <ArrowRight className="h-4 w-4" />}
              >
                {isSubmitting ? "Signing in" : "Sign in"}
              </Button>
            </form>

            <p className="mt-6 text-center text-sm text-ink-500">
              Forgotten your password? Ask your project administrator to reset it for you.
            </p>

            {loading ? (
              <p className="mt-6 flex items-center justify-center gap-2 text-sm text-ink-500">
                <Loader2 className="h-4 w-4 animate-spin" aria-hidden />
                Checking your session…
              </p>
            ) : null}
          </div>

          {process.env.NEXT_PUBLIC_SHOW_DEMO_LOGINS === "true" ? <DemoAccounts /> : null}

          <div className="mt-8 text-center">
            <p className="text-sm font-semibold tracking-tight text-ink-800">SASA</p>
            <p className="mt-2 text-[0.6875rem] uppercase tracking-[0.2em] text-ink-500">A more sustainable tomorrow</p>
          </div>
        </div>
      </main>
    </div>
  );
}

function BrandMark() {
  return (
    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-accent-solid text-lg font-bold text-on-primary">
      S
    </span>
  );
}

const HIGHLIGHTS = [
  { icon: WifiOff, title: "Works offline", detail: "in the field, syncs when the signal returns" },
  { icon: Activity, title: "Real-time", detail: "grievance management" },
  { icon: CircleCheck, title: "Commitment tracking", detail: "and closure verification" },
  { icon: FileBarChart, title: "Executive reporting", detail: "in minutes" },
];

const MODULES = [
  { icon: Users, title: "Register", detail: "Who the stakeholders are, ranked transparently" },
  { icon: Handshake, title: "Engagement", detail: "Planned against actual, on every entry" },
  { icon: TriangleAlert, title: "Grievances", detail: "Every channel, one case, one clock" },
  { icon: ChartColumn, title: "Reporting", detail: "Generated from live data in minutes" },
];

/** The dark story panel. Wide screens only — a phone goes straight to the form. */
function StoryPanel() {
  return (
    <aside className="relative hidden min-h-dvh flex-col overflow-hidden bg-chrome-deep px-10 py-8 lg:flex xl:px-14">
      <Landscape />

      <header className="relative flex items-center justify-between gap-6">
        <div className="flex items-center gap-3">
          <BrandMark />
          <span className="text-lg font-semibold tracking-tight text-white">SASA</span>
        </div>
        <p className="hidden text-[0.6875rem] uppercase tracking-[0.2em] text-chrome-muted xl:block">
          People <span className="px-1.5 text-chrome-subtle">|</span> Insights{" "}
          <span className="px-1.5 text-chrome-subtle">|</span> Action{" "}
          <span className="px-1.5 text-chrome-subtle">|</span> A more sustainable tomorrow
        </p>
      </header>

      <div className="relative my-auto grid items-center gap-10 py-10 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.05fr)]">
        <div className="max-w-xl">
          <h2 className="text-[2.25rem] font-semibold leading-[1.15] tracking-tight text-white xl:text-[2.5rem]">
            Turning stakeholder conversations into{" "}
            <span className="text-accent-300">actionable intelligence.</span>
          </h2>
          <p className="mt-5 max-w-md text-[0.9375rem] leading-relaxed text-chrome-fg">
            From stakeholder registration to grievance closure, manage every interaction in one trusted source of
            truth.
          </p>

          <ul className="mt-8 space-y-4">
            {HIGHLIGHTS.map(({ icon: Icon, title, detail }) => (
              <li key={title} className="flex items-center gap-3.5">
                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-chrome-line bg-chrome-raised text-accent-300">
                  <Icon className="h-[18px] w-[18px]" aria-hidden />
                </span>
                <span className="leading-tight">
                  <span className="block text-[0.9375rem] font-semibold text-white">{title}</span>
                  <span className="block text-sm text-chrome-muted">{detail}</span>
                </span>
              </li>
            ))}
          </ul>
        </div>

        <DashboardPreview />
      </div>

      <ul className="relative grid grid-cols-2 gap-3 2xl:grid-cols-4">
        {MODULES.map(({ icon: Icon, title, detail }) => (
          <li
            key={title}
            className="flex items-start gap-3 rounded-xl border border-chrome-line bg-chrome/80 p-4 backdrop-blur"
          >
            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-chrome-raised text-chrome-accent">
              <Icon className="h-[18px] w-[18px]" aria-hidden />
            </span>
            <span className="leading-snug">
              <span className="block text-sm font-semibold text-white">{title}</span>
              <span className="mt-0.5 block text-xs text-chrome-muted">{detail}</span>
            </span>
          </li>
        ))}
      </ul>
    </aside>
  );
}

/**
 * A drawing of the product, not a screenshot of anyone's data: shapes only, no
 * figures, so nobody reads a number off the sign-in page and believes it.
 */
function DashboardPreview() {
  const nav = ["Overview", "Stakeholders", "Engagements", "Grievances", "Commitments", "Reports"];

  return (
    <div
      className="hidden rotate-[-2deg] overflow-hidden rounded-2xl border border-chrome-line bg-chrome/90 shadow-[var(--shadow-overlay)] backdrop-blur xl:flex"
      aria-hidden
    >
      <div className="w-32 shrink-0 border-r border-chrome-line p-3">
        <div className="mb-4 flex items-center gap-2">
          <span className="flex h-5 w-5 items-center justify-center rounded bg-accent-solid text-[0.625rem] font-bold text-on-primary">
            S
          </span>
          <span className="text-xs font-semibold text-white">SASA</span>
        </div>
        <ul className="space-y-1">
          {nav.map((label, index) => (
            <li
              key={label}
              className={
                index === 0
                  ? "rounded bg-chrome-raised px-2 py-1 text-[0.625rem] text-chrome-fg"
                  : "px-2 py-1 text-[0.625rem] text-chrome-subtle"
              }
            >
              {label}
            </li>
          ))}
        </ul>
      </div>

      <div className="min-w-0 flex-1 space-y-3 p-4">
        <p className="text-xs font-semibold text-white">Project overview</p>

        <div className="grid grid-cols-4 gap-2">
          {[0, 1, 2, 3].map((index) => (
            <div key={index} className="rounded-lg border border-chrome-line bg-chrome-raised p-2">
              <div className="h-1.5 w-3/4 rounded bg-chrome-line" />
              <div className="mt-2 h-3 w-1/2 rounded bg-chrome-muted/60" />
            </div>
          ))}
        </div>

        <div className="grid grid-cols-[0.9fr_1.1fr] gap-2">
          <div className="flex items-center gap-3 rounded-lg border border-chrome-line bg-chrome-raised p-3">
            <svg viewBox="0 0 36 36" className="h-14 w-14 shrink-0 -rotate-90">
              <circle cx="18" cy="18" r="14" fill="none" stroke="var(--color-chrome-line)" strokeWidth="4" />
              <circle cx="18" cy="18" r="14" fill="none" stroke="var(--color-chrome-accent)" strokeWidth="4" strokeDasharray="62 88" strokeLinecap="round" />
              <circle cx="18" cy="18" r="14" fill="none" stroke="var(--color-accent-300)" strokeWidth="4" strokeDasharray="12 88" strokeDashoffset="-64" strokeLinecap="round" />
            </svg>
            <div className="w-full space-y-1.5">
              <div className="h-1.5 w-full rounded bg-chrome-line" />
              <div className="h-1.5 w-4/5 rounded bg-chrome-line" />
              <div className="h-1.5 w-3/5 rounded bg-chrome-line" />
            </div>
          </div>

          <div className="rounded-lg border border-chrome-line bg-chrome-raised p-3">
            <svg viewBox="0 0 120 48" className="h-14 w-full" preserveAspectRatio="none">
              <path
                d="M0 40 L15 34 L30 36 L45 26 L60 29 L75 18 L90 21 L105 10 L120 13 L120 48 L0 48 Z"
                fill="var(--color-chrome-accent)"
                opacity="0.15"
              />
              <path
                d="M0 40 L15 34 L30 36 L45 26 L60 29 L75 18 L90 21 L105 10 L120 13"
                fill="none"
                stroke="var(--color-chrome-accent)"
                strokeWidth="1.5"
              />
            </svg>
          </div>
        </div>

        <div className="rounded-lg border border-chrome-line bg-chrome-raised p-3">
          <div className="h-1.5 w-1/4 rounded bg-chrome-line" />
          <div className="relative mt-3 h-16">
            {[
              [18, 30],
              [34, 55],
              [52, 40],
              [61, 62],
              [72, 28],
              [84, 48],
            ].map(([left, top], index) => (
              <span
                key={index}
                className={
                  index % 2 === 0
                    ? "absolute h-2 w-2 rounded-full bg-chrome-accent"
                    : "absolute h-2 w-2 rounded-full bg-accent-300"
                }
                style={{ left: `${left}%`, top: `${top}%` }}
              />
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}

/** Hills behind the panel, drawn in the chrome tokens so they follow the theme. */
function Landscape() {
  return (
    <svg
      className="pointer-events-none absolute inset-x-0 bottom-0 h-[55%] w-full"
      viewBox="0 0 800 400"
      preserveAspectRatio="none"
      aria-hidden
    >
      <path d="M0 220 Q120 150 240 190 T480 150 T800 170 V400 H0 Z" fill="var(--color-chrome)" />
      <path d="M0 280 Q160 220 330 260 T640 230 T800 250 V400 H0 Z" fill="var(--color-chrome-raised)" opacity="0.7" />
      <path d="M0 330 Q200 300 400 320 T800 310 V400 H0 Z" fill="var(--color-chrome)" />
    </svg>
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
