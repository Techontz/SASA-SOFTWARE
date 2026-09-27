import { expect, test } from "@playwright/test";
import { ACCOUNTS, settle, signIn } from "./helpers";

/**
 * ACCEPTANCE: an unauthorised user must not receive the confidential grievance
 * identity fields. The guarantee is stronger than "hidden" — the fields are
 * absent from the response body itself.
 */
test.describe("field-level confidentiality", () => {
  test("a field officer receives a confidential case with no identity anywhere in the payload", async ({ page }) => {
    const bodies: string[] = [];

    page.on("response", async (response) => {
      if (response.url().includes("/api/v1/grievances") && response.request().method() === "GET") {
        bodies.push(await response.text().catch(() => ""));
      }
    });

    await signIn(page, ACCOUNTS.fieldOfficer);
    await page.goto("/grievances?confidentiality=confidential");
    await settle(page);

    const link = page.getByRole("link", { name: /GRV-/ }).first();

    if (await link.count()) {
      await link.click();
      await page.waitForURL(/\/grievances\/\d+$/);
      await settle(page);

      await expect(page.getByText("Details are withheld")).toBeVisible();
      await expect(page.getByText(/only released to the handling group/)).toBeVisible();

      // The substance is still readable — the case is workable.
      await expect(page.getByText("What happened")).toBeVisible();
    }

    // And nothing that looks like a complainant field arrived on the wire.
    for (const body of bodies) {
      expect(body).not.toContain('"complainant"');
      expect(body).not.toContain("precise_location");
    }
  });

  test("a restricted case does not exist at all for someone outside the handling group", async ({ page }) => {
    /* The list summary reports the true total, which a page of results cannot. */
    const totalFor = async (email: string) => {
      await signIn(page, email);
      await page.goto("/grievances");
      await settle(page);

      const total = await page
        .locator("dl div")
        .filter({ hasText: /^TOTAL/i })
        .first()
        .locator("dd")
        .innerText();

      return Number(total.replace(/\D/g, ""));
    };

    const handlerTotal = await totalFor(ACCOUNTS.grievanceOfficer);
    const fieldTotal = await totalFor(ACCOUNTS.fieldOfficer);

    // The handling group sees strictly more cases than someone outside it.
    expect(handlerTotal).toBeGreaterThan(fieldTotal);
  });

  test("an anonymous case says so, and there is nothing to release", async ({ page }) => {
    await signIn(page, ACCOUNTS.grievanceOfficer);
    await page.goto("/grievances?confidentiality=anonymous");
    await settle(page);

    const link = page.getByRole("link", { name: /GRV-/ }).first();

    if (await link.count()) {
      await link.click();
      await page.waitForURL(/\/grievances\/\d+$/);
      await expect(page.getByText("Submitted anonymously", { exact: true })).toBeVisible();
      await expect(page.getByText(/No identity was ever recorded/)).toBeVisible();
    }
  });

  test("choosing anonymous on the intake form removes the identity fields entirely", async ({ page }) => {
    await signIn(page, ACCOUNTS.grievanceOfficer);
    await page.goto("/grievances/new");

    await expect(page.locator("#complainant_name")).toBeVisible();

    await page.getByText("Anonymous", { exact: true }).click();

    await expect(page.locator("#complainant_name")).toHaveCount(0);
    await expect(page.getByText(/Nothing identifying will be stored/)).toBeVisible();
  });
});
