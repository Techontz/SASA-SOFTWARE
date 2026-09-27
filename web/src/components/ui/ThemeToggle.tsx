"use client";

import { Monitor, Moon, Sun } from "lucide-react";
import { useTheme, type ThemeChoice } from "@/lib/theme";
import { cn } from "@/lib/utils";

const OPTIONS: { value: ThemeChoice; label: string; icon: typeof Sun }[] = [
  { value: "light", label: "Light", icon: Sun },
  { value: "dark", label: "Dark", icon: Moon },
  { value: "system", label: "System", icon: Monitor },
];

/**
 * The top-bar button flips what is on screen, and nothing more. A three-way
 * cycle here reads as broken: someone following their device sits on a light
 * system, presses the button expecting dark, and gets explicit light — the
 * same screen they were already looking at.
 *
 * "Follow my device" is still a first-class choice; it lives in
 * ThemeChoiceGroup on the account page, where there is room to say what it
 * means and to show which one is active.
 */
export function ThemeToggle({
  className,
  onChrome = false,
}: {
  className?: string;
  /** Set on the navy panels (sign-in), where ink tokens would disappear. */
  onChrome?: boolean;
}) {
  const { theme, choice, setChoice } = useTheme();

  const next: ThemeChoice = theme === "dark" ? "light" : "dark";
  const Icon = choice === "system" ? Monitor : theme === "dark" ? Moon : Sun;
  const describe =
    choice === "system" ? `following your device, currently ${theme}` : theme === "dark" ? "dark" : "light";

  return (
    <button
      type="button"
      onClick={() => setChoice(next)}
      className={cn(
        "inline-flex h-10 w-10 items-center justify-center rounded-md transition-colors",
        onChrome
          ? "text-chrome-muted hover:bg-white/10 hover:text-chrome-fg"
          : "text-ink-600 hover:bg-ink-100 hover:text-ink-900",
        className,
      )}
      /* The visible label names the current theme, not the next one, so the
         tooltip and the screen-reader name agree with what is on screen. */
      title={`Theme: ${describe}. Switch to ${next}.`}
      aria-label={`Theme: ${describe}. Switch to ${next}.`}
      data-testid="theme-toggle"
      data-theme-choice={choice}
    >
      <Icon className="h-[1.125rem] w-[1.125rem]" aria-hidden />
    </button>
  );
}

/** The labelled version, for a settings page where there is room to explain. */
export function ThemeChoiceGroup() {
  const { choice, setChoice, theme } = useTheme();

  return (
    <div>
      <div
        role="radiogroup"
        aria-label="Colour theme"
        className="inline-flex rounded-lg border border-hairline bg-surface-sunken p-1"
      >
        {OPTIONS.map(({ value, label, icon: Icon }) => {
          const active = choice === value;
          return (
            <button
              key={value}
              type="button"
              role="radio"
              aria-checked={active}
              onClick={() => setChoice(value)}
              className={cn(
                "inline-flex min-h-11 items-center gap-2 rounded-md px-3.5 text-sm font-medium transition-colors",
                active
                  ? "bg-surface text-ink-900 shadow-[var(--shadow-card)]"
                  : "text-ink-600 hover:text-ink-900",
              )}
            >
              <Icon className="h-4 w-4" aria-hidden />
              {label}
            </button>
          );
        })}
      </div>
      <p className="mt-2 text-sm text-ink-500">
        {choice === "system"
          ? `Following your device, which is currently ${theme}.`
          : `Always ${choice}, on this browser.`}
      </p>
    </div>
  );
}
