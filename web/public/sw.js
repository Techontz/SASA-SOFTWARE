/*
 * SASA service worker.
 *
 * Scope, deliberately narrow: the application shell and static assets.
 *
 * API responses are NEVER cached here. Grievance data — especially
 * confidential cases — belongs in IndexedDB, which the app clears on sign-out.
 * A shared field device must not leave one officer's cases readable to the
 * next person who picks it up, and an HTTP cache would do exactly that.
 */

const VERSION = "sasa-shell-v1";
const SHELL = [
  "/",
  "/offline",
  "/manifest.webmanifest",
  "/icon.svg",
];

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches
      .open(VERSION)
      .then((cache) => cache.addAll(SHELL).catch(() => undefined))
      .then(() => self.skipWaiting()),
  );
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) => Promise.all(keys.filter((key) => key !== VERSION).map((key) => caches.delete(key))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener("fetch", (event) => {
  const request = event.request;

  if (request.method !== "GET") return;

  const url = new URL(request.url);

  // Anything that is not our own origin, and anything under /api, is left
  // entirely alone.
  if (url.origin !== self.location.origin) return;
  if (url.pathname.startsWith("/api")) return;

  // Navigations: network first so the app is current, cache as the fallback so
  // it opens at all with no signal.
  if (request.mode === "navigate") {
    event.respondWith(
      fetch(request)
        .then((response) => {
          const copy = response.clone();
          caches.open(VERSION).then((cache) => cache.put(request, copy));
          return response;
        })
        .catch(async () => {
          const cached = await caches.match(request);
          return cached ?? (await caches.match("/offline")) ?? Response.error();
        }),
    );
    return;
  }

  // Static assets: cache first — they are content-hashed by the build.
  if (/\.(?:css|js|woff2?|png|jpg|jpeg|svg|webp|ico)$/.test(url.pathname) || url.pathname.startsWith("/_next/static")) {
    event.respondWith(
      caches.match(request).then((cached) => {
        if (cached) return cached;

        return fetch(request).then((response) => {
          if (response.ok) {
            const copy = response.clone();
            caches.open(VERSION).then((cache) => cache.put(request, copy));
          }
          return response;
        });
      }),
    );
  }
});

/* Let the page ask the browser to retry the queue when connectivity returns. */
self.addEventListener("message", (event) => {
  if (event.data === "sasa:skip-waiting") self.skipWaiting();
});
