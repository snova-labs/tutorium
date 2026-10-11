import { describe, expect, it } from "vitest";

import { criteriaTotal, defaultDueDate, draftErrors, filledCriteria, needsMaximum, schemeHint } from "@/lib/assessments";
import type { GradingSchemeOption, SchemeKind } from "@/lib/types";

const scheme = (kind: SchemeKind, overrides: Partial<GradingSchemeOption> = {}): GradingSchemeOption => ({
  id: 1,
  name: `${kind} scheme`,
  kind,
  label: kind,
  needs_maximum: kind === "points" || kind === "percentage",
  config: null,
  ...overrides,
});

const criterion = (name: string, max: string) => ({ name, max_points: max, descriptor: "" });

describe("needsMaximum", () => {
  it("asks for one where a mark is out of something", () => {
    expect(needsMaximum(scheme("points"))).toBe(true);
    expect(needsMaximum(scheme("percentage"))).toBe(true);
  });

  it("does not where the scale is the scheme's own", () => {
    expect(needsMaximum(scheme("letter"))).toBe(false);
    expect(needsMaximum(scheme("pass_fail"))).toBe(false);
    // A rubric has a maximum per criterion, never one for the whole thing.
    expect(needsMaximum(scheme("rubric", { needs_maximum: true }))).toBe(false);
    expect(needsMaximum(undefined)).toBe(false);
  });
});

describe("schemeHint", () => {
  it("says what a mark will look like", () => {
    expect(schemeHint(scheme("points"), "20")).toBe("Marked out of 20.");
    expect(schemeHint(scheme("points"), "  ")).toBe("Marked out of the maximum.");
    expect(schemeHint(scheme("pass_fail"), "")).toBe("Marked pass or fail.");
    expect(schemeHint(scheme("rubric"), "")).toBe("Marked per criterion, below.");
  });

  it("names the scale's own words when it has them", () => {
    expect(schemeHint(scheme("letter", { config: { bands: [{ label: "A" }, { label: "B" }] } }), "")).toBe(
      "Marked as a letter (A, B).",
    );
    expect(schemeHint(scheme("level", { config: { ladder: ["A1", "A2"] } }), "")).toBe("Marked as a level (A1, A2).");
    expect(schemeHint(scheme("level"), "")).toBe("Marked as a level.");
  });
});

describe("criteriaTotal", () => {
  it("adds up what is typed so far, ignoring what is not a number yet", () => {
    expect(criteriaTotal([criterion("Fluency", "10"), criterion("Accuracy", "15")])).toBe(25);
    expect(criteriaTotal([criterion("Fluency", ""), criterion("Accuracy", "5")])).toBe(5);
  });
});

describe("defaultDueDate", () => {
  const period = { starts_local_date: "2026-08-01", ends_local_date: "2026-08-31" };

  it("offers today while the period is running", () => {
    expect(defaultDueDate(period, "2026-08-14")).toBe("2026-08-14");
  });

  it("keeps the date inside the period someone is looking at", () => {
    expect(defaultDueDate(period, "2026-09-10")).toBe("2026-08-31");
    expect(defaultDueDate(period, "2026-07-20")).toBe("2026-08-01");
  });
});

describe("draftErrors", () => {
  const draft = { title: "Unit quiz", scheme: scheme("points"), maximum: "20", due: "2026-08-14", criteria: [] };

  it("passes a complete draft", () => {
    expect(draftErrors(draft)).toEqual({});
  });

  it("names what is missing, keyed as the API names it", () => {
    expect(draftErrors({ ...draft, title: "  " })).toHaveProperty("title");
    expect(draftErrors({ ...draft, scheme: undefined })).toHaveProperty("grading_scheme_id");
    expect(draftErrors({ ...draft, maximum: "0" })).toHaveProperty("max_points");
    // No date would mean no grade book shows it, which looks like it was never saved.
    expect(draftErrors({ ...draft, due: "" })).toHaveProperty("due_local_date");
  });

  it("holds a rubric to having criteria worth marking", () => {
    const rubric = { ...draft, scheme: scheme("rubric"), maximum: "" };

    expect(draftErrors(rubric)).toHaveProperty("criteria.0.name");
    expect(draftErrors({ ...rubric, criteria: [criterion("Fluency", "0")] })).toHaveProperty("criteria.0.max_points");
    expect(draftErrors({ ...rubric, criteria: [criterion("Fluency", "10"), criterion("", "")] })).toEqual({});
  });
});

describe("filledCriteria", () => {
  it("sends only the rows someone named", () => {
    expect(filledCriteria([criterion("Fluency", "10"), criterion("  ", "5")])).toHaveLength(1);
  });
});
