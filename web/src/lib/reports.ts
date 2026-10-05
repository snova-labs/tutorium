import type { ArchivedReport, DeliveryStatus, ReportDelivery, ReportRunStatus } from "@/lib/types";

/**
 * The distinct reasons a run reported, most frequent first, so forty identical "not sent" lines
 * read as one sentence with a count.
 */
export function runReasons(failures: { reason: string }[]): { reason: string; count: number }[] {
  const counts = new Map<string, number>();

  for (const { reason } of failures) {
    counts.set(reason, (counts.get(reason) ?? 0) + 1);
  }

  return [...counts.entries()].map(([reason, count]) => ({ reason, count })).sort((a, b) => b.count - a.count);
}

/** Whether a generation run has stopped: the progress screen stops asking. */
export function runFinished(status: ReportRunStatus): boolean {
  return status === "completed" || status === "completed_with_failures" || status === "failed";
}

export function runLabel(status: ReportRunStatus): string {
  switch (status) {
    case "queued":
      return "Waiting to start";
    case "running":
      return "Generating";
    case "completed":
      return "Done";
    case "completed_with_failures":
      return "Done, with some failures";
    case "failed":
      return "Failed";
  }
}

/**
 * Where a report stands with its recipients, in one phrase for the archive. A failure or a bounce
 * outranks everything else: that is the one a coordinator has to act on.
 */
export function deliverySummary(deliveries: ReportDelivery[]): { label: string; tone: "good" | "bad" | "neutral" } {
  if (deliveries.length === 0) {
    return { label: "Not sent", tone: "neutral" };
  }

  const count = (status: DeliveryStatus) => deliveries.filter((d) => d.status === status).length;
  const problems = count("failed") + count("bounced");

  if (problems > 0) {
    return { label: `${problems} of ${deliveries.length} not delivered`, tone: "bad" };
  }

  if (count("queued") > 0) {
    return { label: "Sending", tone: "neutral" };
  }

  return { label: deliveries.length === 1 ? "Sent" : `Sent to ${deliveries.length}`, tone: "good" };
}

/** Failed or bounced deliveries, which can be tried again. */
export function retryable(report: ArchivedReport): ReportDelivery[] {
  return report.deliveries.filter((d) => d.status === "failed" || d.status === "bounced");
}

export interface NoteDraft {
  enrollmentId: number;
  body: string;
  onReport: boolean;
}

/**
 * The bulk-save payload. Empty boxes are left out rather than sent: an empty box is a teacher who
 * has not reached that learner yet, not a note saying nothing.
 */
export function notesToSave(drafts: NoteDraft[], categoryId: number) {
  return drafts
    .filter((draft) => draft.body.trim() !== "")
    .map((draft) => ({
      enrollment_id: draft.enrollmentId,
      note_category_id: categoryId,
      body: draft.body.trim(),
      is_report_visible: draft.onReport,
    }));
}
