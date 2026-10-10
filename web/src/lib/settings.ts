import type { EnrollmentStatusOption, TermKey, Terms } from "@/lib/types";

/** The nouns an academy can rename, in the order the settings screen lists them, with what each is. */
export const TERM_KEYS: { key: TermKey; meaning: string }[] = [
  { key: "learner", meaning: "The people you teach" },
  { key: "guardian", meaning: "Who receives their reports" },
  { key: "course", meaning: "What you teach" },
  { key: "batch", meaning: "A group taught together, on a timetable" },
  { key: "session", meaning: "One meeting of a group" },
  { key: "assessment", meaning: "Anything graded" },
  { key: "period", meaning: "The stretch a report covers" },
];

/** The product's own words, for screens rendered before the account's are known. */
export const DEFAULT_TERMS: Terms = {
  learner: { singular: "Learner", plural: "Learners" },
  guardian: { singular: "Guardian", plural: "Guardians" },
  batch: { singular: "Batch", plural: "Batches" },
  course: { singular: "Course", plural: "Courses" },
  session: { singular: "Session", plural: "Sessions" },
  assessment: { singular: "Assessment", plural: "Assessments" },
  period: { singular: "Period", plural: "Periods" },
};

export type TermEdits = Partial<Record<TermKey, { singular: string; plural: string }>>;

/**
 * What to send when saving terminology: only the nouns that changed, trimmed. Errors name each
 * empty box, keyed as the form names its inputs ("batch.plural").
 */
export function termChanges(current: Terms, edited: Terms): { changes: TermEdits; errors: Record<string, string> } {
  const changes: TermEdits = {};
  const errors: Record<string, string> = {};

  for (const { key } of TERM_KEYS) {
    const singular = (edited[key]?.singular ?? "").trim();
    const plural = (edited[key]?.plural ?? "").trim();

    if (singular === "") errors[`${key}.singular`] = "Needs a word.";
    if (plural === "") errors[`${key}.plural`] = "Needs a word.";

    if (singular !== current[key].singular || plural !== current[key].plural) {
      changes[key] = { singular, plural };
    }
  }

  return { changes, errors };
}

/** Where the client does each setup step, or null for steps done in the operator console. */
export function stepHref(key: string): string | null {
  switch (key) {
    case "course":
    case "batch":
      return "/batches";
    case "staff":
      return "/team";
    case "learners":
      return "/learners";
    default:
      return null;
  }
}

/**
 * The statuses someone may move an enrollment to by hand: not the one it is in, and not
 * "transferred", which only a transfer sets (it creates the new enrollment too).
 */
export function statusChoices(statuses: EnrollmentStatusOption[], currentId: number): EnrollmentStatusOption[] {
  return statuses.filter((s) => s.id !== currentId && !s.set_by_transfer_only);
}

/** What choosing a status will do, said before anyone chooses it. */
export function statusConsequence(status: EnrollmentStatusOption): string {
  const ends = status.is_terminal ? "Ends the enrollment; a reason is required." : "Keeps the enrollment open.";
  const bill = status.counts_toward_billing ? "Counts toward the bill." : "Does not count toward the bill.";

  return `${ends} ${bill}`;
}
