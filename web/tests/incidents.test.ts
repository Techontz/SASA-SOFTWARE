import { describe, expect, it } from "vitest";
import { groupTypesForCategory, isSeriousIncident, nowForDateTimeInput, parseNamesList } from "@/lib/incidents";
import type { IncidentTypeOption } from "@/types/incident";

const types: IncidentTypeOption[] = [
  { key: "hs_fatality", label: "Health & Safety - Fatality (FAT)", category: "personal_injury", serious: true },
  { key: "hs_fac", label: "Health & Safety - First Aid Case (FAC)", category: "personal_injury", serious: false },
  { key: "sec_theft", label: "Security - Theft", category: "security", serious: false },
  { key: "industrial_action", label: "Industrial Action", category: null, serious: false },
];

describe("groupTypesForCategory", () => {
  it("puts the category's own types first and keeps every other type available", () => {
    const { suggested, others } = groupTypesForCategory(types, "personal_injury");
    expect(suggested.map((t) => t.key)).toEqual(["hs_fatality", "hs_fac"]);
    expect(others.map((t) => t.key)).toEqual(["sec_theft", "industrial_action"]);
  });

  it("offers the whole list when no category is chosen", () => {
    const { suggested, others } = groupTypesForCategory(types, "");
    expect(suggested).toEqual([]);
    expect(others).toHaveLength(4);
  });
});

describe("isSeriousIncident", () => {
  it("flags a potential consequence at or above the configured level", () => {
    expect(isSeriousIncident({ potentialConsequence: 4, type: types[1], seriousLevel: 4 })).toBe(true);
    expect(isSeriousIncident({ potentialConsequence: 3, type: types[1], seriousLevel: 4 })).toBe(false);
  });

  it("flags a Fatality or Permanent Disability type whatever the level", () => {
    expect(isSeriousIncident({ potentialConsequence: 0, type: types[0], seriousLevel: 4 })).toBe(true);
  });

  it("is not serious before anything is chosen", () => {
    expect(isSeriousIncident({ potentialConsequence: null, type: undefined, seriousLevel: 4 })).toBe(false);
  });
});

describe("parseNamesList", () => {
  it("trims, drops blank lines and removes duplicates regardless of case", () => {
    expect(parseNamesList(" Joseph Mrema \n\njoseph mrema\nEng. Neema Kweka\r\n")).toEqual(["Joseph Mrema", "Eng. Neema Kweka"]);
  });
});

describe("nowForDateTimeInput", () => {
  it("formats a local date for a datetime-local input", () => {
    expect(nowForDateTimeInput(new Date(2026, 10, 3, 7, 5))).toBe("2026-11-03T07:05");
  });
});
