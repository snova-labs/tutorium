import { describe, expect, it } from "vitest";

import {
  formatDateTimeIn,
  formatLocalDate,
  formatLocalDateShort,
  formatLocalRange,
  formatTimeIn,
  viewerTimeDiffers,
} from "@/lib/time";

describe("formatLocalDate", () => {
  it("shows the agreed calendar day, whatever zone the code runs in", () => {
    // The test runs at UTC-10 (see vitest.config). A date parsed as UTC midnight would shift.
    expect(formatLocalDate("2026-10-31")).toBe("Sat 31 Oct");
    expect(formatLocalDate("2026-01-01")).toBe("Thu 1 Jan");
  });
});

describe("formatLocalDateShort", () => {
  it("leaves out the weekday and keeps the year", () => {
    // Also at UTC-10: a date parsed as UTC midnight would land on the day before.
    expect(formatLocalDateShort("2026-10-31")).toBe("31 Oct 2026");
    expect(formatLocalDateShort("2026-01-01")).toBe("1 Jan 2026");
  });
});

describe("formatLocalRange", () => {
  it("names the month once when both ends share it", () => {
    expect(formatLocalRange("2026-10-01", "2026-10-31")).toBe("1–31 October 2026");
  });

  it("spells out both ends when the span crosses a month or a year", () => {
    expect(formatLocalRange("2026-10-15", "2026-11-14")).toBe("15 Oct 2026 – 14 Nov 2026");
    expect(formatLocalRange("2026-12-15", "2027-01-14")).toBe("15 Dec 2026 – 14 Jan 2027");
  });
});

describe("times in a zone", () => {
  it("reads one instant on each clock", () => {
    const instant = "2026-11-01T01:00:00Z"; // 21:00 on 31 Oct in Toronto (EDT)

    expect(formatTimeIn(instant, "America/Toronto")).toBe("21:00");
    expect(formatTimeIn(instant, "Asia/Kathmandu")).toBe("06:45");
    expect(formatDateTimeIn(instant, "Asia/Kathmandu")).toContain("1 Nov");
  });
});

describe("viewerTimeDiffers", () => {
  const instant = "2026-11-01T01:00:00Z";

  it("is false when the viewer has no zone, or the same one", () => {
    expect(viewerTimeDiffers(instant, "America/Toronto", null)).toBe(false);
    expect(viewerTimeDiffers(instant, "America/Toronto", undefined)).toBe(false);
    expect(viewerTimeDiffers(instant, "America/Toronto", "America/Toronto")).toBe(false);
  });

  it("is false for a different zone that reads the same clock", () => {
    // Different names, same offset at this instant: a second line would only add noise.
    expect(viewerTimeDiffers(instant, "Asia/Kolkata", "Asia/Colombo")).toBe(false);
  });

  it("is true when the viewer's clock reads differently", () => {
    expect(viewerTimeDiffers(instant, "America/Toronto", "Asia/Kathmandu")).toBe(true);
  });
});
