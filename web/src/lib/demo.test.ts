import { describe, expect, it } from "vitest";

import { isBusy, needsEmailedCode, stateLabel } from "@/lib/demo";

describe("demo status", () => {
  it("is busy only while loading or removing", () => {
    expect(isBusy("building")).toBe(true);
    expect(isBusy("removing")).toBe(true);
    expect(isBusy("ready")).toBe(false);
    expect(isBusy("failed")).toBe(false);
    expect(isBusy("none")).toBe(false);
  });

  it("says where things stand", () => {
    expect(stateLabel("none")).toBe("No demo data is loaded.");
    expect(stateLabel("failed")).toBe("The last run did not finish.");
  });

  it("knows which roles get an emailed code", () => {
    expect(needsEmailedCode("Owner")).toBe(true);
    expect(needsEmailedCode("Teacher")).toBe(false);
    expect(needsEmailedCode("Front desk")).toBe(false);
  });
});
