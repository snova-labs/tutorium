import { describe, expect, it } from "vitest";

import { formatDateTimeIn, formatLocalDate, formatTimeIn, viewerTimeDiffers } from "@/lib/time";

describe("formatLocalDate", () => {
  it("shows the agreed calendar day, whatever zone the code runs in", () => {
    // The test runs at UTC-10 (see vitest.config). A date parsed as UTC midnight would shift.
    expect(formatLocalDate("2026-10-31")).toBe("Sat 31 Oct");
    expect(formatLocalDate("2026-01-01")).toBe("Thu 1 Jan");
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
