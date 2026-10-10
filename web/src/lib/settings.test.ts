import { describe, expect, it } from "vitest";

import { DEFAULT_TERMS, TERM_KEYS, statusChoices, statusConsequence, stepHref, termChanges } from "@/lib/settings";
import type { EnrollmentStatusOption, Terms } from "@/lib/types";

const status = (overrides: Partial<EnrollmentStatusOption>): EnrollmentStatusOption => ({
  id: 1,
  name: "Active",
  code: "ACTIVE",
  is_terminal: false,
  counts_toward_billing: true,
  set_by_transfer_only: false,
  ...overrides,
});

describe("terminology", () => {
  it("lists every noun the API knows", () => {
    expect(TERM_KEYS.map((t) => t.key).sort()).toEqual(Object.keys(DEFAULT_TERMS).sort());
  });

  it("sends only what changed, trimmed", () => {
    const edited: Terms = { ...DEFAULT_TERMS, batch: { singular: " Class ", plural: "Classes" } };

    expect(termChanges(DEFAULT_TERMS, edited)).toEqual({
      changes: { batch: { singular: "Class", plural: "Classes" } },
      errors: {},
    });
  });

  it("sends nothing when only spaces were added", () => {
    const edited: Terms = { ...DEFAULT_TERMS, learner: { singular: "Learner ", plural: " Learners" } };

    expect(termChanges(DEFAULT_TERMS, edited).changes).toEqual({});
  });

  it("names each empty box", () => {
    const edited: Terms = { ...DEFAULT_TERMS, course: { singular: "Subject", plural: "  " } };

    expect(termChanges(DEFAULT_TERMS, edited).errors).toEqual({ "course.plural": "Needs a word." });
  });
});

describe("setup steps", () => {
  it("links the steps the client can do", () => {
    expect(stepHref("batch")).toBe("/batches");
    expect(stepHref("staff")).toBe("/team");
    expect(stepHref("learners")).toBe("/learners");
  });

  it("does not link steps done elsewhere", () => {
    expect(stepHref("brand")).toBeNull();
    expect(stepHref("branch")).toBeNull();
  });
});

describe("enrollment statuses", () => {
  const statuses = [
    status({ id: 1 }),
    status({ id: 2, name: "On hold", code: "ON_HOLD", counts_toward_billing: false }),
    status({ id: 3, name: "Transferred", code: "TRANSFERRED", is_terminal: true, set_by_transfer_only: true }),
    status({ id: 4, name: "Withdrawn", code: "WITHDRAWN", is_terminal: true, counts_toward_billing: false }),
  ];

  it("offers neither the current status nor transferred", () => {
    expect(statusChoices(statuses, 1).map((s) => s.id)).toEqual([2, 4]);
  });

  it("says what a status will do", () => {
    expect(statusConsequence(statuses[3])).toBe("Ends the enrollment; a reason is required. Does not count toward the bill.");
    expect(statusConsequence(statuses[0])).toBe("Keeps the enrollment open. Counts toward the bill.");
  });
});
