import { describe, expect, it } from "vitest";

import { deliverySummary, notesToSave, retryable, runFinished, runLabel, runReasons } from "@/lib/reports";
import type { ArchivedReport, ReportDelivery } from "@/lib/types";

const delivery = (id: number, status: ReportDelivery["status"]): ReportDelivery => ({
  id,
  to: `parent${id}@example.test`,
  name: null,
  status,
  error: status === "failed" ? "Mailbox unavailable" : null,
  sent_at_utc: null,
});

describe("report runs", () => {
  it("knows when a run has stopped", () => {
    expect(runFinished("queued")).toBe(false);
    expect(runFinished("running")).toBe(false);
    expect(runFinished("completed")).toBe(true);
    expect(runFinished("completed_with_failures")).toBe(true);
    expect(runFinished("failed")).toBe(true);
    expect(runLabel("completed_with_failures")).toBe("Done, with some failures");
  });

  it("groups identical reasons, most frequent first", () => {
    expect(
      runReasons([
        { reason: "Generated but not sent: confirm your address." },
        { reason: "The enrollment no longer exists." },
        { reason: "Generated but not sent: confirm your address." },
      ]),
    ).toEqual([
      { reason: "Generated but not sent: confirm your address.", count: 2 },
      { reason: "The enrollment no longer exists.", count: 1 },
    ]);
  });
});

describe("delivery summary", () => {
  it("says when nothing has been sent", () => {
    expect(deliverySummary([])).toEqual({ label: "Not sent", tone: "neutral" });
  });

  it("puts a failure first, because that is what needs doing", () => {
    expect(deliverySummary([delivery(1, "sent"), delivery(2, "bounced"), delivery(3, "queued")])).toEqual({
      label: "1 of 3 not delivered",
      tone: "bad",
    });
  });

  it("shows sending, then sent", () => {
    expect(deliverySummary([delivery(1, "sent"), delivery(2, "queued")]).label).toBe("Sending");
    expect(deliverySummary([delivery(1, "sent")])).toEqual({ label: "Sent", tone: "good" });
    expect(deliverySummary([delivery(1, "sent"), delivery(2, "sent")]).label).toBe("Sent to 2");
  });

  it("offers only failed and bounced deliveries for retry", () => {
    const report: ArchivedReport = {
      id: 1,
      number: "R-1",
      learner: "Asha",
      period: "October 2026",
      generated_at_utc: "2026-10-31T10:00:00Z",
      deliveries: [delivery(1, "sent"), delivery(2, "failed"), delivery(3, "bounced"), delivery(4, "queued")],
    };

    expect(retryable(report).map((d) => d.id)).toEqual([2, 3]);
  });
});

describe("period-end notes", () => {
  it("sends only the boxes that were filled in, trimmed", () => {
    expect(
      notesToSave(
        [
          { enrollmentId: 1, body: "  Reads fluently now. ", onReport: true },
          { enrollmentId: 2, body: "   ", onReport: true },
          { enrollmentId: 3, body: "Needs support with fractions.", onReport: false },
        ],
        7,
      ),
    ).toEqual([
      { enrollment_id: 1, note_category_id: 7, body: "Reads fluently now.", is_report_visible: true },
      { enrollment_id: 3, note_category_id: 7, body: "Needs support with fractions.", is_report_visible: false },
    ]);
  });
});
