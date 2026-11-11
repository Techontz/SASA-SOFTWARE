import { describe, expect, it } from "vitest";
import { formatMoney, formatMoneyCompact, formatQuantity, toPayloadValue } from "@/lib/pap/format";

describe("PAP money and figures", () => {
  it("writes shillings the same way everywhere", () => {
    expect(formatMoney(12110000)).toBe("TZS 12,110,000");
    expect(formatMoney("540000.00")).toBe("TZS 540,000");
    expect(formatMoney(null)).toBe("—");
  });

  it("keeps a KPI tile short enough for a phone", () => {
    expect(formatMoneyCompact(12_110_000)).toBe("TZS 12.1M");
    expect(formatMoneyCompact(1_250_000_000)).toBe("TZS 1.3bn");
    expect(formatMoneyCompact(450_000)).toBe("TZS 450k");
    expect(formatMoneyCompact(9_500)).toBe("TZS 9,500");
    expect(formatMoneyCompact(null)).toBe("—");
  });

  it("shows quantities with their unit", () => {
    expect(formatQuantity(0.75, "ha")).toBe("0.75 ha");
    expect(formatQuantity(null)).toBe("—");
  });

  it("turns form input into API values", () => {
    expect(toPayloadValue("", "number")).toBeNull();
    expect(toPayloadValue("1,200,000", "number")).toBe(1200000);
    expect(toPayloadValue("  Plot 14 ", "text")).toBe("Plot 14");
    expect(toPayloadValue(true, "boolean")).toBe(true);
  });
});
