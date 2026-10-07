import type { Page } from "@playwright/test";

export const ACCOUNTS = {
  admin: "admin@sasa.test",
  projectAdmin: "project.admin@sasa.test",
  executive: "executive@sasa.test",
  grievanceOfficer: "grievance@sasa.test",
  communityRelations: "cro@sasa.test",
  fieldOfficer: "field@sasa.test",
  auditor: "auditor@sasa.test",
} as const;

export async function signIn(page: Page, email: string, password = "password") {
  await page.goto("/sign-in");

  // Start from a clean session so each test is hermetic. Safe to call here:
  // the document is same-origin by this point.
  await page.evaluate(() => {
    localStorage.clear();
    sessionStorage.clear();
  });
  await page.goto("/sign-in");
  await page.fill("#email", email);
  await page.fill("#password", password);
  await page.click('button[type="submit"]');
  await page.waitForURL((url) => !url.pathname.startsWith("/sign-in"), { timeout: 30_000 });
  // The app shell header is the signal that the session is live, and it is the
  // one element visible on every viewport. `networkidle` is unreliable here:
  // a service worker and polling queries keep it busy.
  await page.waitForSelector("header", { state: "visible", timeout: 30_000 });
}

/**
 * Wait until the service worker is actually controlling the page.
 *
 * Registration is not the same as control, and waiting longer does not bridge
 * the gap: a document that finished loading before the worker activated is
 * never claimed retroactively, and no second activation is coming. So this
 * waits for an *active* worker, then reloads once if the current document is
 * still uncontrolled — after which the browser serves it through the worker
 * deterministically.
 *
 * Anything that warms the offline cache has to come after this, or its
 * navigations are not intercepted and nothing reaches the shell cache.
 */
export async function serviceWorkerReady(page: Page) {
  await page.waitForFunction(
    () => navigator.serviceWorker.getRegistration().then((r) => Boolean(r?.active)),
    undefined,
    { timeout: 30_000 },
  );

  const controlled = await page.evaluate(() => navigator.serviceWorker.controller !== null);

  if (!controlled) {
    await page.reload({ waitUntil: "domcontentloaded" });
    await page.waitForFunction(() => navigator.serviceWorker.controller !== null, undefined, {
      timeout: 30_000,
    });
  }
}

/** Wait for a screen to have finished its first data load. */
export async function settle(page: Page, ms = 900) {
  await page.waitForLoadState("domcontentloaded");
  await page.waitForTimeout(ms);
}

export async function signOut(page: Page) {
  await page.evaluate(() => {
    localStorage.clear();
    indexedDB.deleteDatabase("sasa");
  });
}

/** Reads the browser's own queue, which is what "saved on this device" means. */
export async function queuedOperationCount(page: Page): Promise<number> {
  return page.evaluate(
    () =>
      new Promise<number>((resolve) => {
        const request = indexedDB.open("sasa");

        request.onsuccess = () => {
          const database = request.result;

          if (!database.objectStoreNames.contains("operations")) {
            database.close();
            resolve(0);
            return;
          }

          const transaction = database.transaction("operations", "readonly");
          const countRequest = transaction.objectStore("operations").count();

          countRequest.onsuccess = () => {
            resolve(countRequest.result);
            database.close();
          };
          countRequest.onerror = () => {
            resolve(0);
            database.close();
          };
        };

        request.onerror = () => resolve(0);
      }),
  );
}

export function uniqueName(prefix: string): string {
  return `${prefix} ${Date.now().toString().slice(-6)}`;
}
