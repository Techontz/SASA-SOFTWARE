import { expect, test } from "@playwright/test";
import { ACCOUNTS, settle, signIn } from "./helpers";

/**
 * Global search is the fastest route to any record, so it is also the fastest
 * route to a record someone should not see. The filtering is the server's job;
 * what these tests check is that the client asks for it correctly and never
 * renders anything the server did not send.
 */
test.describe("searching across the project", () => {
  test("finds records and groups them by what they are", async ({ page }) => {
    await signIn(page, ACCOUNTS.projectAdmin);
    await settle(page);

    await page.getByRole("button", { name: /Search stakeholders/i }).first().click();
    const box = page.getByRole("dialog", { name: "Search" });
    await expect(box).toBeVisible();

    await box.getByRole("combobox", { name: "Search" }).fill("GRV");
    await expect(box.getByRole("option").first()).toBeVisible({ timeout: 15_000 });

    /* The reference is shown on every row: it is how someone matches what is
       on screen against a paper form or a phone call. */
    await expect(box.getByText(/GRV-\d+/).first()).toBeVisible();
  });

  test("moves through results with the keyboard and opens the highlighted one", async ({ page }) => {
    await signIn(page, ACCOUNTS.projectAdmin);
    await settle(page);

    await page.keyboard.press("ControlOrMeta+k");
    const box = page.getByRole("dialog", { name: "Search" });
    await expect(box).toBeVisible();

    await box.getByRole("combobox", { name: "Search" }).fill("GRV");
    await expect(box.getByRole("option").first()).toBeVisible({ timeout: 15_000 });

    await page.keyboard.press("ArrowDown");
    await expect(box.getByRole("option").nth(1)).toHaveAttribute("aria-selected", "true");

    await page.keyboard.press("ArrowUp");
    await expect(box.getByRole("option").first()).toHaveAttribute("aria-selected", "true");

    await page.keyboard.press("Enter");
    await expect(box).toBeHidden();
    await expect(page).toHaveURL(/\/(grievances|stakeholders|engagements|commitments|concerns)\//);
  });

  test("says plainly when nothing matched, and why the scope is narrow", async ({ page }) => {
    await signIn(page, ACCOUNTS.projectAdmin);
    await settle(page);

    await page.keyboard.press("ControlOrMeta+k");
    const box = page.getByRole("dialog", { name: "Search" });
    await box.getByRole("combobox", { name: "Search" }).fill("zzzzqqqqnotathing");

    await expect(box.getByText(/Nothing matched/i)).toBeVisible({ timeout: 15_000 });
    await expect(box.getByText(/only records you have permission to open/i)).toBeVisible();
  });

  test("closes on escape without navigating anywhere", async ({ page }) => {
    await signIn(page, ACCOUNTS.projectAdmin);
    await settle(page);
    const before = page.url();

    await page.keyboard.press("ControlOrMeta+k");
    const box = page.getByRole("dialog", { name: "Search" });
    await expect(box).toBeVisible();

    await page.keyboard.press("Escape");

    await expect(box).toBeHidden();
    expect(page.url()).toBe(before);
  });

  test("never returns a restricted case to someone outside its handling group", async ({ page }) => {
    await signIn(page, ACCOUNTS.fieldOfficer);
    await settle(page);

    /* The wire is what matters: a record filtered out in the browser has
       already leaked. */
    const bodies: string[] = [];
    page.on("response", async (response) => {
      if (!response.url().includes("/search")) return;
      try {
        bodies.push(await response.text());
      } catch {
        /* A cancelled request has no body to read. */
      }
    });

    await page.keyboard.press("ControlOrMeta+k");
    const box = page.getByRole("dialog", { name: "Search" });
    await box.getByRole("combobox", { name: "Search" }).fill("retaliation");
    await page.waitForTimeout(2500);

    for (const body of bodies) {
      expect(body).not.toContain("complainant");
      expect(body.toLowerCase()).not.toContain("retaliation against");
    }
  });
});
