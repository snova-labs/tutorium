import type { AttendanceRate, InvitationStatus, LearnerDetail } from "@/lib/types";

/** "84%", or a dash when there is nothing to divide yet. */
export function percent(value: number | null | undefined): string {
  return value === null || value === undefined ? "—" : `${Math.round(value)}%`;
}

/** Attendance in a phrase that says what the number rests on. */
export function attendanceSummary(rate: AttendanceRate): string {
  if (rate.counted === 0) {
    return rate.unmarked > 0 ? `${rate.unmarked} not marked yet` : "No sessions yet";
  }

  const base = `${percent(rate.percentage)} (${rate.attended} of ${rate.counted})`;

  return rate.unmarked > 0 ? `${base}, ${rate.unmarked} not marked` : base;
}

/** Enrollments still running first, newest first within each group. */
export function sortEnrollments(enrollments: LearnerDetail["enrollments"]): LearnerDetail["enrollments"] {
  return [...enrollments].sort((a, b) => {
    const endedA = a.ended_on !== null ? 1 : 0;
    const endedB = b.ended_on !== null ? 1 : 0;

    return endedA - endedB || b.enrolled_on.localeCompare(a.enrolled_on);
  });
}

/** Whether anyone will receive this learner's reports: a guardian who is set to, or the learner's own email. */
export function hasReportRecipient(learner: Pick<LearnerDetail, "guardians" | "contact">): boolean {
  return learner.guardians.some((g) => g.link?.receives_reports) || Boolean(learner.contact.email);
}

export function invitationStatusLabel(status: InvitationStatus): string {
  return { pending: "Waiting", accepted: "Joined", revoked: "Withdrawn", expired: "Expired" }[status];
}

/**
 * What can be done with an invitation. A waiting one can be sent again or withdrawn; an expired one
 * is replaced by inviting the same address again (the API refuses to resend it).
 */
export function invitationActions(status: InvitationStatus): { resend: boolean; reinvite: boolean; revoke: boolean } {
  return { resend: status === "pending", reinvite: status === "expired", revoke: status === "pending" };
}
