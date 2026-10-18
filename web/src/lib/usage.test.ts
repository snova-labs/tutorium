import { describe, expect, it } from "vitest";

import {
  dailyBars,
  entitlementValue,
  hasFallen,
  inPlanOrder,
  invoiceTone,
  licenceNotice,
  meterHeadline,
  outstanding,
} from "@/lib/usage";
import type { LicenceStatus, UsageInvoice, UsageMeter } from "@/lib/types";

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

describe("licenceNotice", () => {
  const status = (overrides: Partial<LicenceStatus> = {}): LicenceStatus => ({
    ok: true,
    problem: null,
    learners_now: 90,
    places_left: 10,
    warning: "90 of the 100 learner places on the licence are in use.",
    licence: {
      id: "LIC-2026-0001",
      licensee: "Sunrise Academy",
      issued_on: "2026-01-01",
      expires_on: "2027-01-01",
      days_left: 200,
      max_learners: 100,
      notes: null,
      grace_ends_on: "2027-01-31",
      in_grace: false,
      lapsed: false,
    },
    ...overrides,
  });

  it("says nothing to a hosted academy, which has no licence", () => {
    expect(licenceNotice(null)).toBeNull();
  });

  it("says nothing while there is nothing to say", () => {
    expect(licenceNotice(status({ warning: null }))).toBeNull();
  });

  it("warns while the cap is filling", () => {
    expect(licenceNotice(status())).toEqual({
      tone: "warn",
      message: "90 of the 100 learner places on the licence are in use.",
      expires: "2027-01-01",
    });
  });

  it("says the cap is full in a way that says what still works", () => {
    const full = status({ learners_now: 100, places_left: 0, warning: "All 100 places." });

    expect(licenceNotice(full)?.message).toContain("All 100 learner places");
    expect(licenceNotice(full)?.message).toContain("carries on as normal");
  });

  it("writes the dates the way the reader reads dates", () => {
    // The server says the same thing in ISO for a terminal. On a screen it is the reader's date.
    const lapsed = licenceNotice(
      status({
        ok: false,
        warning: "The licence expired on 2026-07-10.",
        licence: { ...status().licence!, expires_on: "2026-07-10", grace_ends_on: "2026-08-09", lapsed: true },
      }),
    );

    expect(lapsed?.tone).toBe("bad");
    expect(lapsed?.message).toBe(
      "The licence expired on 10 Jul 2026 and its grace period ended on 9 Aug 2026. New enrollments are " +
        "refused; attendance, grades, reports and exports all still work.",
    );
  });

  it("says when an expired licence stops working, while it still does", () => {
    const grace = licenceNotice(
      status({
        warning: "Expired.",
        licence: { ...status().licence!, expires_on: "2026-10-01", grace_ends_on: "2026-10-31", in_grace: true },
      }),
    );

    expect(grace?.tone).toBe("warn");
    expect(grace?.message).toContain("keeps working until 31 Oct 2026");
  });

  it("counts down the last month rather than waiting for the day", () => {
    const soon = licenceNotice(status({ licence: { ...status().licence!, days_left: 1 } }));

    expect(soon?.message).toBe("The licence runs out on 1 Jan 2027, in a day.");
  });

  it("copes with a licence nobody could read at all", () => {
    const unreadable = status({ ok: false, warning: "No licence file at /var/www/licence.json.", licence: null });

    expect(licenceNotice(unreadable)).toEqual({
      tone: "bad",
      message: "No licence file at /var/www/licence.json.",
      expires: null,
    });
  });
});
