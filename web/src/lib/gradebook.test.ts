import { describe, expect, it } from "vitest";

import { cellKey, changedCells, defaultStatusId, draftFrom, optionsFor, toPayload } from "@/lib/gradebook";
import type { GradeBook, GradeCell } from "@/lib/types";

const cell = (overrides: Partial<GradeCell>): GradeCell => ({
  grade_id: 1,
  submission_status_id: 1,
  raw_score: null,
  letter: null,
  level_code: null,
  passed: null,
  normalized_pct: null,
  feedback: null,
  ...overrides,
});

const statuses = [
  { id: 4, name: "Exempt", code: "EXEMPT", counts_as_submitted: false, excluded_from_average: true, color: null },
  { id: 1, name: "Submitted", code: "SUBMITTED", counts_as_submitted: true, excluded_from_average: false, color: null },
];

describe("draftFrom", () => {
  it("reads the value from the field each scheme uses", () => {
    expect(draftFrom("points", cell({ raw_score: "17.50" })).value).toBe("17.5");
    expect(draftFrom("letter", cell({ letter: "B" })).value).toBe("B");
    expect(draftFrom("level", cell({ level_code: "B2" })).value).toBe("B2");
    expect(draftFrom("pass_fail", cell({ passed: false })).value).toBe("fail");
    expect(draftFrom("points", null)).toEqual({ status_id: null, value: "" });
  });
});

describe("toPayload", () => {
  it("puts the value in the field the scheme reads", () => {
    expect(toPayload("points", 7, 9, { status_id: 1, value: "18" })).toEqual({
      enrollment_id: 7, assessment_id: 9, submission_status_id: 1, raw_score: 18,
    });
    expect(toPayload("letter", 7, 9, { status_id: 1, value: "A" })).toMatchObject({ letter: "A" });
    expect(toPayload("level", 7, 9, { status_id: 1, value: "C1" })).toMatchObject({ level_code: "C1" });
    expect(toPayload("pass_fail", 7, 9, { status_id: 1, value: "pass" })).toMatchObject({ passed: true });
  });

  it("sends a status alone, such as Missing, without inventing a score", () => {
    expect(toPayload("points", 7, 9, { status_id: 3, value: "" })).toEqual({
      enrollment_id: 7, assessment_id: 9, submission_status_id: 3,
    });
  });

  it("sends nothing without a status, and nothing for a rubric", () => {
    expect(toPayload("points", 7, 9, { status_id: null, value: "5" })).toBeNull();
    expect(toPayload("rubric", 7, 9, { status_id: 1, value: "5" })).toBeNull();
  });
});

describe("changedCells", () => {
  const book = {
    assessments: [
      { id: 9, title: "Quiz", type: "Quiz", scheme: { kind: "points", label: "Points", max_points: 20, config: null }, due_local_date: null, criteria: [] },
    ],
    rows: [
      { enrollment_id: 7, number: "L-7", name: "A", cells: { "9": cell({ raw_score: 15 }) } },
      { enrollment_id: 8, number: "L-8", name: "B", cells: { "9": null } },
    ],
    period: { label: "2026-10", starts_local_date: "2026-10-01", ends_local_date: "2026-10-31", timezone: "UTC" },
    submission_statuses: statuses,
  } as GradeBook;

  it("sends only what differs from what was loaded", () => {
    const drafts = {
      [cellKey(7, 9)]: { status_id: 1, value: "15" }, // unchanged
      [cellKey(8, 9)]: { status_id: 1, value: "19" }, // new
    };

    expect(changedCells(book, drafts)).toEqual([
      { enrollment_id: 8, assessment_id: 9, submission_status_id: 1, raw_score: 19 },
    ]);
  });
});

describe("defaults and options", () => {
  it("defaults a typed result to plainly submitted", () => {
    expect(defaultStatusId(statuses)).toBe(1);
  });

  it("offers a letter or level scheme's own scale, in order", () => {
    expect(optionsFor({ kind: "letter", label: "", max_points: null, config: { bands: [{ label: "1" }, { label: "2" }] } })).toEqual(["1", "2"]);
    expect(optionsFor({ kind: "level", label: "", max_points: null, config: { ladder: ["A1", "A2"] } })).toEqual(["A1", "A2"]);
  });
});
