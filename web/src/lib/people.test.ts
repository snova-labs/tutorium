import { describe, expect, it } from "vitest";

import {
  attendanceSummary,
  hasReportRecipient,
  invitationActions,
  invitationStatusLabel,
  percent,
  sortEnrollments,
} from "@/lib/people";
import type { AttendanceRate, LearnerDetail } from "@/lib/types";

const rate = (overrides: Partial<AttendanceRate>): AttendanceRate => ({
  attended: 0,
  counted: 0,
  percentage: null,
  unmarked: 0,
  is_complete: true,
  is_informational: false,
  ...overrides,
});

describe("learner summaries", () => {
  it("rounds percentages and shows a dash for nothing", () => {
    expect(percent(83.6)).toBe("84%");
    expect(percent(null)).toBe("—");
  });

  it("says what attendance rests on", () => {
    expect(attendanceSummary(rate({}))).toBe("No sessions yet");
    expect(attendanceSummary(rate({ unmarked: 2 }))).toBe("2 not marked yet");
    expect(attendanceSummary(rate({ attended: 9, counted: 10, percentage: 90 }))).toBe("90% (9 of 10)");
    expect(attendanceSummary(rate({ attended: 9, counted: 10, percentage: 90, unmarked: 1 }))).toBe(
      "90% (9 of 10), 1 not marked",
    );
  });

  it("lists running enrollments before ended ones, newest first", () => {
    const e = (id: number, enrolled_on: string, ended_on: string | null) =>
      ({ id, number: `E-${id}`, learner_id: 1, enrolled_on, ended_on, status: { id: 1, reason: null } }) as LearnerDetail["enrollments"][number];

    expect(
      sortEnrollments([e(1, "2025-01-01", "2025-06-30"), e(2, "2026-01-01", null), e(3, "2026-08-01", null)]).map((x) => x.id),
    ).toEqual([3, 2, 1]);
  });

  it("knows whether a report has anyone to go to", () => {
    const guardian = (receives: boolean) => ({ id: 1, name: "G", contact: { email: null, phone: null }, link: { is_primary: true, receives_reports: receives } });

    expect(hasReportRecipient({ guardians: [guardian(true)], contact: { email: null, phone: null } })).toBe(true);
    expect(hasReportRecipient({ guardians: [guardian(false)], contact: { email: null, phone: null } })).toBe(false);
    expect(hasReportRecipient({ guardians: [], contact: { email: "adult@example.test", phone: null } })).toBe(true);
  });
});

describe("invitations", () => {
  it("names each state plainly and offers only what works", () => {
    expect(invitationStatusLabel("accepted")).toBe("Joined");
    expect(invitationActions("pending")).toEqual({ resend: true, reinvite: false, revoke: true });
    expect(invitationActions("expired")).toEqual({ resend: false, reinvite: true, revoke: false });
    expect(invitationActions("accepted")).toEqual({ resend: false, reinvite: false, revoke: false });
  });
});
