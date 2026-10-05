import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { ProvenanceChip } from "@/components/app/provenance-chip";

describe("ProvenanceChip", () => {
  it("shows an inherited value in grey and says where it came from", () => {
    render(<ProvenanceChip value="Asia/Kathmandu" inherited inheritedFrom="Lalitpur branch" />);

    const chip = screen.getByText("Asia/Kathmandu").closest("[data-provenance]");
    expect(chip).toHaveAttribute("data-provenance", "inherited");
    expect(chip?.className).not.toContain("text-override");
    // Never colour alone: the origin is in words for screen readers too.
    expect(screen.getByText(/Inherited from Lalitpur branch/)).toBeInTheDocument();
  });

  it("shows an overridden value in amber and says it was set here", () => {
    render(<ProvenanceChip value="America/Toronto" inherited={false} setOn="this batch" />);

    const chip = screen.getByText("America/Toronto").closest("[data-provenance]");
    expect(chip).toHaveAttribute("data-provenance", "overridden");
    expect(chip?.className).toContain("text-override");
    expect(screen.getByText(/Set on this batch/)).toBeInTheDocument();
  });
});
