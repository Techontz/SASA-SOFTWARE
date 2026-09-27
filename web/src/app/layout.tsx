import type { Metadata, Viewport } from "next";
import { Inter } from "next/font/google";
import type { ReactNode } from "react";
import { QueryProvider } from "@/providers/QueryProvider";
import { SessionProvider } from "@/providers/SessionProvider";
import { SyncProvider } from "@/providers/SyncProvider";
import { ToastProvider } from "@/providers/ToastProvider";
import { ServiceWorkerRegistrar } from "@/components/app/ServiceWorkerRegistrar";
import { THEME_INIT_SCRIPT } from "@/lib/theme";
import "./globals.css";

const inter = Inter({
  subsets: ["latin"],
  variable: "--font-inter",
  display: "swap",
});

export const metadata: Metadata = {
  title: {
    default: "SASA — Stakeholder Intelligence & Voice",
    template: "%s · SASA",
  },
  description:
    "Stakeholder register, engagement planning and logging, commitments, grievance management with SLA and escalation, and reporting from live data.",
  applicationName: "SASA",
  manifest: "/manifest.webmanifest",
  appleWebApp: {
    capable: true,
    title: "SASA",
    statusBarStyle: "default",
  },
  formatDetection: { telephone: false },
  icons: {
    icon: [{ url: "/icon.svg", type: "image/svg+xml" }],
    apple: [{ url: "/apple-icon.png" }],
  },
};

export const viewport: Viewport = {
  /* Kept in step with the chosen theme at runtime by lib/theme.ts. */
  themeColor: "#0d1b2c",
  width: "device-width",
  initialScale: 1,
  viewportFit: "cover",
  /* Deliberately zoomable: some users need to enlarge the text. */
  maximumScale: 5,
};

export default function RootLayout({ children }: { children: ReactNode }) {
  return (
    /* The theme attribute is written by the script below, before the first
       paint, so the server's markup cannot match it — which is exactly what
       suppressHydrationWarning is for. It covers this element only. */
    <html lang="en" data-theme="light" className={inter.variable} suppressHydrationWarning>
      <head>
        {/* Inline and blocking on purpose: a deferred script would let the
            light theme paint first and then snap to dark. */}
        <script dangerouslySetInnerHTML={{ __html: THEME_INIT_SCRIPT }} />
      </head>
      <body>
        <a
          href="#main"
          className="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-[100] focus:rounded-md focus:bg-chrome focus:px-4 focus:py-2 focus:text-chrome-fg"
        >
          Skip to content
        </a>
        <QueryProvider>
          <ToastProvider>
            <SessionProvider>
              <SyncProvider>
                <div id="main">{children}</div>
                <ServiceWorkerRegistrar />
              </SyncProvider>
            </SessionProvider>
          </ToastProvider>
        </QueryProvider>
      </body>
    </html>
  );
}
