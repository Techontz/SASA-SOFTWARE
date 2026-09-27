"use client";

import { useSyncExternalStore } from "react";

export type Theme = "light" | "dark";
export type ThemeChoice = Theme | "system";

export const THEME_STORAGE_KEY = "sasa-theme";

/**
 * The theme lives in one place only: the `data-theme` attribute on <html>.
 *
 * Nothing holds a copy of it in React state, because a second copy is a second
 * thing that can be wrong — and because the attribute has to be set before
 * React exists at all (see THEME_INIT_SCRIPT) or the first paint flashes the
 * wrong theme. Components read it through useTheme(), which subscribes to the
 * attribute rather than to a store.
 *
 * The user's *choice* is what gets saved: "light", "dark", or "system". Saving
 * the resolved theme instead would freeze someone who picked "system" into
 * whichever theme they happened to be in when they chose it.
 */

/** What a first-time visitor gets: whatever their operating system is set to. */
export const DEFAULT_CHOICE: ThemeChoice = "system";

/**
 * Runs in <head> before the first paint. It is deliberately terse and has no
 * dependencies — it is inlined into the HTML document, so it cannot import.
 *
 * `suppressHydrationWarning` on <html> covers the attribute this adds, since
 * the server cannot know which theme to render.
 */
export const THEME_INIT_SCRIPT = `(function(){try{var c=localStorage.getItem("${THEME_STORAGE_KEY}");var t=(c==="light"||c==="dark")?c:(window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light");document.documentElement.setAttribute("data-theme",t);document.documentElement.style.colorScheme=t}catch(e){document.documentElement.setAttribute("data-theme","light")}})()`;

function systemTheme(): Theme {
  return typeof window !== "undefined" &&
    window.matchMedia?.("(prefers-color-scheme: dark)").matches
    ? "dark"
    : "light";
}

export function readChoice(): ThemeChoice {
  try {
    const saved = window.localStorage.getItem(THEME_STORAGE_KEY);
    if (saved === "light" || saved === "dark" || saved === "system") return saved;
  } catch {
    // Storage blocked (private browsing, embedded webview): fall through.
  }
  return DEFAULT_CHOICE;
}

function resolve(choice: ThemeChoice): Theme {
  return choice === "system" ? systemTheme() : choice;
}

function apply(theme: Theme): void {
  const root = document.documentElement;
  root.setAttribute("data-theme", theme);
  /* Keeps the browser's own widgets — date pickers, scrollbars, native
     selects — in step with the theme. */
  root.style.colorScheme = theme;
  /* The browser chrome on a phone (the address bar, the status bar) reads the
     theme-color meta, so it matches the app instead of staying pale. */
  document
    .querySelector('meta[name="theme-color"]')
    ?.setAttribute("content", theme === "dark" ? "#08111d" : "#0d1b2c");
}

/** Records the choice and applies it. Other tabs follow via the storage event. */
export function setThemeChoice(choice: ThemeChoice): void {
  try {
    window.localStorage.setItem(THEME_STORAGE_KEY, choice);
  } catch {
    // Unavailable storage still leaves the theme applied for this page view.
  }
  apply(resolve(choice));
  window.dispatchEvent(new Event(THEME_CHOICE_EVENT));
}

const THEME_CHOICE_EVENT = "sasa:theme-choice";

function subscribe(onChange: () => void): () => void {
  /* The attribute is the source of truth, so watch the attribute. */
  const observer = new MutationObserver(onChange);
  observer.observe(document.documentElement, {
    attributes: true,
    attributeFilter: ["data-theme"],
  });

  /* Someone changing the theme in another tab should change it here too. */
  const onStorage = (event: StorageEvent) => {
    if (event.key !== THEME_STORAGE_KEY) return;
    apply(resolve(readChoice()));
    onChange();
  };

  /* Someone on "system" should follow the system when it changes, including
     the automatic switch at dusk. */
  const media = window.matchMedia?.("(prefers-color-scheme: dark)");
  const onSystem = () => {
    if (readChoice() === "system") apply(systemTheme());
  };

  window.addEventListener("storage", onStorage);
  window.addEventListener(THEME_CHOICE_EVENT, onChange);
  media?.addEventListener("change", onSystem);

  return () => {
    observer.disconnect();
    window.removeEventListener("storage", onStorage);
    window.removeEventListener(THEME_CHOICE_EVENT, onChange);
    media?.removeEventListener("change", onSystem);
  };
}

function currentTheme(): Theme {
  return document.documentElement.getAttribute("data-theme") === "dark" ? "dark" : "light";
}

function currentChoice(): ThemeChoice {
  return readChoice();
}

/**
 * `theme` is what is on screen now; `choice` is what the user asked for, which
 * is what a settings control should show. The server snapshot is "light"
 * because the server has no way to know — the init script has already
 * corrected it by the time anything is visible.
 */
export function useTheme(): {
  theme: Theme;
  choice: ThemeChoice;
  setChoice: (choice: ThemeChoice) => void;
  toggle: () => void;
} {
  const theme = useSyncExternalStore(subscribe, currentTheme, () => "light" as Theme);
  const choice = useSyncExternalStore(subscribe, currentChoice, () => DEFAULT_CHOICE);

  return {
    theme,
    choice,
    setChoice: setThemeChoice,
    toggle: () => setThemeChoice(theme === "dark" ? "light" : "dark"),
  };
}
