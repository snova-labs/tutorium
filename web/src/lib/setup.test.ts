import { describe, expect, it } from "vitest";

import { defaultGenerateRange, suggestCode, teacherChoices, timeRange, weekdaysFrom } from "@/lib/setup";
import type { StaffMember } from "@/lib/types";

const person = (name: string, roles: string[], is_active = true): StaffMember => ({
  id: name.length,
  name,
  email: `${name.toLowerCase()}@example.test`,
  is_active,
  roles,
});

describe("class setup", () => {
  it("suggests a code the API accepts", () => {
    expect(suggestCode("Grade 8 Maths · Morning")).toBe("GRADE-8-MATHS-MORNING");
    expect(suggestCode("  Côté's Français  ")).toBe("COTE-S-FRANCAIS");
    expect(suggestCode("A very long class name that goes on and on and on")).toHaveLength(32);
    expect(suggestCode("A very long class name that goes on and on and on")).not.toMatch(/-$/);
  });

  it("starts the week where the academy does", () => {
    expect(weekdaysFrom(7).map((d) => d.name).slice(0, 2)).toEqual(["Sunday", "Monday"]);
    expect(weekdaysFrom(1)[0].name).toBe("Monday");
  });

  it("shows when a slot ends", () => {
    expect(timeRange("07:00", 90)).toBe("07:00–08:30");
    expect(timeRange("23:30", 60)).toBe("23:30–00:30");
  });

  it("offers teachers first, and never inactive people", () => {
    const choices = teacherChoices([
      person("Zara", ["Management"]),
      person("Bikash", ["Teacher"]),
      person("Anil", ["Teacher"]),
      person("Gone", ["Teacher"], false),
    ]);

    expect(choices.map((c) => c.name)).toEqual(["Anil", "Bikash", "Zara"]);
  });

  it("generates from today to the end of the class by default", () => {
    expect(defaultGenerateRange("2026-08-01", "2026-12-31", "2026-10-10")).toEqual({ from: "2026-10-10", to: "2026-12-31" });
    expect(defaultGenerateRange("2026-11-01", null, "2026-10-10")).toEqual({ from: "2026-11-01", to: "2027-02-01" });
  });
});
