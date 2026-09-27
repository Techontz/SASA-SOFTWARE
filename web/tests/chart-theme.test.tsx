import { renderHook } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { useChartTheme } from "@/lib/chartTheme";
import { setThemeChoice } from "@/lib/theme";

/**
 * The chart palette is the one part of SASA's colour that cannot be a CSS
 * variable, because Recharts writes SVG presentation attributes. So it is the
 * one part that a stylesheet change cannot keep honest — these tests do that
 * instead.
 *
 * The full six-check validation (colour-blind separation, chroma, lightness
 * band) lives in the data-visualisation validator and is recorded in
 * lib/chartTheme.ts. What is guarded here is what a careless edit would break.
 */

const luminance = (hex: string) => {
  const channel = (value: number) => {
    const c = value / 255;
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
  };
  const n = parseInt(hex.slice(1), 16);
  return 0.2126 * channel((n >> 16) & 255) + 0.7152 * channel((n >> 8) & 255) + 0.0722 * channel(n & 255);
};

const contrast = (a: string, b: string) => {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return (hi + 0.05) / (lo + 0.05);
};

function themeFor(choice: "light" | "dark") {
  setThemeChoice(choice);
  return renderHook(() => useChartTheme()).result.current;
}

describe.each(["light", "dark"] as const)("the %s chart palette", (choice) => {
  it("gives every series its own colour", () => {
    const { series } = themeFor(choice);

    expect(new Set(series).size).toBe(series.length);
  });

  it("keeps the leading three slots visible against the surface on their own", () => {
    const theme = themeFor(choice);

    /*
     * 3:1 is the floor for a graphical object — a 2px line has to be findable
     * before its colour can mean anything.
     *
     * The first three slots clear it unaided, which is what a chart of one to
     * three series needs. Past three, some of the light steps sit below it
     * (yellow and magenta cannot be both chromatic enough to read as
     * themselves and dark enough to clear white), and those charts carry the
     * relief the data-visualisation rules require instead: a legend is always
     * present for two or more series, and the donut labels its slices.
     */
    for (const colour of theme.series.slice(0, 3)) {
      expect(contrast(colour, theme.surface)).toBeGreaterThanOrEqual(3);
    }
  });

  it("never lets a series disappear into the surface entirely", () => {
    const theme = themeFor(choice);

    for (const colour of theme.series) {
      expect(contrast(colour, theme.surface)).toBeGreaterThanOrEqual(2);
    }
  });

  it("keeps status colours out of the series palette", () => {
    const theme = themeFor(choice);

    /* Reusing "critical" as series four would tell a reader something is wrong
       when nothing is. */
    for (const status of Object.values(theme.status)) {
      expect(theme.series).not.toContain(status);
    }
  });

  it("writes axis and tooltip text in a text tone, not a series colour", () => {
    const theme = themeFor(choice);

    expect(theme.series).not.toContain(theme.text);
    expect(theme.series).not.toContain(theme.axis);
    expect(contrast(theme.text, theme.surface)).toBeGreaterThanOrEqual(4.5);
  });
});

describe("switching theme", () => {
  it("restyles the charts rather than leaving them in the old theme", () => {
    const light = themeFor("light");
    const dark = themeFor("dark");

    expect(dark.series).not.toEqual(light.series);
    expect(dark.grid).not.toBe(light.grid);
    expect(dark.surface).not.toBe(light.surface);
  });

  it("keeps a series on the same slot, so a filter cannot repaint it", () => {
    const light = themeFor("light");
    const dark = themeFor("dark");

    /* Colour follows the entity. The two themes must therefore agree on how
       many slots there are and what order they come in. */
    expect(dark.series).toHaveLength(light.series.length);
  });
});
