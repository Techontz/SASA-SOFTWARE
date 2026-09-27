import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { ThemeChoiceGroup, ThemeToggle } from "@/components/ui/ThemeToggle";
import { readChoice, setThemeChoice, THEME_INIT_SCRIPT, THEME_STORAGE_KEY } from "@/lib/theme";

/**
 * The theme is the one piece of SASA that has to be right *before* React runs:
 * an inline script sets it during HTML parsing so the page never paints light
 * and then snap to dark. These tests cover that script as a string, because
 * that is how it ships.
 */

/** Runs the inline script the way the browser does when parsing <head>. */
function runInitScript() {
  // eslint-disable-next-line no-new-func -- exercising the shipped script text
  new Function(THEME_INIT_SCRIPT)();
}

function setSystemPrefersDark(dark: boolean) {
  Object.defineProperty(window, "matchMedia", {
    writable: true,
    value: vi.fn().mockImplementation((query: string) => ({
      matches: dark && query.includes("dark"),
      media: query,
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
      addListener: vi.fn(),
      removeListener: vi.fn(),
      dispatchEvent: vi.fn(),
    })),
  });
}

beforeEach(() => {
  document.documentElement.removeAttribute("data-theme");
  document.documentElement.style.colorScheme = "";
  setSystemPrefersDark(false);
});

afterEach(() => {
  document.head.querySelector('meta[name="theme-color"]')?.remove();
});

describe("the pre-paint theme script", () => {
  it("applies a saved theme before React has rendered anything", () => {
    localStorage.setItem(THEME_STORAGE_KEY, "dark");

    runInitScript();

    expect(document.documentElement.getAttribute("data-theme")).toBe("dark");
    expect(document.documentElement.style.colorScheme).toBe("dark");
  });

  it("follows the operating system when the user has not chosen", () => {
    setSystemPrefersDark(true);

    runInitScript();

    expect(document.documentElement.getAttribute("data-theme")).toBe("dark");
  });

  it("lets an explicit choice override the operating system", () => {
    setSystemPrefersDark(true);
    localStorage.setItem(THEME_STORAGE_KEY, "light");

    runInitScript();

    expect(document.documentElement.getAttribute("data-theme")).toBe("light");
  });

  it("still picks a theme when storage throws", () => {
    const getItem = vi.spyOn(Storage.prototype, "getItem").mockImplementation(() => {
      throw new Error("blocked in private browsing");
    });

    runInitScript();

    expect(document.documentElement.getAttribute("data-theme")).toBe("light");
    getItem.mockRestore();
  });

  it("never writes a value the stylesheet does not define", () => {
    localStorage.setItem(THEME_STORAGE_KEY, "midnight-neon");

    runInitScript();

    expect(document.documentElement.getAttribute("data-theme")).toBe("light");
  });
});

describe("choosing a theme", () => {
  it("records the choice rather than the theme it resolved to", () => {
    setSystemPrefersDark(true);

    setThemeChoice("system");

    /* Saving "dark" here would freeze the user into whichever theme they
       happened to be in at the moment they chose to follow their device. */
    expect(localStorage.getItem(THEME_STORAGE_KEY)).toBe("system");
    expect(document.documentElement.getAttribute("data-theme")).toBe("dark");
    expect(readChoice()).toBe("system");
  });

  it("keeps the phone's browser chrome in step with the app", () => {
    const meta = document.createElement("meta");
    meta.setAttribute("name", "theme-color");
    meta.setAttribute("content", "#ffffff");
    document.head.appendChild(meta);

    setThemeChoice("dark");

    expect(meta.getAttribute("content")).toBe("#08111d");
  });

  it("applies the theme even when the choice cannot be saved", () => {
    const setItem = vi.spyOn(Storage.prototype, "setItem").mockImplementation(() => {
      throw new Error("quota");
    });

    setThemeChoice("dark");

    expect(document.documentElement.getAttribute("data-theme")).toBe("dark");
    setItem.mockRestore();
  });
});

describe("the theme control", () => {
  it("flips what is on screen, every time it is pressed", async () => {
    const user = userEvent.setup();
    setThemeChoice("light");
    render(<ThemeToggle />);

    await user.click(screen.getByTestId("theme-toggle"));
    expect(document.documentElement.getAttribute("data-theme")).toBe("dark");

    await user.click(screen.getByTestId("theme-toggle"));
    expect(document.documentElement.getAttribute("data-theme")).toBe("light");
  });

  it("changes the screen on the first press even when following the device", async () => {
    const user = userEvent.setup();
    /* The trap: "system" on a light device resolves to light, so a cycle that
       went to explicit light would look like a dead button. */
    setThemeChoice("system");
    render(<ThemeToggle />);
    expect(document.documentElement.getAttribute("data-theme")).toBe("light");

    await user.click(screen.getByTestId("theme-toggle"));

    expect(document.documentElement.getAttribute("data-theme")).toBe("dark");
  });

  it("names the theme that is on screen, so the label is never a riddle", () => {
    setThemeChoice("dark");
    render(<ThemeToggle />);

    expect(screen.getByTestId("theme-toggle")).toHaveAccessibleName(/Theme: dark/i);
  });

  it("says it is following the device when it is", () => {
    setThemeChoice("system");
    render(<ThemeToggle />);

    expect(screen.getByTestId("theme-toggle")).toHaveAccessibleName(/following your device/i);
  });

  it("offers following the device as a real choice, not only light and dark", async () => {
    const user = userEvent.setup();
    setThemeChoice("light");
    render(<ThemeChoiceGroup />);

    const system = screen.getByRole("radio", { name: "System" });
    expect(system).toHaveAttribute("aria-checked", "false");

    await user.click(system);

    expect(screen.getByRole("radio", { name: "System" })).toHaveAttribute("aria-checked", "true");
    expect(readChoice()).toBe("system");
  });
});
