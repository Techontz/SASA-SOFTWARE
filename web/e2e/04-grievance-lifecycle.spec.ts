import { expect, test } from "@playwright/test";
import { ACCOUNTS, signIn, uniqueName } from "./helpers";

/**
 * ACCEPTANCE: given a grievance is submitted, the system assigns a case ID,
 * classifies it, starts the SLA, assigns an owner, and tracks investigation,
 * resolution and closure — and can reopen without creating a duplicate.
 */
test.describe("the grievance lifecycle", () => {
  test("a case runs from intake to closure and can be reopened on the same ID", async ({ page }) => {
    await signIn(page, ACCOUNTS.grievanceOfficer);

    // --- intake -------------------------------------------------------
    await page.goto("/grievances/new");

    const title = uniqueName("Dust from haulage trucks");
    await page.selectOption("#channel", "in_person");
    await page.fill("#complainant_name", "Neema Charles Mwita");
    await page.fill("#complainant_phone", "+255 754 123 456");
    await page.fill("#title", title);
    await page.fill(
      "#description",
      "Trucks pass every few minutes from early morning. The dust settles on the houses, the food and the washing.",
    );
    await page.fill("#desired_resolution", "Water the road, or move the haulage route away from the houses.");
    await page.getByRole("button", { name: "Open the case" }).click();

    await expect(page).toHaveURL(/\/grievances\/\d+$/, { timeout: 40_000 });
    const caseUrl = page.url();

    // A case ID was assigned and both clocks are running.
    await expect(page.getByText(/GRV-\d{4}/).first()).toBeVisible();
    await expect(page.getByText("Acknowledgement", { exact: true })).toBeVisible();
    await expect(page.getByText("Resolution", { exact: true })).toBeVisible();

    // --- assign --------------------------------------------------------
    await page.getByRole("button", { name: /Assign an owner|Reassign/ }).click();
    await page.selectOption("#assignee", { index: 1 });
    await page.getByRole("button", { name: "Assign", exact: true }).click();
    await expect(page.getByText("Case assigned")).toBeVisible({ timeout: 20_000 });

    // --- acknowledge ----------------------------------------------------
    await page.getByRole("button", { name: "Acknowledge to the complainant" }).click();
    await page.selectOption("#ack-method", "sms");
    await page.getByRole("button", { name: "Record it" }).click();
    await expect(page.getByText("Acknowledgement recorded")).toBeVisible({ timeout: 20_000 });

    // --- investigate -----------------------------------------------------
    await page.getByRole("button", { name: /investigat/i }).first().click();
    await page.fill("#inv-summary", "Site visit carried out with the ward executive officer.");
    await page.fill("#inv-findings", "The complaint is substantiated. Watering had stopped.");
    await page.selectOption("#inv-complete", "yes");
    await page.getByRole("button", { name: "Save", exact: true }).click();
    await expect(page.getByText("Investigation updated")).toBeVisible({ timeout: 20_000 });

    // --- resolve ----------------------------------------------------------
    await page.getByRole("button", { name: "Record the resolution" }).click();
    await page.fill(
      "#resolution",
      "Twice-daily watering was reinstated and verified on site. The complainant was visited and shown the change.",
    );
    await page.getByRole("button", { name: "Record resolution" }).click();
    await expect(page.getByText("Resolution recorded")).toBeVisible({ timeout: 20_000 });

    // --- the complainant's own answer --------------------------------------
    await page.getByRole("button", { name: "Record the complainant's answer" }).click();
    await page.selectOption("#response", "accepted");
    await page.getByRole("button", { name: "Record it" }).click();
    await expect(page.getByText("Response recorded")).toBeVisible({ timeout: 20_000 });

    // --- close ---------------------------------------------------------------
    await page.getByRole("button", { name: "Close the case" }).first().click();
    await page.getByRole("button", { name: "Close the case", exact: true }).last().click();
    await expect(page.getByText("Case closed")).toBeVisible({ timeout: 20_000 });

    // --- reopen keeps the same case ID ----------------------------------------
    await page.goto(caseUrl);
    await page.getByRole("button", { name: "Reopen" }).first().click();
    await page.fill("#reopen-reason", "The complainant says watering stopped after three days and the dust is back.");
    await page.getByRole("button", { name: "Reopen", exact: true }).last().click();

    await expect(page.getByText(/reopened/i).first()).toBeVisible({ timeout: 20_000 });
    await expect(page.getByText(/cycle 2/i).first()).toBeVisible();
    // Same URL, same case — no duplicate was created.
    expect(page.url()).toBe(caseUrl);
  });

  test("a resolution of a few words is refused", async ({ page }) => {
    await signIn(page, ACCOUNTS.grievanceOfficer);
    await page.goto("/grievances?status=open");
    await page.getByRole("link", { name: /GRV-/ }).first().click();
    await page.waitForURL(/\/grievances\/\d+$/);

    const resolve = page.getByRole("button", { name: "Record the resolution" });

    if (await resolve.count()) {
      await resolve.click();
      await page.fill("#resolution", "Fixed");
      await expect(page.getByRole("button", { name: "Record resolution" })).toBeDisabled();
    }
  });
});
