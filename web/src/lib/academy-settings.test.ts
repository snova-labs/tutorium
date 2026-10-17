import { describe, expect, it } from "vitest";

import { canRevert, origin, revertsTo, settingValue } from "@/lib/academy-settings";
import type { AcademySetting } from "@/lib/types";

const setting = (overrides: Partial<AcademySetting> = {}): AcademySetting => ({
  key: "attendance.compulsory",
  value: true,
  type: "switch",
  label: "Registers must be taken",
  help: "",
  choices: null,
  min: null,
  max: null,
  source: "system",
  origin: null,
  set_here: false,
  shipped_default: true,
  ...overrides,
});

describe("settingValue", () => {
  it("reads each type as a person would say it", () => {
    expect(settingValue(setting())).toBe("Yes");
    expect(settingValue(setting({ value: false }))).toBe("No");
    expect(settingValue(setting({ type: "number", value: 15 }))).toBe("15");
    expect(
      settingValue(setting({ type: "choice", value: "termly", choices: { monthly: "A calendar month", termly: "A term you define" } })),
    ).toBe("A term you define");
    expect(settingValue(setting({ type: "roles", value: ["Owner", "Management"] }))).toBe("Owner, Management");
  });

  it("says plainly when a list is empty or a value is missing", () => {
    expect(settingValue(setting({ type: "roles", value: [] }))).toBe("Nobody");
    expect(settingValue(setting({ value: null }))).toBe("Not set");
  });

  it("falls back to the raw value when a choice is not in the catalogue", () => {
    expect(settingValue(setting({ type: "choice", value: "weekly", choices: { monthly: "A month" } }))).toBe("weekly");
  });
});

describe("origin", () => {
  it("tells the three answers apart", () => {
    expect(origin(setting())).toBe("What the product ships with");
    expect(origin(setting({ source: "tenant", origin: "preset" }))).toBe(
      "Chosen by your preset when the academy was created",
    );
    expect(origin(setting({ source: "tenant", origin: "academy", set_here: true }))).toBe("Set by your academy");
  });

  it("does not invent an answer for a row written before the origin was recorded", () => {
    expect(origin(setting({ source: "tenant", origin: null, set_here: true }))).toBe("Already set on your academy");
  });

  it("names the level an inherited value came from", () => {
    expect(origin(setting({ source: "branch" }))).toBe("Inherited from the branch");
  });
});

describe("reverting", () => {
  it("is offered only for a value this academy set", () => {
    expect(canRevert(setting({ set_here: true, source: "tenant", origin: "academy" }))).toBe(true);
    expect(canRevert(setting({ source: "tenant", origin: "preset" }))).toBe(false);
    expect(canRevert(setting())).toBe(false);
  });

  it("says what the value would go back to", () => {
    expect(revertsTo(setting({ value: 15, type: "number", shipped_default: 10 }))).toBe("10");
    expect(revertsTo(setting({ value: false, shipped_default: true }))).toBe("Yes");
  });
});
