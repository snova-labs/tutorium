import { describe, expect, it } from "vitest";

import { draftErrors, inOfferedOrder, isHexColor, removalBlock, termSummary } from "@/lib/vocabularies";
import type { Vocabulary, VocabularyTerm } from "@/lib/types";

const term = (overrides: Partial<VocabularyTerm> = {}): VocabularyTerm => ({
  id: 1,
  name: "Make-up class",
  code: "MAKE_UP",
  flag: false,
  color: null,
  sort: 10,
  is_active: true,
  used: 0,
  ...overrides,
});

const list = (overrides: Partial<Vocabulary> = {}): Vocabulary => ({
  key: "session-types",
  name: "Session types",
  singular: "session type",
  about: "What kind of class a session is.",
  flag: {
    field: "counts_in_attendance",
    label: "Counts toward attendance",
    off: "Does not count toward attendance",
    help: "",
    default: true,
  },
  deactivates: true,
  can_manage: true,
  terms: [],
  ...overrides,
});

describe("draftErrors", () => {
  it("passes a complete draft", () => {
    expect(draftErrors({ name: "Trial lesson", color: "#1B5FAA" })).toEqual({});
    expect(draftErrors({ name: "Trial lesson", color: "" })).toEqual({});
  });

  it("refuses a nameless term and a colour that is not one", () => {
    expect(draftErrors({ name: "   " })).toHaveProperty("name");
    expect(draftErrors({ name: "x".repeat(61) })).toHaveProperty("name");
    expect(draftErrors({ name: "Workshop", color: "blue" })).toHaveProperty("color");
  });

  it("knows a hex colour", () => {
    expect(isHexColor("#abc")).toBe(true);
    expect(isHexColor("#1B5FAA")).toBe(true);
    expect(isHexColor("1B5FAA")).toBe(false);
  });
});

describe("removalBlock", () => {
  it("says nothing about a term nothing has used", () => {
    expect(removalBlock(list(), term())).toBeNull();
  });

  it("says what to do instead, which differs by list", () => {
    expect(removalBlock(list(), term({ used: 1 }))).toBe("In use in 1 place. Switch it off to stop it being offered.");
    expect(removalBlock(list({ deactivates: false }), term({ used: 4 }))).toBe(
      "In use in 4 places. Rename it if it is wrong.",
    );
  });
});

describe("termSummary", () => {
  it("reads the flag either way, and marks a term that is switched off", () => {
    expect(termSummary(list(), term({ flag: true }))).toBe("Counts toward attendance · MAKE_UP");
    expect(termSummary(list(), term())).toBe("Does not count toward attendance · MAKE_UP");
    expect(termSummary(list(), term({ is_active: false }))).toContain("Switched off");
  });
});

describe("inOfferedOrder", () => {
  it("orders by sort, with the switched-off ones last", () => {
    const terms = [term({ id: 1, sort: 20 }), term({ id: 2, sort: 0, is_active: false }), term({ id: 3, sort: 10 })];

    expect(inOfferedOrder(list({ terms })).map((t) => t.id)).toEqual([3, 1, 2]);
  });

  it("leaves a list that cannot be switched off in its own order", () => {
    const terms = [term({ id: 1, sort: 20, is_active: false }), term({ id: 2, sort: 10 })];

    expect(inOfferedOrder(list({ deactivates: false, terms })).map((t) => t.id)).toEqual([2, 1]);
  });
});
