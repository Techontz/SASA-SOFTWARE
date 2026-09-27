import { describe, expect, it } from "vitest";
import { daysBetween, formatMetric, humanise, initials, pluralise, truncate, languageName } from "@/lib/utils";

describe("presentation helpers", () => {
  it("turns machine values into words a person reads", () => {
    expect(humanise("under_investigation")).toBe("Under investigation");
    expect(humanise("on_plan")).toBe("On plan");
    expect(humanise(null)).toBe("—");
  });

  it("formats a metric with its unit", () => {
    expect(formatMetric(88.9, "percent")).toBe("88.9%");
    expect(formatMetric(50, "percent")).toBe("50%");
    expect(formatMetric(1234, "count")).toBe("1,234");
    expect(formatMetric(null, "count")).toBe("—");
  });

  it("counts days to a date, signed", () => {
    const inFive = new Date(Date.now() + 5 * 86_400_000).toISOString();
    const fiveAgo = new Date(Date.now() - 5 * 86_400_000).toISOString();

    expect(daysBetween(inFive)).toBe(5);
    expect(daysBetween(fiveAgo)).toBe(-5);
    expect(daysBetween(null)).toBeNull();
  });

  it("makes initials for an avatar", () => {
    expect(initials("Grace Ndosi")).toBe("GN");
    expect(initials("Mwenyekiti Daniel Masanja")).toBe("MD");
    expect(initials(null)).toBe("?");
  });

  it("pluralises without a library", () => {
    expect(pluralise(1, "case")).toBe("case");
    expect(pluralise(2, "case")).toBe("cases");
    expect(pluralise(2, "person", "people")).toBe("people");
  });

  it("truncates with an ellipsis", () => {
    expect(truncate("a".repeat(20), 10)).toHaveLength(10);
    expect(truncate("short", 10)).toBe("short");
  });
});

describe("language names", () => {
  it("uses the name speakers of the language use themselves", () => {
    expect(languageName("sw")).toBe("Kiswahili");
    expect(languageName("en")).toBe("English");
  });

  it("handles a language the product has never been configured with before", () => {
    /* Languages are per-project configuration, so a lookup table would be
       wrong the first time a project adds one SASA has not seen. */
    expect(languageName("fr")).toBe("français");
  });

  it("falls back to the code rather than showing nothing", () => {
    expect(languageName("zzz")).toBe("ZZZ");
  });

  it("says nothing is recorded when nothing is", () => {
    expect(languageName(null)).toBe("—");
    expect(languageName("")).toBe("—");
  });
});
