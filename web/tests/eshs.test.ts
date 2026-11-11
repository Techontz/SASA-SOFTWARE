import { describe, expect, it } from "vitest";
import { formatRate, parseList, riskTone, stageTone, subcategoriesFor, targetLabel } from "@/lib/eshs";

describe("ESHS conditional formatting", () => {
  it("colours Critical as danger, High as orange and Closed as success", () => {
    expect(riskTone("critical")).toBe("danger");
    expect(riskTone("high")).toBe("accent");
    expect(riskTone("medium")).toBe("warning");
    expect(riskTone("low")).toBe("neutral");
    expect(stageTone("closed")).toBe("success");
    expect(stageTone("closed", true)).toBe("success");
    expect(stageTone("open", true)).toBe("danger");
    expect(stageTone("awaiting_verification")).toBe("warning");
  });
});

describe("dependent subcategories", () => {
  const categories = [
    { key: "environment", label: "Environment", subcategories: ["Dust", "Noise & Vibration"] },
    { key: "safety", label: "Safety", subcategories: ["Work at Height"] },
  ];

  it("lists only the chosen category's subcategories", () => {
    expect(subcategoriesFor(categories, "safety")).toEqual(["Work at Height"]);
    expect(subcategoriesFor(categories, "")).toEqual([]);
    expect(subcategoriesFor(categories, "unknown")).toEqual([]);
    expect(subcategoriesFor(undefined, "safety")).toEqual([]);
  });
});

describe("target and rate labels", () => {
  it("describes the target date relative to today, and nothing once closed", () => {
    expect(targetLabel(-3, false)).toBe("3 days over");
    expect(targetLabel(-1, false)).toBe("1 day over");
    expect(targetLabel(0, false)).toBe("due today");
    expect(targetLabel(5, false)).toBe("in 5 days");
    expect(targetLabel(-3, true)).toBeNull();
    expect(targetLabel(null, false)).toBeNull();
  });

  it("shows a dash rather than 0% when nothing was raised", () => {
    expect(formatRate(null)).toBe("—");
    expect(formatRate(33.3)).toBe("33.3%");
  });
});

describe("list parsing for the contractor settings", () => {
  it("trims, drops blanks and repeats", () => {
    expect(parseList(" Alpha Ltd \n\nBeta Ltd\nalpha ltd\r\nGamma")).toEqual(["Alpha Ltd", "Beta Ltd", "Gamma"]);
  });
});
