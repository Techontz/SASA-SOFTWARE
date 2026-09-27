import { expect, test } from "@playwright/test";
import { ACCOUNTS, settle, signIn } from "./helpers";

/**
 * The theme has to survive the two things that break most theme switchers: a
 * full page load, and a navigation. It also has to arrive before the first
 * paint — a flash of the wrong theme is the failure everyone notices.
 */
test.describe("choosing a theme", () => {
  test("a choice survives a reload and follows the user from page to page", async ({ page }) => {
    await signIn(page, ACCOUNTS.projectAdmin);
    await settle(page);

    await expect(page.locator("html")).toHaveAttribute("data-theme", "light");

    await page.getByTestId("theme-toggle").click();
    await expect(page.locator("html")).toHaveAttribute("data-theme", "dark");

    await page.goto("/grievances");
    await settle(page);
    await expect(page.locator("html")).toHaveAttribute("data-theme", "dark");

    await page.reload();
    await settle(page);
    await expect(page.locator("html")).toHaveAttribute("data-theme", "dark");
  });

  test("the dark theme is applied while the document is still parsing", async ({ page }) => {
    await signIn(page, ACCOUNTS.projectAdmin);
    await page.getByTestId("theme-toggle").click();
    await expect(page.locator("html")).toHaveAttribute("data-theme", "dark");

    /*
     * Sample the theme at DOMContentLoaded, from a script registered before any
     * of the page's own. React has not hydrated at that point, so if the
     * attribute already says "dark" it can only have been set by the inline
     * script while the document was parsing — which is before the first paint.
     * Had React been responsible, this would still read "light" and the user
     * would see the white flash we are preventing.
     *
     * (An iframe would be the obvious probe, but SASA sends
     * X-Frame-Options: DENY, so it cannot be framed — by design.)
     */
    await page.addInitScript(() => {
      const w = window as unknown as { __themeAtParse?: string | null };
      document.addEventListener("DOMContentLoaded", () => {
        w.__themeAtParse = document.documentElement.getAttribute("data-theme");
      });
    });

    await page.goto("/stakeholders");
    await settle(page);

    const themeAtParse = await page.evaluate(
      () => (window as unknown as { __themeAtParse?: string | null }).__themeAtParse,
    );

    expect(themeAtParse).toBe("dark");
    await expect(page.locator("html")).toHaveAttribute("data-theme", "dark");
  });

  test("the page really is dark, not just labelled dark", async ({ page }) => {
    await signIn(page, ACCOUNTS.projectAdmin);
    await settle(page);

    const luminance = async () =>
      page.evaluate(() => {
        const rgb = getComputedStyle(document.body).backgroundColor.match(/\d+/g) ?? ["255", "255", "255"];
        const [r, g, b] = rgb.slice(0, 3).map(Number);
        return 0.2126 * r + 0.7152 * g + 0.0722 * b;
      });

    const light = await luminance();
    await page.getByTestId("theme-toggle").click();
    await expect(page.locator("html")).toHaveAttribute("data-theme", "dark");
    const dark = await luminance();

    expect(light).toBeGreaterThan(200);
    expect(dark).toBeLessThan(60);
  });

  test("a field officer can set the theme before signing in", async ({ page }) => {
    await page.goto("/sign-in");
    await page.waitForSelector("#email");

    await page.getByTestId("theme-toggle").click();

    await expect(page.locator("html")).toHaveAttribute("data-theme", "dark");
  });

  test("the account page explains what following the device means", async ({ page }) => {
    await signIn(page, ACCOUNTS.fieldOfficer);
    await page.goto("/account");
    await settle(page);

    await page.getByRole("radio", { name: "System" }).click();

    await expect(page.getByText(/Following your device/i)).toBeVisible();
  });
});
