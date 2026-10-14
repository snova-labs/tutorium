import { describe, expect, it } from "vitest";

import { appliesTo, blockSummary, draftErrors, inPrintOrder, matchesNothing } from "@/lib/report-templates";
import type { ReportTemplate } from "@/lib/types";

const catalogue = {
  summary: "Attendance and average",
  engagement: "Engagement stars",
  highlights: "What went well",
  work: "Every graded piece",
  notes: "The teacher's note",
};

const template = (overrides: Partial<ReportTemplate> = {}): ReportTemplate => ({
  id: 1,
  name: "Default",
  blocks: ["summary"],
  closing: null,
  locale: null,
  is_default: false,
  applies_to: { brand_id: null, brand: null, course_id: null, course: null },
  ...overrides,
});

describe("appliesTo", () => {
  it("names the course when one is set, in the academy's own word", () => {
    expect(appliesTo(template({ applies_to: { brand_id: 1, brand: "Main", course_id: 4, course: "Maths" } }), "Course")).toBe(
      "Course: Maths",
    );
  });

  it("falls back to the brand, then to being the default", () => {
    expect(appliesTo(template({ applies_to: { brand_id: 1, brand: "Main", course_id: null, course: null } }), "Course")).toBe(
      "Everything under Main",
    );
    expect(appliesTo(template({ is_default: true }), "Course")).toBe("Everything else");
  });

  it("says plainly when a template answers for nothing", () => {
    expect(appliesTo(template(), "Course")).toContain("Nothing yet");
    expect(matchesNothing(template())).toBe(true);
    expect(matchesNothing(template({ is_default: true }))).toBe(false);
  });
});

describe("draftErrors", () => {
  it("passes a complete draft", () => {
    expect(draftErrors({ name: "Short report", blocks: ["summary"] })).toEqual({});
  });

  it("refuses a nameless template and one with no sections", () => {
    expect(draftErrors({ name: "  ", blocks: ["summary"] })).toHaveProperty("name");
    expect(draftErrors({ name: "Empty", blocks: [] })).toHaveProperty("blocks");
  });
});

describe("inPrintOrder", () => {
  it("orders the sections as the report prints them, not as they were ticked", () => {
    expect(inPrintOrder(["notes", "summary", "work"], catalogue)).toEqual(["summary", "work", "notes"]);
  });

  it("ignores anything the catalogue does not know", () => {
    expect(inPrintOrder(["horoscope", "summary"], catalogue)).toEqual(["summary"]);
  });
});

describe("blockSummary", () => {
  it("reads as a line of sections", () => {
    expect(blockSummary(["work", "summary"], catalogue)).toBe("Summary · Work");
    expect(blockSummary([], catalogue)).toBe("No sections yet");
  });
});
