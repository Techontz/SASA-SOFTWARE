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

/**
 * Engagements and grievances cannot be saved without evidence. A small PDF is
 * enough to satisfy the form and exercises the real upload path.
 */
export async function attachEvidence(page: Page, name = "evidence.pdf") {
  await page.setInputFiles("#attachments", {
    name,
    mimeType: "application/pdf",
    buffer: Buffer.from("%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"),
  });
}

/**
 * Fills every mandatory field the test has left empty, so a test about the
 * engagement chain or offline sync is not also a test of which fields the
 * project made compulsory (that is RequiredFieldsTest's job on the API, and
 * the forms flag them on screen). Fields the test filled are left alone.
 *
 * A mandatory field is one whose label carries the red asterisk.
 */
export async function completeMandatoryFields(page: Page) {
  const fields = await page.evaluate(() =>
    [...document.querySelectorAll("label")]
      .filter((label) => label.querySelector("span.text-danger-500"))
      .map((label) => {
        const id = label.htmlFor;
        const control = id ? (document.getElementById(id) as HTMLInputElement | HTMLSelectElement | null) : null;
        const container = label.parentElement;
        return {
          id: id || null,
          tag: control?.tagName.toLowerCase() ?? null,
          type: control instanceof HTMLInputElement ? control.type : null,
          empty: control ? control.value.trim() === "" : false,
          disabled: control ? control.disabled : false,
          // A checklist ("Promised to", "Stakeholders present") has no single control.
          checklist: !control && Boolean(container?.querySelector('input[type="checkbox"]'))
            && !container?.querySelector('input[type="checkbox"]:checked'),
          checklistIndex: [...document.querySelectorAll("label")].indexOf(label),
        };
      }),
  );

  const day = (offset: number) => new Date(Date.now() + offset * 864e5).toISOString().slice(0, 10);

  for (const field of fields) {
    if (field.checklist) {
      await page
        .locator("label")
        .nth(field.checklistIndex)
        .locator("xpath=..")
        .locator('input[type="checkbox"]')
        .first()
        .check();
      continue;
    }
    if (!field.id || !field.empty || field.disabled) continue;

    const selector = `#${field.id}`;
    if (field.tag === "select") {
      const values = await page.$$eval(`${selector} option`, (options) =>
        options.map((option) => (option as HTMLOptionElement).value).filter((value) => value && value !== "yes"),
      );
      if (values[0]) await page.selectOption(selector, values[0]);
    } else if (field.type === "date") {
      await page.fill(selector, /target|due/.test(field.id) ? day(7) : day(0));
    } else if (field.type === "number") {
      await page.fill(selector, "3");
    } else if (field.type === "email") {
      await page.fill(selector, "e2e@example.com");
    } else if (field.type === "tel") {
      await page.fill(selector, "+255754000333");
    } else if (field.type !== "file" && field.type !== "checkbox" && field.type !== "radio") {
      await page.fill(selector, "Recorded by the end-to-end suite.");
    }
  }
}
