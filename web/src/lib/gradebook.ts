import type { GradeBook, GradeCell, SchemeKind } from "@/lib/types";

/** What a cell holds while being edited: a status and one value in the scheme's own terms. */
export interface Draft {
  status_id: number | null;
  /** A score, a letter or a level; "pass" or "fail" for pass/fail; "" for nothing. */
  value: string;
}

export const cellKey = (enrollmentId: number, assessmentId: number) => `${enrollmentId}:${assessmentId}`;

export function draftFrom(kind: SchemeKind, cell: GradeCell | null): Draft {
  if (cell === null) {
    return { status_id: null, value: "" };
  }

  switch (kind) {
    case "letter":
      return { status_id: cell.submission_status_id, value: cell.letter ?? "" };
    case "level":
      return { status_id: cell.submission_status_id, value: cell.level_code ?? "" };
    case "pass_fail":
      return { status_id: cell.submission_status_id, value: cell.passed === null ? "" : cell.passed ? "pass" : "fail" };
    default:
      return { status_id: cell.submission_status_id, value: cell.raw_score === null ? "" : String(Number(cell.raw_score)) };
  }
}

/** The status a result gets when someone types one without choosing: plainly submitted. */
export function defaultStatusId(statuses: GradeBook["submission_statuses"]): number | null {
  return statuses.find((s) => s.counts_as_submitted && !s.excluded_from_average)?.id ?? null;
}

/**
 * One cell as the API takes it. The value goes in the field the scheme reads (a score, a letter,
 * a level or pass/fail); the scheme on the server validates it and explains any problem.
 */
export function toPayload(
  kind: SchemeKind,
  enrollmentId: number,
  assessmentId: number,
  draft: Draft,
): Record<string, unknown> | null {
  if (draft.status_id === null) {
    return null;
  }

  const base = { enrollment_id: enrollmentId, assessment_id: assessmentId, submission_status_id: draft.status_id };
  const value = draft.value.trim();

  if (value === "") {
    return base;
  }

  switch (kind) {
    case "points":
    case "percentage":
      return { ...base, raw_score: Number(value) };
    case "letter":
      return { ...base, letter: value };
    case "level":
      return { ...base, level_code: value };
    case "pass_fail":
      return { ...base, passed: value === "pass" };
    default:
      // Rubrics are scored per criterion, which this grid does not edit.
      return null;
  }
}

/** Every changed cell, ready to save in one request. */
export function changedCells(
  book: GradeBook,
  drafts: Record<string, Draft>,
): Record<string, unknown>[] {
  return book.rows.flatMap((row) =>
    book.assessments.flatMap((assessment) => {
      const key = cellKey(row.enrollment_id, assessment.id);
      const draft = drafts[key];
      const original = draftFrom(assessment.scheme.kind, row.cells[String(assessment.id)] ?? null);

      if (!draft || (draft.status_id === original.status_id && draft.value === original.value)) {
        return [];
      }

      const payload = toPayload(assessment.scheme.kind, row.enrollment_id, assessment.id, draft);

      return payload === null ? [] : [payload];
    }),
  );
}

/** The choices for a letter or level scheme, in the scheme's own order. */
export function optionsFor(scheme: GradeBook["assessments"][number]["scheme"]): string[] {
  if (scheme.kind === "letter") {
    return (scheme.config?.bands ?? []).map((band) => band.label);
  }

  if (scheme.kind === "level") {
    return scheme.config?.ladder ?? [];
  }

  return [];
}
