import { expect, test } from "@playwright/test";
import { ACCOUNTS, signIn } from "./helpers";

test.describe("signing in", () => {
  test("a wrong password is refused with a readable message", async ({ page }) => {
    await page.goto("/sign-in");
    await page.fill("#email", ACCOUNTS.grievanceOfficer);
    await page.fill("#password", "definitely-not-the-password");
    await page.click('button[type="submit"]');

    // Next.js adds its own route announcer with role="alert", so target the message.
    await expect(page.getByText("That email address and password do not match.")).toBeVisible();
    await expect(page).toHaveURL(/sign-in/);
  });

  test("an officer signs in and lands on a dashboard that answers their question", async ({ page }) => {
    await signIn(page, ACCOUNTS.grievanceOfficer);

    await expect(page.getByRole("heading", { level: 1 })).toContainText(/Good (morning|afternoon|evening)/);
    await expect(page.getByText("What needs attention today?")).toBeVisible();
  });

  test("the executive is asked a different question from the field officer", async ({ page }) => {
    await signIn(page, ACCOUNTS.executive);
    await expect(page.getByText("What is happening on this project?")).toBeVisible();
  });

  test("a visitor lands on the public page: Book a demo opens the form, Log in leads to sign-in", async ({ page }) => {
    await page.goto("/");
    await expect(page.getByRole("heading", { level: 1 })).toContainText("Environmental and Social performance");

    await page.getByRole("link", { name: /Book a demo/ }).first().click();
    await expect(page).toHaveURL(/\/book-demo$/);
    await expect(page.getByRole("heading", { level: 1 })).toContainText("Book a SASA Compliance demonstration");

    await page.goto("/sign-in");
    await expect(page).toHaveURL(/\/sign-in$/);
  });

  test("signing in from the landing page opens the dashboard", async ({ page }) => {
    await signIn(page, ACCOUNTS.grievanceOfficer);
    await expect(page).toHaveURL(/\/dashboard$/);
  });

  test("an unauthenticated visitor is sent to sign in", async ({ page }) => {
    await page.goto("/grievances");
    await expect(page).toHaveURL(/sign-in/);
  });
});
