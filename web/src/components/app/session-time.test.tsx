import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { SessionTime } from "@/components/app/session-time";
import type { ClassSession } from "@/lib/types";

// 21:00 in Toronto on the last day of October: already 1 November in UTC and in Kathmandu.
const session: ClassSession = {
  id: 1,
  batch_id: 1,
  session_type: { id: 1, name: "Lesson" },
  local: { date: "2026-10-31", time: "21:00", timezone: "America/Toronto" },
  utc: { starts_at: "2026-11-01T01:00:00Z", ends_at: "2026-11-01T02:00:00Z" },
  viewer: null,
  status: "scheduled",
  cancel_reason: null,
  meeting_url: null,
};

describe("SessionTime", () => {
  it("shows the agreed local date and time, never recomputed from UTC", () => {
    render(<SessionTime session={session} batchZone="America/Toronto" />);

    expect(screen.getByText("Sat 31 Oct")).toBeInTheDocument();
    expect(screen.getByText("21:00")).toBeInTheDocument();
    // The zone is named, so the time cannot be misread.
    expect(screen.getByTitle("America/Toronto")).toBeInTheDocument();
    expect(screen.queryByText(/Your time/)).not.toBeInTheDocument();
  });

  it("adds the viewer's own time, labelled, when their clock reads differently", () => {
    render(<SessionTime session={session} batchZone="America/Toronto" viewerZone="Asia/Kathmandu" />);

    expect(screen.getByText("21:00")).toBeInTheDocument();
    expect(screen.getByText(/Your time/)).toBeInTheDocument();
    expect(screen.getByText(/Sun 1 Nov, 06:45/)).toBeInTheDocument();
  });

  it("does not add a second line for a viewer in the batch's own zone", () => {
    render(<SessionTime session={session} batchZone="America/Toronto" viewerZone="America/Toronto" />);

    expect(screen.queryByText(/Your time/)).not.toBeInTheDocument();
  });
});
