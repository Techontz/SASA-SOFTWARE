import { expect, test } from "@playwright/test";
import { ACCOUNTS, signIn, uniqueName } from "./helpers";

/**
 * ACCEPTANCE: given a planned engagement exists, when the actual engagement is
 * logged, the system links it to the plan and calculates planned-vs-actual.
 * And: a commitment made in an engagement creates a register entry
 * automatically; a concern can become a grievance without being retyped.
 */
test.describe("the engagement chain", () => {
  test("plan, log, and the concern and commitment it produced all land as records", async ({ page }) => {
    await signIn(page, ACCOUNTS.communityRelations);

    // --- plan it -------------------------------------------------------
    await page.goto("/engagements/plans/new");
    const title = uniqueName("Corridor disclosure meeting");
    await page.fill("#title", title);
    await page.fill("#target_date", new Date().toISOString().slice(0, 10));
    await page.selectOption("#method", "community_meeting");
    await page.getByRole("button", { name: "Add to the plan" }).click();

    await expect(page).toHaveURL(/\/engagements\/plans\/\d+$/, { timeout: 30_000 });
    await expect(page.getByText(/PLAN-\d{4}/).first()).toBeVisible();

    // --- log what happened, with a concern and a commitment ------------
    await page.getByRole("link", { name: "Log what happened" }).first().click();
    await page.waitForURL(/\/engagements\/log/);

    await page.fill("#topic", title);
    await page.fill("#attendance_total", "180");
    await page.fill("#attendance_female", "74");
    await page.fill("#discussion_points", "Works programme presented; the grievance mechanism was explained again.");

    await page.getByRole("button", { name: "Add a concern" }).click();
    await page.fill("#concern-0-description", "Dust from the access road is reaching the school every morning.");
    await page.selectOption("#concern-0-severity", "high");

    await page.getByRole("button", { name: "Add a commitment" }).click();
    await page.fill("#commitment-0-text", "Water the access road twice daily during dry-season works.");
    await page.selectOption("#commitment-0-risk", "high");

    await page.getByRole("button", { name: "Save the engagement" }).click();

    await expect(page).toHaveURL(/\/engagements\/\d+$/, { timeout: 40_000 });

    // The plan is closed and the variance is worked out.
    await expect(page.getByText("On plan").first()).toBeVisible();
    // The concern and the commitment exist as their own records.
    await expect(page.getByText(/CON-\d{4}/).first()).toBeVisible();
    await expect(page.getByText(/COM-\d{4}/).first()).toBeVisible();
    await expect(page.getByText("Water the access road twice daily during dry-season works.")).toBeVisible();
  });

  test("an unplanned engagement is flagged as unplanned rather than hidden", async ({ page }) => {
    await signIn(page, ACCOUNTS.fieldOfficer);

    await page.goto("/engagements/log");
    await page.fill("#topic", uniqueName("Unscheduled visit after a complaint"));
    await page.fill("#attendance_total", "6");
    await page.getByRole("button", { name: "Save the engagement" }).click();

    await expect(page).toHaveURL(/\/engagements\/\d+$/, { timeout: 40_000 });
    await expect(page.getByText("Unplanned").first()).toBeVisible();
  });

  test("a concern becomes a grievance without anyone retyping it", async ({ page }) => {
    await signIn(page, ACCOUNTS.grievanceOfficer);

    await page.goto("/concerns?escalated=0");
    const firstConcern = page.getByRole("link", { name: /CON-/ }).first();
    await firstConcern.click();
    await page.waitForURL(/\/concerns\/\d+$/);

    const description = await page.getByTestId("concern-description").innerText();

    await page.getByRole("button", { name: "Escalate to a grievance" }).click();
    await expect(page.getByText("Carrying across")).toBeVisible();
    await page.getByRole("button", { name: "Create the case" }).click();

    await expect(page).toHaveURL(/\/grievances\/\d+$/, { timeout: 30_000 });
    // The same words arrived on the case.
    await expect(page.getByText(description.slice(0, 40)).first()).toBeVisible();
  });
});
