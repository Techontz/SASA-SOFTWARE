import { expect, test } from "@playwright/test";
import { ACCOUNTS, settle, signIn } from "./helpers";

/** The field officer's phone is the hardest device SASA has to work on. */
test.describe("the mobile field experience", () => {
  test.skip(({ isMobile }) => !isMobile, "Only meaningful on a phone viewport.");

  test("bottom navigation gets a field officer to the things they do daily", async ({ page }) => {
    await signIn(page, ACCOUNTS.fieldOfficer);

    const nav = page.getByRole("navigation", { name: "Primary" });
    await expect(nav).toBeVisible();

    await nav.getByRole("link", { name: "Register" }).click();
    await expect(page).toHaveURL(/stakeholders/);

    await nav.getByRole("link", { name: "Cases" }).click();
    await expect(page).toHaveURL(/grievances/);
  });

  test("the central button opens the thing they most often need to record", async ({ page }) => {
    await signIn(page, ACCOUNTS.fieldOfficer);

    await page.getByRole("button", { name: "New record" }).click();
    await expect(page).toHaveURL(/grievances\/new|engagements\/log|stakeholders\/new/);
  });

  test("no screen scrolls sideways on a phone", async ({ page }) => {
    await signIn(page, ACCOUNTS.fieldOfficer);

    for (const path of ["/", "/stakeholders", "/grievances", "/engagements", "/sync"]) {
      await page.goto(path);
      await settle(page);

      const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
      );

      expect(overflow, `${path} scrolls sideways`).toBeLessThanOrEqual(2);
    }
  });

  test("lists become cards on a phone rather than a squeezed table", async ({ page }) => {
    await signIn(page, ACCOUNTS.fieldOfficer);
    await page.goto("/grievances");
    await settle(page);

    await expect(page.getByRole("table")).toBeHidden();
  });

  test("form controls are big enough to hit with a thumb", async ({ page }) => {
    await signIn(page, ACCOUNTS.fieldOfficer);
    await page.goto("/grievances/new");

    const submit = page.getByRole("button", { name: "Open the case" });
    const box = await submit.boundingBox();

    expect(box?.height ?? 0).toBeGreaterThanOrEqual(40);
  });
});
