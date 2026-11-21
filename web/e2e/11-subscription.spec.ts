import { expect, test } from "@playwright/test";
import { ACCOUNTS, signIn } from "./helpers";

/*
 * B2B billing: prices are never public. A company requests a quote with its
 * project details; the SASA team agrees a plan; the customer's administrator
 * sees it under Your plan and pays the monthly invoice.
 */

test.describe("quotes and agreed plans", () => {
  test("the old pricing address leads to the quote form, which shows no prices", async ({ page }) => {
    await page.goto("/pricing");
    await expect(page).toHaveURL(/\/request-quote$/);
    await expect(page.getByRole("heading", { level: 1 })).toContainText("Request a quote");
    await expect(page.getByText(/\$\d|TSh|per month/)).toHaveCount(0);
  });

  test("a quote request asks about the project before it sends", async ({ page }) => {
    await page.goto("/request-quote");
    await page.getByLabel(/Full name/).fill("Neema Test");
    await page.getByLabel(/E-mail address/).fill("neema@example.com");
    await page.getByLabel(/^Organization/).fill(`Quote Org ${Date.now()}`);
    await page.getByLabel(/^Sector/).selectOption("infrastructure");
    await page.getByRole("button", { name: "Request a quote" }).click();
    await expect(page.getByText("Choose the size of your company.")).toBeVisible();

    await page.getByLabel(/Company size/).selectOption("51-200");
    await page.getByLabel(/Number of projects/).fill("2");
    await page.getByLabel(/Expected users/).fill("40");
    await page.getByRole("button", { name: "Request a quote" }).click();
    await expect(page.getByText(/Reference ENQ-/)).toBeVisible();
  });

  test("the customer's administrator sees the agreed plan and pays the invoice by mobile money", async ({ page }) => {
    await signIn(page, ACCOUNTS.projectAdmin);
    await page.goto("/subscription");
    await expect(page.getByRole("heading", { name: "Your plan" })).toBeVisible();
    await expect(page.getByText("per month").first()).toBeVisible();
    await expect(page.getByText("What your plan includes")).toBeVisible();
    await expect(page.getByText("Roles in your system")).toBeVisible();

    const pay = page.getByRole("button", { name: /^Pay / }).first();
    if (await pay.isVisible()) {
      await pay.click();
      await page.getByLabel(/Mobile money number/).fill("0712 345 678");
      await page.getByRole("dialog").getByRole("button", { name: /^Pay / }).click();
      await expect(page.getByText("Check your phone")).toBeVisible();
      await expect(page.getByText(/is paid\. Thank you\./)).toBeVisible({ timeout: 30_000 });
    }
  });

  test("the platform administrator manages customers and invoices", async ({ page }) => {
    await signIn(page, ACCOUNTS.admin);
    await page.goto("/billing");
    await expect(page.getByRole("heading", { name: "Customers & billing" })).toBeVisible();
    await expect(page.getByText(/per month/).first()).toBeVisible();
    await page.getByRole("tab", { name: "Invoices" }).click();
    await expect(page.getByText(/INV-/).first()).toBeVisible();
  });

  test("a field officer never sees billing", async ({ page }) => {
    await signIn(page, ACCOUNTS.fieldOfficer);
    await expect(page.getByRole("link", { name: "Customers & billing" })).toHaveCount(0);
    await expect(page.getByRole("link", { name: "Your plan" })).toHaveCount(0);
  });
});
