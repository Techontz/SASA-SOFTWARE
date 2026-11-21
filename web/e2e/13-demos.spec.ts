import { expect, test } from "@playwright/test";
import { ACCOUNTS, signIn } from "./helpers";

/*
 * Giving a demo: a company asks for one on the website, the administrator gives
 * it in one step, and the company opens the link, chooses a password and lands
 * in its own workspace, filled with sample records.
 */

test("an administrator gives a demo and the company opens it with the link", async ({ page, browser }) => {
  const stamp = Date.now();
  const organization = `Demo Co ${stamp}`;
  const email = `demo.${stamp}@example.com`;

  await page.goto("/book-demo");
  await page.getByLabel(/Full name/).fill("Amina Demo");
  await page.getByLabel(/E-mail address/).fill(email);
  await page.getByLabel(/^Organization/).fill(organization);
  await page.getByLabel(/^Sector/).selectOption("energy");
  await page.getByRole("button", { name: "Request a demonstration" }).click();
  await expect(page.getByText(/Reference ENQ-/)).toBeVisible();

  await signIn(page, ACCOUNTS.admin);
  await page.goto("/enquiries");
  await page.getByText(organization).click();
  await page.getByRole("button", { name: "Give a demo" }).click();
  const link = page.getByLabel("Sign-in link");
  await expect(link).toBeVisible({ timeout: 20_000 });
  const url = await link.inputValue();
  expect(url).toContain("/set-password?token=");

  await page.keyboard.press("Escape");
  await page.getByRole("tab", { name: "Demos" }).click();
  await expect(page.getByRole("listitem").filter({ hasText: organization })).toBeVisible();

  // The company, in its own browser.
  const company = await browser.newPage();
  await company.goto(url.replace(/^https?:\/\/[^/]+/, ""));
  await expect(company.getByRole("heading", { name: "Welcome to your SASA demo" })).toBeVisible();
  await company.getByLabel(/^Password/).fill("DemoPassword2026");
  await company.getByLabel(/Confirm password/).fill("DemoPassword2026");
  await company.getByRole("button", { name: "Open my demo" }).click();
  await expect(company).toHaveURL(/\/dashboard$/, { timeout: 20_000 });
  await expect(company.getByText("Demo workspace")).toBeVisible();
  await company.close();
});

test("a link without its token explains itself", async ({ page }) => {
  await page.goto("/set-password");
  await expect(page.getByRole("heading", { name: "This link is incomplete" })).toBeVisible();
});
