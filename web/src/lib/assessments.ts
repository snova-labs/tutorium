import type { GradingSchemeOption } from "@/lib/types";

export interface Criterion {
  name: string;
  max_points: string;
  descriptor: string;
}

/** A rubric is scored per criterion, so the form asks for criteria instead of one maximum. */
export function isRubric(scheme: GradingSchemeOption | undefined): boolean {
  return scheme?.kind === "rubric";
}

/** Points and percentage need a maximum; a letter, level or pass mark does not. */
export function needsMaximum(scheme: GradingSchemeOption | undefined): boolean {
  return scheme?.needs_maximum === true && !isRubric(scheme);
}

/**
 * What a grade out of this scheme will look like, said before anyone picks it: the form's one
 * chance to stop an academy grading a quiz on a scale nobody meant to use.
 */
export function schemeHint(scheme: GradingSchemeOption | undefined, maximum: string): string {
  if (!scheme) return "";

  const out = maximum.trim() === "" ? "the maximum" : maximum.trim();

  switch (scheme.kind) {
    case "points":
      return `Marked out of ${out}.`;
    case "percentage":
      return `Marked out of ${out}, reported as a percentage.`;
    case "pass_fail":
      return "Marked pass or fail.";
    case "letter":
      return `Marked as a letter${bands(scheme)}.`;
    case "level":
      return `Marked as a level${ladder(scheme)}.`;
    case "rubric":
      return "Marked per criterion, below.";
    default:
      return "";
  }
}

function bands(scheme: GradingSchemeOption): string {
  const labels = scheme.config?.bands?.map((band) => band.label) ?? [];

  return labels.length > 0 ? ` (${labels.join(", ")})` : "";
}

function ladder(scheme: GradingSchemeOption): string {
  const levels = scheme.config?.ladder ?? [];

  return levels.length > 0 ? ` (${levels.join(", ")})` : "";
}

/** The rubric's total, shown as criteria are typed so nobody adds up to 97 by accident. */
export function criteriaTotal(criteria: Criterion[]): number {
  return criteria.reduce((total, criterion) => {
    const points = Number(criterion.max_points);

    return total + (Number.isFinite(points) ? points : 0);
  }, 0);
}

/**
 * The due date to offer: today when today falls inside the period, otherwise the period's last
 * day. A date outside the period would put the assessment in a grade book nobody is looking at.
 */
export function defaultDueDate(period: { starts_local_date: string; ends_local_date: string }, today: string): string {
  if (today < period.starts_local_date) return period.starts_local_date;
  if (today > period.ends_local_date) return period.ends_local_date;

  return today;
}

export interface AssessmentDraft {
  title: string;
  scheme: GradingSchemeOption | undefined;
  maximum: string;
  due: string;
  criteria: Criterion[];
}

/**
 * What the form refuses to send, keyed as the API names its fields so a later refusal from the
 * API lands in the same place. The API checks all of this again.
 */
export function draftErrors(draft: AssessmentDraft): Record<string, string> {
  const errors: Record<string, string> = {};

  if (draft.title.trim() === "") {
    errors.title = "Needs a title.";
  }

  if (draft.scheme === undefined) {
    errors.grading_scheme_id = "Choose how it is marked.";
  }

  // The grade book shows a period at a time, by due date, so one without a date would be in no
  // grade book at all.
  if (draft.due.trim() === "") {
    errors.due_local_date = "Needs a date, or it appears in no grade book.";
  }

  if (needsMaximum(draft.scheme) && !(Number(draft.maximum) > 0)) {
    errors.max_points = "Needs a maximum above zero.";
  }

  if (isRubric(draft.scheme)) {
    const named = draft.criteria.filter((criterion) => criterion.name.trim() !== "");

    if (named.length === 0) {
      errors["criteria.0.name"] = "A rubric needs at least one criterion.";
    } else if (named.some((criterion) => !(Number(criterion.max_points) > 0))) {
      errors["criteria.0.max_points"] = "Every criterion needs points above zero.";
    }
  }

  return errors;
}

/** The criteria worth sending: the ones someone actually named. */
export function filledCriteria(criteria: Criterion[]): Criterion[] {
  return criteria.filter((criterion) => criterion.name.trim() !== "");
}
