import { describe, expect, it } from "vitest";

import { hasChanges, marksToSave, policyProvenance, presentStatus } from "@/lib/register";
import type { AttendanceStatus, RosterRow } from "@/lib/types";

const statuses: AttendanceStatus[] = [
  { id: 2, name: "Late", code: "LATE", counts_as_attended: true, counts_in_rate: true, is_late: true, color: null },
  { id: 1, name: "Present", code: "PRESENT", counts_as_attended: true, counts_in_rate: true, is_late: false, color: null },
  { id: 3, name: "Absent", code: "ABSENT", counts_as_attended: false, counts_in_rate: true, is_late: false, color: null },
];

const row = (enrollment_id: number, status_id: number | null = null, minutes_late: number | null = null): RosterRow => ({
  enrollment_id,
  learner_id: enrollment_id,
  number: `L-${enrollment_id}`,
  name: `Learner ${enrollment_id}`,
  status_id,
  minutes_late,
  note: null,
  marked: status_id !== null,
});

describe("presentStatus", () => {
  it("is the first attended status that is not late, whatever the order", () => {
    expect(presentStatus(statuses)?.code).toBe("PRESENT");
  });
});

describe("marksToSave", () => {
  it("sends only learners with a mark, and minutes late only for a late status", () => {
    const roster = [row(10), row(11), row(12)];
    const marks = {
      10: { status_id: 2, minutes_late: 7 },
      11: { status_id: 3, minutes_late: 7 },
      12: { status_id: null, minutes_late: null },
    };

    expect(marksToSave(roster, marks, statuses)).toEqual([
      { enrollment_id: 10, status_id: 2, minutes_late: 7 },
      { enrollment_id: 11, status_id: 3, minutes_late: null },
    ]);
  });
});

describe("hasChanges", () => {
  it("compares what is on screen with what was loaded", () => {
    const roster = [row(10, 1)];

    expect(hasChanges(roster, { 10: { status_id: 1, minutes_late: null } })).toBe(false);
    expect(hasChanges(roster, { 10: { status_id: 3, minutes_late: null } })).toBe(true);
  });
});

describe("policyProvenance", () => {
  it("treats only a rule set on the batch as overridden", () => {
    expect(policyProvenance("batch")).toEqual({ inherited: false, from: "this batch" });
    expect(policyProvenance("course").inherited).toBe(true);
    expect(policyProvenance("tenant").inherited).toBe(true);
    expect(policyProvenance(undefined).inherited).toBe(true);
  });
});
