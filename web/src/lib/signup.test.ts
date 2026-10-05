import { describe, expect, it } from "vitest";

import { countryForZone, defaultTimezone, presetsFor, timezonesFor, zoneLabel, type SignupOptions } from "@/lib/signup";

const countries: SignupOptions["countries"] = [
  { code: "NP", name: "Nepal", timezones: ["Asia/Kathmandu"] },
  { code: "US", name: "United States", timezones: ["America/Chicago", "America/New_York"] },
];

const presets: SignupOptions["presets"] = [
  { code: "kids", vertical: "tutoring", name: "Kids", summary: "" },
  { code: "lang-eu", vertical: "language", name: "Europe", summary: "" },
  { code: "lang-gulf", vertical: "language", name: "Gulf", summary: "" },
];

describe("signup form helpers", () => {
  it("offers only the starting points for the chosen kind of academy", () => {
    expect(presetsFor("language", presets).map((p) => p.code)).toEqual(["lang-eu", "lang-gulf"]);
    expect(presetsFor("unknown", presets)).toEqual([]);
  });

  it("lists a country's timezones", () => {
    expect(timezonesFor("US", countries)).toEqual(["America/Chicago", "America/New_York"]);
    expect(timezonesFor("XX", countries)).toEqual([]);
  });

  it("prefers the browser's timezone when it belongs to the country", () => {
    expect(defaultTimezone("US", countries, "America/New_York")).toBe("America/New_York");
    expect(defaultTimezone("US", countries, "Asia/Kathmandu")).toBe("America/Chicago");
    expect(defaultTimezone("XX", countries)).toBe("");
  });

  it("guesses the country from the browser's timezone", () => {
    expect(countryForZone("Asia/Kathmandu", countries)).toBe("NP");
    expect(countryForZone("Europe/Paris", countries)).toBe("");
    expect(countryForZone(undefined, countries)).toBe("");
  });

  it("understands the old zone names browsers still report", () => {
    expect(countryForZone("Asia/Katmandu", countries)).toBe("NP");
    expect(defaultTimezone("NP", countries, "Asia/Katmandu")).toBe("Asia/Kathmandu");
  });

  it("labels timezones by place", () => {
    expect(zoneLabel("Asia/Kathmandu")).toBe("Kathmandu");
    expect(zoneLabel("America/Indiana/Tell_City")).toBe("Indiana / Tell City");
    expect(zoneLabel("UTC")).toBe("UTC");
  });
});
