import type { AttendanceStatus, PolicyOrigin, RosterRow } from "@/lib/types";

export interface Mark {
  status_id: number | null;
  minutes_late: number | null;
}

/** The status "mark the rest" uses: the first that counts as attended without being late. */
export function presentStatus(statuses: AttendanceStatus[]): AttendanceStatus | undefined {
  return statuses.find((s) => s.counts_as_attended && !s.is_late);
}

/**
 * What to send when the register is saved: every learner with a mark, minutes late only where the
 * status is a late one. Learners nobody has marked are left out rather than guessed.
 */
export function marksToSave(
  roster: RosterRow[],
  marks: Record<number, Mark>,
  statuses: AttendanceStatus[],
): { enrollment_id: number; status_id: number; minutes_late: number | null }[] {
  const late = new Set(statuses.filter((s) => s.is_late).map((s) => s.id));

  return roster.flatMap((row) => {
    const mark = marks[row.enrollment_id];

    if (!mark || mark.status_id === null) {
      return [];
    }

    return [
      {
        enrollment_id: row.enrollment_id,
        status_id: mark.status_id,
        minutes_late: late.has(mark.status_id) ? mark.minutes_late : null,
      },
    ];
  });
}

/** Whether anything on screen differs from what was loaded. */
export function hasChanges(roster: RosterRow[], marks: Record<number, Mark>): boolean {
  return roster.some((row) => {
    const mark = marks[row.enrollment_id];

    return (mark?.status_id ?? null) !== row.status_id || (mark?.minutes_late ?? null) !== row.minutes_late;
  });
}

/** On a register, a rule set on this batch is an override; anything from above is inherited. */
export function policyProvenance(origin: PolicyOrigin | undefined): { inherited: boolean; from: string } {
  switch (origin) {
    case "batch":
      return { inherited: false, from: "this batch" };
    case "course":
      return { inherited: true, from: "the course" };
    case "tenant":
      return { inherited: true, from: "the academy's settings" };
    default:
      return { inherited: true, from: "the product default" };
  }
}
