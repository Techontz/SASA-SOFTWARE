import { expect, test } from "@playwright/test";
import { ACCOUNTS, signIn } from "./helpers";

/* The public pages and the two enquiry forms, through to the admin's list. */

test.describe("public site and enquiries", () => {
  test("every public page opens with its own heading", async ({ page }) => {
    for (const [path, heading] of [
      ["/platform", "One platform for connected project workflows"],
      ["/industries", "Built around the realities of project delivery"],
      ["/about", "Born from the realities of project delivery"],
      ["/register-interest", "Register your interest in SASA Compliance"],
      ["/book-demo", "Book a SASA Compliance demonstration"],
      ["/data-protection", "Protecting the people a project records"],
      ["/privacy", "Privacy and website terms"],
    ] as const) {
      await page.goto(path);
      await expect(page.getByRole("heading", { level: 1 })).toContainText(heading);
    }
  });

  test("the form asks for the required fields before it sends", async ({ page }) => {
    await page.goto("/register-interest");
    await page.getByRole("button", { name: "Register interest" }).last().click();
    await expect(page.getByText("Enter your full name.")).toBeVisible();
    await expect(page.getByText("Choose your sector.")).toBeVisible();
  });

  test("a demo request is received and reaches the administrator", async ({ page }) => {
    const organization = `Demo Org ${Date.now()}`;
    await page.goto("/book-demo");
    await page.getByLabel(/Full name/).fill("Neema Test");
    await page.getByLabel(/E-mail address/).fill("neema@example.com");
    await page.getByLabel(/Organization/).fill(organization);
    await page.getByLabel(/Sector/).selectOption("energy");
    await page.getByRole("checkbox", { name: "Grievance Management" }).click();
    await page.getByRole("checkbox", { name: "Not sure yet" }).click();
    await expect(page.getByRole("checkbox", { name: "Grievance Management" })).toHaveAttribute("aria-checked", "false");
    await page.getByRole("button", { name: "Request a demonstration" }).click();
    await expect(page.getByText(/Reference ENQ-/)).toBeVisible();

    await signIn(page, ACCOUNTS.admin);
    await page.goto("/enquiries");
    await expect(page.getByText(organization)).toBeVisible();
  });
});
