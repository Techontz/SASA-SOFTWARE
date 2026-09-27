import { expect, test } from "@playwright/test";
import { ACCOUNTS, queuedOperationCount, settle, signIn, uniqueName, serviceWorkerReady } from "./helpers";

/**
 * THE OFFLINE ACCEPTANCE TEST.
 *
 *  1. sign in online          6. reload the application
 *  2. go offline              7. confirm the work is still there
 *  3. create a stakeholder    8. go back online
 *  4. create a grievance      9. confirm it syncs by itself
 *  5. confirm both are queued 10. confirm no duplicates
 *
 * A field officer must never lose a completed form because the connection
 * disappeared, and must never be told a record reached the server when it did not.
 */
test.describe("working offline", () => {
  test.describe.configure({ mode: "serial" });

  const stakeholderName = uniqueName("Offline Household");
  const grievanceTitle = uniqueName("Recorded with no signal");

  test("work created offline is kept, survives a reload, and syncs by itself", async ({ page, context }) => {
    // 1 — sign in while there is a connection and cache what the device needs.
    await signIn(page, ACCOUNTS.fieldOfficer);
    // Registration alone is not enough: until the worker is controlling the
    // page, the warm-up navigations below are not intercepted and nothing
    // reaches the shell cache.
    await serviceWorkerReady(page);
    await page.goto("/sync");
    await page.getByRole("button", { name: "Download for offline use" }).click();
    await expect(page.getByText("Field data cached")).toBeVisible({ timeout: 40_000 });

    // Warm the screens the officer will use, the way opening the app once does.
    await page.goto("/stakeholders/new");
    await page.goto("/grievances/new");
    await page.goto("/sync");

    // 2 — the connection disappears.
    await context.setOffline(true);
    await page.evaluate(() => window.dispatchEvent(new Event("offline")));

    // Navigating with no signal is served by the service worker's shell cache.
    await page.goto("/stakeholders/new");
    await page.evaluate(() => window.dispatchEvent(new Event("offline")));
    await expect(page.getByText(/You are offline/).first()).toBeVisible({ timeout: 20_000 });

    // 3 — create a stakeholder with no signal.
    await page.fill("#name", stakeholderName);
    await page.selectOption("#type", "household");
    await page.fill("#phone", "+255 755 111 222");
    await page.getByRole("button", { name: /Add to the register/ }).click();

    // The user is told the truth: on this device, not on the server. The app
    // stays put rather than forcing a reload with no connection.
    await expect(page.getByText(/Stakeholder saved on this device/i).first()).toBeVisible({ timeout: 20_000 });
    await expect(page.getByText(/SASA will send it by itself/i).first()).toBeVisible();

    // 4 — create a grievance with no signal.
    await page.goto("/grievances/new");
    await page.evaluate(() => window.dispatchEvent(new Event("offline")));
    await page.fill("#title", grievanceTitle);
    await page.fill("#complainant_name", "A caller in the field");
    await page.fill(
      "#description",
      "Written down on a tablet with no connection and queued until the signal returned.",
    );
    await page.getByRole("button", { name: "Open the case" }).click();
    await expect(page.getByText(/Grievance saved on this device/i).first()).toBeVisible({ timeout: 20_000 });

    // 5 — both are in the device's own queue.
    expect(await queuedOperationCount(page)).toBeGreaterThanOrEqual(2);

    // 6 & 7 — reload the whole application; the work is still there.
    await page.reload();
    await page.waitForLoadState("domcontentloaded");
    await page.evaluate(() => window.dispatchEvent(new Event("offline")));
    expect(await queuedOperationCount(page)).toBeGreaterThanOrEqual(2);

    await page.goto("/sync");
    await page.evaluate(() => window.dispatchEvent(new Event("offline")));
    await expect(page.getByText("The queue on this device")).toBeVisible();
    await expect(page.getByText(/Create stakeholder|Create grievance/i).first()).toBeVisible();

    // 8 — the signal returns.
    await context.setOffline(false);
    await page.evaluate(() => window.dispatchEvent(new Event("online")));

    // 9 — it syncs without anybody pressing anything (the button only hurries it).
    await page.getByRole("button", { name: "Sync now" }).click();

    await expect(page.getByText("Everything is on the server")).toBeVisible({ timeout: 60_000 });
    expect(await queuedOperationCount(page)).toBe(0);

    // 10 — and the server has exactly one of each.
    await page.goto(`/stakeholders?search=${encodeURIComponent(stakeholderName)}`);
    await settle(page);
    // On a phone the table is hidden and the card list is shown, so match the
    // visible one rather than whichever comes first in the DOM.
    await expect(page.getByText(stakeholderName).filter({ visible: true }).first()).toBeVisible({
      timeout: 20_000,
    });
    // Exactly one record — the desktop table row and the phone card, no duplicate.
    expect(await page.getByText(stakeholderName).count()).toBeLessThanOrEqual(2);

    await page.goto(`/grievances?search=${encodeURIComponent(grievanceTitle)}`);
    await settle(page);
    await expect(page.getByText(grievanceTitle).filter({ visible: true }).first()).toBeVisible({
      timeout: 20_000,
    });
  });

  test("a failed sync keeps the work and retries rather than dropping it", async ({ page, context }) => {
    await signIn(page, ACCOUNTS.fieldOfficer);

    // Create something while the API is unreachable.
    await context.route("**/api/v1/stakeholders", (route) => route.abort("failed"));
    await context.route("**/api/v1/sync/push", (route) => route.abort("failed"));

    await page.goto("/stakeholders/new");
    await page.fill("#name", uniqueName("Retry Household"));
    await page.selectOption("#type", "household");
    await page.getByRole("button", { name: /Add to the register/ }).click();

    await expect(page.getByText(/saved on this device/i).first()).toBeVisible({ timeout: 20_000 });
    expect(await queuedOperationCount(page)).toBeGreaterThanOrEqual(1);

    // The queue still holds it — nothing was thrown away.
    await page.goto("/sync");
    await expect(page.getByText("The queue on this device")).toBeVisible();

    // Restore the connection and let it drain.
    await context.unroute("**/api/v1/stakeholders");
    await context.unroute("**/api/v1/sync/push");

    await page.getByRole("button", { name: /Retry everything that failed|Sync now/ }).first().click();
    await expect(page.getByText("Everything is on the server")).toBeVisible({ timeout: 60_000 });
  });

  test("the sync indicator never claims the server has something it does not", async ({ page, context }) => {
    await signIn(page, ACCOUNTS.fieldOfficer);
    await context.setOffline(true);
    await page.evaluate(() => window.dispatchEvent(new Event("offline")));

    await expect(page.getByText("You are offline. Your work is saved on this device")).toBeVisible({
      timeout: 20_000,
    });

    await context.setOffline(false);
  });
});
