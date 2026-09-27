import { expect, test } from "@playwright/test";
import { ACCOUNTS, signIn, uniqueName } from "./helpers";

/**
 * ACCEPTANCE: given an authorised user submits a stakeholder, the system
 * creates a unique ID, saves the record, and makes it available to the
 * engagement and grievance modules.
 */
test.describe("the stakeholder register", () => {
  test("a community relations officer adds a stakeholder and it gets an ID and a priority", async ({ page }) => {
    await signIn(page, ACCOUNTS.communityRelations);

    await page.goto("/stakeholders/new");

    const name = uniqueName("Mama Fatuma");
    await page.fill("#name", name);
    await page.selectOption("#type", "household");
    await page.fill("#phone", "+255 754 900 001");
    await page.selectOption("#influence", "medium");
    await page.selectOption("#interest", "high");
    await page.selectOption("#power", "low");
    await page.selectOption("#impact", "high");

    // The score is worked out live, before anything is saved.
    await expect(page.getByText("9", { exact: true }).first()).toBeVisible();

    await page.getByRole("button", { name: /Add to the register/ }).click();

    await expect(page).toHaveURL(/\/stakeholders\/\d+$/, { timeout: 30_000 });
    await expect(page.getByRole("heading", { level: 1 })).toContainText(name);
    await expect(page.getByText(/STK-\d{4}/).first()).toBeVisible();
  });

  test("the calculated priority stays visible next to an override, with the reason", async ({ page }) => {
    await signIn(page, ACCOUNTS.communityRelations);

    await page.goto("/stakeholders");
    await page.getByRole("link", { name: /STK-/ }).first().click();
    await page.waitForURL(/\/stakeholders\/\d+$/);

    await page.getByRole("button", { name: "Set priority" }).click();
    await page.selectOption("#override-priority", "high");
    await page.fill(
      "#override-reason",
      "Chairs the district land committee, which the four scoring dimensions do not capture.",
    );
    await page.getByRole("button", { name: "Save priority" }).click();

    await expect(page.getByText("Set by a person, not by the score.")).toBeVisible({ timeout: 20_000 });
    // The calculated value is still on the card, next to the stored one.
    await expect(page.getByText("Calculated", { exact: true }).first()).toBeVisible();
    await expect(page.getByText(/district land committee/)).toBeVisible();
  });

  test("a field officer cannot override a priority", async ({ page }) => {
    await signIn(page, ACCOUNTS.fieldOfficer);
    await page.goto("/stakeholders");
    await page.getByRole("link", { name: /STK-/ }).first().click();

    await expect(page.getByRole("button", { name: "Set priority" })).toHaveCount(0);
  });

  test("an auditor can read the register but not add to it", async ({ page }) => {
    await signIn(page, ACCOUNTS.auditor);

    await page.goto("/stakeholders");
    await expect(page.getByRole("heading", { name: "Stakeholders" })).toBeVisible();
    await expect(page.getByRole("link", { name: "Add stakeholder" })).toHaveCount(0);

    await page.goto("/stakeholders/new");
    await expect(page.getByText(/do not have access/)).toBeVisible();
  });
});
