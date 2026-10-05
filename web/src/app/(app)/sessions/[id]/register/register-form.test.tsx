import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import type { Register } from "@/lib/types";

const saveRegister = vi.fn(async () => ({ ok: true, message: "1 recorded, 0 updated." }));
const markRemaining = vi.fn(async () => ({ ok: true, message: "done" }));

vi.mock("@/app/(app)/sessions/[id]/register/actions", () => ({
  saveRegister: (...args: unknown[]) => saveRegister(...(args as [])),
  markRemaining: (...args: unknown[]) => markRemaining(...(args as [])),
}));
vi.mock("sonner", () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

const { RegisterForm } = await import("@/app/(app)/sessions/[id]/register/register-form");

const register: Register = {
  session: {
    id: 5,
    batch: { id: 1, name: "Grade 6" },
    type: "Lesson",
    local: { date: "2026-10-31", time: "07:30", timezone: "Asia/Kathmandu" },
    status: "scheduled",
  },
  policy: { is_compulsory: true, allow_late_join: true, late_grace_min: 10, low_threshold_pct: 75, origins: {} },
  counts_in_rate: true,
  statuses: [
    { id: 1, name: "Present", code: "PRESENT", counts_as_attended: true, counts_in_rate: true, is_late: false, color: null },
    { id: 2, name: "Late", code: "LATE", counts_as_attended: true, counts_in_rate: true, is_late: true, color: null },
  ],
  roster: [
    { enrollment_id: 10, learner_id: 1, number: "L-1", name: "Asha", status_id: null, minutes_late: null, note: null, marked: false },
    { enrollment_id: 11, learner_id: 2, number: "L-2", name: "Bikash", status_id: null, minutes_late: null, note: null, marked: false },
  ],
};

describe("RegisterForm", () => {
  it("asks for minutes only for a late mark, and saves only what was marked", async () => {
    const user = userEvent.setup();
    render(<RegisterForm register={register} canRecord />);

    const save = screen.getByRole("button", { name: "Save register" });
    expect(save).toBeDisabled();
    expect(screen.queryByLabelText(/Minutes late/)).not.toBeInTheDocument();

    const asha = screen.getByRole("radiogroup", { name: "Attendance for Asha" });
    await user.click(asha.querySelector('[title="Late"]') as HTMLElement);
    await user.type(screen.getByLabelText(/Minutes late/), "6");

    expect(save).toBeEnabled();
    await user.click(save);

    expect(saveRegister).toHaveBeenCalledWith(5, [{ enrollment_id: 10, status_id: 2, minutes_late: 6 }]);
  });

  it("offers to mark the rest present, naming how many are left", () => {
    render(<RegisterForm register={register} canRecord />);

    expect(screen.getByRole("button", { name: "Mark the other 2 present" })).toBeInTheDocument();
  });

  it("shows the marks but no controls to someone who cannot record", () => {
    render(<RegisterForm register={register} canRecord={false} />);

    expect(screen.queryByRole("button", { name: "Save register" })).not.toBeInTheDocument();
    expect(screen.getByText("You can see this register but not change it.")).toBeInTheDocument();
    for (const radio of screen.getAllByRole("radio")) {
      expect(radio).toBeDisabled();
    }
  });
});
