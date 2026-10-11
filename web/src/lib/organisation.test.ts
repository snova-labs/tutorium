import { describe, expect, it } from "vitest";

import { colorOr, dayLabel, isHexColor, localeSummary, timezoneChoices } from "@/lib/organisation";

describe("dayLabel", () => {
  it("names a day as a person writes it", () => {
    expect(dayLabel("friday")).toBe("Friday");
  });
});

describe("localeSummary", () => {
  it("says where a location is in time and which days it rests", () => {
    expect(
      localeSummary({ locale_rules: { timezone: "Asia/Dubai", week_start: "sunday", weekend_days: ["friday", "saturday"] } }),
    ).toBe("Asia/Dubai · week starts Sunday · weekend Friday and Saturday");
  });

  it("is explicit when no weekend is set, rather than silently showing none", () => {
    expect(localeSummary({ locale_rules: { timezone: "UTC" } })).toBe("UTC · no weekend set");
  });
});

describe("timezoneChoices", () => {
  it("keeps the zone in use, even one the platform does not list", () => {
    const choices = timezoneChoices("Mars/Olympus");

    expect(choices[0]).toBe("Mars/Olympus");
    expect(choices).toContain("Asia/Dubai");
  });

  it("offers real zones without one in use", () => {
    expect(timezoneChoices()).toContain("UTC");
  });
});

describe("isHexColor", () => {
  it("takes the two forms a stylesheet understands", () => {
    expect(isHexColor("#1B5FAA")).toBe(true);
    expect(isHexColor(" #abc ")).toBe(true);
  });

  it("refuses anything that is not one", () => {
    // What a style attribute must never be handed.
    expect(isHexColor("red; } body { display:none")).toBe(false);
    expect(isHexColor("1B5FAA")).toBe(false);
    expect(isHexColor("#12345")).toBe(false);
  });
});

describe("colorOr", () => {
  it("prefers the brand's colour and falls back to the product's", () => {
    expect(colorOr({ primary: "#001122" }, "primary")).toBe("#001122");
    expect(colorOr({ primary: "nonsense" }, "primary")).toBe("#1B5FAA");
    expect(colorOr(null, "accent")).toBe("#7C3AED");
  });
});
