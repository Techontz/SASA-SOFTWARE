import { expect, test, type Page } from "@playwright/test";
import { ACCOUNTS, signIn } from "./helpers";

/*
 * The public subscription journey, run against the API's sandbox payment
 * driver: choose a plan, give the organisation's details, pay by mobile money
 * or by card, and see the request arrive for the platform administrator.
 */

async function startStarter(page: Page, currency: "USD" | "TZS", organisation: string) {
  await page.goto("/pricing");
  if (currency === "TZS") await page.getByRole("radio", { name: "TZS" }).click();
  await page.getByRole("link", { name: /Choose Starter/ }).click();
  await expect(page).toHaveURL(/\/subscribe\?plan=starter/);

  await page.getByLabel(/Organisation/).fill(organisation);
  await page.getByLabel(/Your name/).fill("Neema Test");
  await page.getByLabel(/Work e-mail/).fill("neema@example.com");
  await page.getByLabel(/^Mobile number/).fill("0712 345 678");
  await page.getByRole("button", { name: /Continue to payment/ }).click();
  await expect(page.getByRole("heading", { name: "How would you like to pay?" })).toBeVisible();
}

test.describe("subscribing", () => {
  test("pays by mobile money with only a phone number", async ({ page }) => {
    const organisation = `Mobile Money Org ${Date.now()}`;
    await startStarter(page, "USD", organisation);

    await page.getByRole("radio", { name: /Mobile money/ }).click();
    await expect(page.getByLabel(/Mobile money number/)).toHaveValue("0712 345 678");
    await page.getByRole("button", { name: /^Pay / }).click();

    await expect(page.getByRole("heading", { name: "Check your phone" })).toBeVisible();
    await expect(page.getByRole("heading", { name: "Payment received" })).toBeVisible({ timeout: 30_000 });
  });

  test("pays by card on the hosted page and comes back confirmed", async ({ page }) => {
    await startStarter(page, "TZS", `Card Org ${Date.now()}`);

    await page.getByRole("radio", { name: /Card/ }).click();
    await page.getByRole("button", { name: /Continue to card payment/ }).click();
    await expect(page).toHaveURL(/sandbox-card/);
    await page.getByRole("button", { name: "Approve payment" }).click();

    await expect(page).toHaveURL(/\/subscribe\/return/);
    await expect(page.getByRole("heading", { name: "Payment received" })).toBeVisible({ timeout: 20_000 });
  });

  test("rejects a phone number that is not Tanzanian mobile", async ({ page }) => {
    await page.goto("/subscribe?plan=starter");
    await page.getByLabel(/Organisation/).fill("Validation Org");
    await page.getByLabel(/Your name/).fill("Neema Test");
    await page.getByLabel(/Work e-mail/).fill("neema@example.com");
    await page.getByLabel(/^Mobile number/).fill("12345");
    await page.getByRole("button", { name: /Continue to payment/ }).click();
    await expect(page.getByText(/Enter a Tanzanian mobile number/)).toBeVisible();
  });

  test("the platform administrator sees the request and manages plans", async ({ page }) => {
    const organisation = `Admin View Org ${Date.now()}`;
    await startStarter(page, "USD", organisation);

    await signIn(page, ACCOUNTS.admin);
    await page.goto("/billing");
    await expect(page.getByRole("heading", { name: "Plans & billing" })).toBeVisible();
    await expect(page.getByText(organisation)).toBeVisible();

    await page.getByRole("tab", { name: "Plans" }).click();
    await expect(page.getByRole("heading", { name: "Professional" })).toBeVisible();
  });

  test("a project role without billing rights never sees billing", async ({ page }) => {
    await signIn(page, ACCOUNTS.fieldOfficer);
    await expect(page.getByRole("link", { name: "Plans & billing" })).toHaveCount(0);
  });
});
