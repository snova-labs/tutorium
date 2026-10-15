import type { Vocabulary, VocabularyTerm } from "@/lib/types";

/** What the form refuses to send, keyed as the API names its fields. The API checks it again. */
export function draftErrors(draft: { name: string; color?: string | null }): Record<string, string> {
  const errors: Record<string, string> = {};

  if (draft.name.trim() === "") {
    errors.name = "Give it a name.";
  } else if (draft.name.trim().length > 60) {
    errors.name = "A name is 60 characters at most.";
  }

  if (draft.color != null && draft.color.trim() !== "" && !isHexColor(draft.color.trim())) {
    errors.color = "A colour looks like #1B5FAA.";
  }

  return errors;
}

export function isHexColor(value: string): boolean {
  return /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(value.trim());
}

/**
 * Why a term cannot be removed, or null when it can.
 *
 * Phrased as the thing to do instead, because "in use" on its own leaves somebody stuck with a
 * list item they have outgrown and no idea what to do about it.
 */
export function removalBlock(list: Vocabulary, term: VocabularyTerm): string | null {
  if (term.used === 0) {
    return null;
  }

  const places = `${term.used} ${term.used === 1 ? "place" : "places"}`;

  return list.deactivates
    ? `In use in ${places}. Switch it off to stop it being offered.`
    : `In use in ${places}. Rename it if it is wrong.`;
}

/** How a term reads under its name: the flag, then the code an import would use. */
export function termSummary(list: Vocabulary, term: VocabularyTerm): string {
  // Each flag carries its own wording for being off: negating the label mechanically gives
  // "Not goes on reports by default".
  const parts = [term.flag ? list.flag.label : list.flag.off, term.code];

  if (list.deactivates && !term.is_active) {
    parts.unshift("Switched off");
  }

  return parts.join(" · ");
}

/**
 * The terms in the order the product offers them, with the switched-off ones last: they are
 * history rather than choices, and leaving them interleaved makes the live list hard to read.
 */
export function inOfferedOrder(list: Vocabulary): VocabularyTerm[] {
  return [...list.terms].sort((a, b) => {
    if (list.deactivates && a.is_active !== b.is_active) {
      return a.is_active ? -1 : 1;
    }

    return a.sort - b.sort || a.id - b.id;
  });
}
