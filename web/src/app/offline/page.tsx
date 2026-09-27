import { WifiOff } from "lucide-react";
import Link from "next/link";

export const metadata = { title: "Offline" };

export default function OfflinePage() {
  return (
    <main className="flex min-h-dvh items-center justify-center px-6 py-16">
      <div className="max-w-md text-center">
        <span className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-warning-50 text-warning-600">
          <WifiOff className="h-7 w-7" aria-hidden />
        </span>
        <h1 className="mt-6 text-2xl font-semibold tracking-tight text-ink-900">You are offline</h1>
        <p className="mt-3 text-[0.9375rem] leading-relaxed text-ink-600">
          This part of SASA has not been opened on this device yet, so there is nothing stored to show you.
          Anything you have already entered is safe on the device and will sync by itself when the connection
          returns.
        </p>
        <Link
          href="/"
          className="mt-6 inline-flex h-11 items-center justify-center rounded-md bg-primary px-5 font-medium text-on-primary transition hover:bg-primary-hover"
        >
          Back to the dashboard
        </Link>
      </div>
    </main>
  );
}
