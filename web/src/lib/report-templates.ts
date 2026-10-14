import type { ReportTemplate } from "@/lib/types";

/** How a template reads in the list: what it answers for, narrowest first. */
export function appliesTo(template: ReportTemplate, courseNoun: string): string {
  if (template.applies_to.course) {
    return `${courseNoun}: ${template.applies_to.course}`;
  }

  if (template.applies_to.brand) {
    return `Everything under ${template.applies_to.brand}`;
  }

  return template.is_default ? "Everything else" : "Nothing yet — give it a brand or a course";
}

/**
 * A template that matches nothing sends no reports and is nobody's default: worth saying on the
 * screen rather than leaving somebody to wonder why their template never appears.
 */
export function matchesNothing(template: ReportTemplate): boolean {
  return !template.is_default && template.applies_to.brand_id === null && template.applies_to.course_id === null;
}

export interface TemplateDraft {
  name: string;
  blocks: string[];
}

/** What the form refuses to send, keyed as the API names its fields. The API checks it again. */
export function draftErrors(draft: TemplateDraft): Record<string, string> {
  const errors: Record<string, string> = {};

  if (draft.name.trim() === "") {
    errors.name = "Needs a name.";
  }

  if (draft.blocks.length === 0) {
    // A report with no sections is a greeting and nothing else.
    errors.blocks = "Choose at least one section.";
  }

  return errors;
}

/** The sections in the order the report prints them, whatever order they were ticked in. */
export function inPrintOrder(blocks: string[], catalogue: Record<string, string>): string[] {
  return Object.keys(catalogue).filter((block) => blocks.includes(block));
}

/** A one-line summary of what the report will carry. */
export function blockSummary(blocks: string[], catalogue: Record<string, string>): string {
  const chosen = inPrintOrder(blocks, catalogue);

  if (chosen.length === 0) {
    return "No sections yet";
  }

  return chosen.map((block) => block.charAt(0).toUpperCase() + block.slice(1)).join(" · ");
}
