"use client";

import { useEffect } from "react";

/**
 * Registers the app-shell service worker. The worker caches the shell and
 * static assets only — API responses containing case data are never written to
 * the HTTP cache, because a shared field device must not leave a confidential
 * grievance readable to the next person who picks it up. Field data lives in
 * IndexedDB, which is cleared on sign-out.
 */
export function ServiceWorkerRegistrar() {
  useEffect(() => {
    if (typeof window === "undefined" || !("serviceWorker" in navigator)) return;
    if (process.env.NODE_ENV !== "production") return;

    const register = () => {
      navigator.serviceWorker.register("/sw.js").catch(() => {
        // A failed registration only costs offline shell caching, never data.
      });
    };

    if (document.readyState === "complete") register();
    else window.addEventListener("load", register);

    return () => window.removeEventListener("load", register);
  }, []);

  return null;
}
