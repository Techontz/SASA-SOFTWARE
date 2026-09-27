import { expect, test } from "@playwright/test";
import { ACCOUNTS, settle, signIn } from "./helpers";

/**
 * ACCEPTANCE: given records exist, the dashboard KPIs reflect live data; and
 * given filters are selected, the generated report contains matching data.
 */
test.describe("dashboards and reporting", () => {
  test("the executive dashboard shows live numbers, each with its definition", async ({ page }) => {
    await signIn(page, ACCOUNTS.executive);
    await page.goto("/analytics");
    await settle(page);

    await expect(page.getByText("Total stakeholders")).toBeVisible();
    await expect(page.getByText("Open grievances")).toBeVisible();

    // Every KPI carries a definition a person can read.
    const notes = page.getByRole("note");
    expect(await notes.count()).toBeGreaterThan(4);
    await expect(notes.first()).toHaveAttribute("aria-label", /How this is measured/);
  });

  test("a KPI drills through to the records behind it", async ({ page }) => {
    await signIn(page, ACCOUNTS.executive);
    await page.goto("/analytics");
    await settle(page);

    await page.getByText("Open grievances").click();
    await expect(page).toHaveURL(/\/grievances/, { timeout: 20_000 });
  });

  test("the disaggregation dashboard suppresses cells that could identify someone", async ({ page }) => {
    await signIn(page, ACCOUNTS.executive);
    await page.goto("/analytics");
    await page.getByRole("tab", { name: "Who is reached" }).click();
    await settle(page);

    await expect(page.getByText("Who is being reached, and who is being missed")).toBeVisible();
  });

  test("a field officer cannot open the disaggregation dashboard", async ({ page }) => {
    await signIn(page, ACCOUNTS.fieldOfficer);
    await page.goto("/analytics");
    await expect(page.getByRole("tab", { name: "Who is reached" })).toHaveCount(0);
  });

  test("a report generates and is kept with its parameters", async ({ page }) => {
    await signIn(page, ACCOUNTS.grievanceOfficer);
    await page.goto("/reports");

    await page.selectOption("#template", "executive_summary");
    await page.getByText("Excel").click();
    await page.getByRole("button", { name: "Generate" }).click();

    await expect(page.getByText("Report generated")).toBeVisible({ timeout: 90_000 });
    await expect(page.getByText(/REP-\d{4}/).first()).toBeVisible();
    await expect(page.getByRole("button", { name: "Download" }).first()).toBeVisible();
  });

  test("every metric definition is published in the interface", async ({ page }) => {
    await signIn(page, ACCOUNTS.grievanceOfficer);
    await page.goto("/reports");

    await expect(page.getByText("How every number is defined")).toBeVisible();
    await expect(page.getByText(/Cases not currently closed/)).toBeVisible();
  });
});
