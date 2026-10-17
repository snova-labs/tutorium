import { describe, expect, it } from "vitest";

import {
  dailyBars,
  entitlementValue,
  hasFallen,
  inPlanOrder,
  invoiceTone,
  meterHeadline,
  outstanding,
} from "@/lib/usage";
import type { UsageInvoice, UsageMeter } from "@/lib/types";

const meter = (overrides: Partial<UsageMeter> = {}): UsageMeter => ({
  today: 42,
  peak: 42,
  peak_on: "2026-10-09",
  will_be_billed_for: 42,
  basis: "The highest daily count in the period.",
  period: { start: "2026-10-01", end: "2026-10-31" },
  daily: [],
  measured_through: "2026-10-16",
  ...overrides,
});

const invoice = (overrides: Partial<UsageInvoice> = {}): UsageInvoice => ({
  number: "INV-0001",
  period: { start: "2026-09-01", end: "2026-09-30" },
  quantity: 40,
  basis_date: "2026-09-12",
  total: "4000.00",
  status: "paid",
  notes: null,
  ...overrides,
});

describe("meterHeadline", () => {
  it("says the figure once when today is the peak", () => {
    expect(meterHeadline(meter())).toBe("42 learners this period.");
    expect(meterHeadline(meter({ today: 1, peak: 1 }))).toBe("1 learner this period.");
  });

  it("says the peak and the day it fell on when the figure has come down", () => {
    // The invoice bills the peak, so somebody looking at a lower number today must be told.
    expect(meterHeadline(meter({ today: 38, peak: 42 }))).toBe(
      "42 learners: the highest count so far on 9 October. Today it is 38.",
    );
    expect(hasFallen(meter({ today: 38, peak: 42 }))).toBe(true);
    expect(hasFallen(meter())).toBe(false);
  });

  it("copes with a peak day it does not know", () => {
    expect(meterHeadline(meter({ today: 38, peak: 42, peak_on: null }))).toBe(
      "42 learners: the highest count so far. Today it is 38.",
    );
  });
});

describe("dailyBars", () => {
  it("scales to the highest day, and keeps a zero day visible", () => {
    const bars = dailyBars(
      meter({
        daily: [
          { date: "2026-10-01", count: 0, corrected: false },
          { date: "2026-10-02", count: 20, corrected: false },
          { date: "2026-10-03", count: 40, corrected: true },
        ],
      }),
    );

    expect(bars.map((b) => b.percent)).toEqual([1, 50, 100]);
    expect(bars[2].corrected).toBe(true);
  });

  it("does not divide by zero on an empty period", () => {
    expect(dailyBars(meter({ daily: [] }))).toEqual([]);
  });
});

describe("entitlementValue", () => {
  it("tells unlimited apart from zero", () => {
    expect(entitlementValue(null)).toBe("Unlimited");
    expect(entitlementValue(0)).toBe("0");
  });

  it("reads a switch as a switch", () => {
    expect(entitlementValue(true)).toBe("Included");
    expect(entitlementValue(false)).toBe("Not included");
  });

  it("never prints an object, however the value arrives", () => {
    // A wrapped value reached this screen from an older release and read "[object Object]".
    expect(entitlementValue({ value: null })).toBe("Unlimited");
    expect(entitlementValue({ value: 3 })).toBe("3");
    expect(entitlementValue({})).toBe("Unlimited");
  });
});

describe("inPlanOrder", () => {
  it("puts what this account was given ahead of the product's baseline", () => {
    const ordered = inPlanOrder([
      { key: "b", value: 1, source: "default", enforcement: "soft", reason: null },
      { key: "a", value: 1, source: "plan", enforcement: "hard", reason: null },
      { key: "c", value: 1, source: "override", enforcement: "hard", reason: "Agreed on the call" },
    ]);

    expect(ordered.map((e) => e.key)).toEqual(["c", "a", "b"]);
  });
});

describe("invoices", () => {
  it("alarms only for what is actually wrong", () => {
    expect(invoiceTone("paid")).toBe("good");
    expect(invoiceTone("overdue")).toBe("bad");
    expect(invoiceTone("failed")).toBe("bad");
    expect(invoiceTone("open")).toBe("muted");
  });

  it("counts what is still owed, voids aside", () => {
    const rows = [invoice(), invoice({ number: "INV-2", status: "open" }), invoice({ number: "INV-3", status: "void" })];

    expect(outstanding(rows).map((i) => i.number)).toEqual(["INV-2"]);
  });
});
