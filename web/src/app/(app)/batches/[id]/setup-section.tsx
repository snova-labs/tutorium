"use client";

import { useState, type ReactNode } from "react";

/**
 * The setup tools, open while a class still needs them. Open or closed is the person's choice
 * after that: it does not snap shut when generating the first sessions refreshes the page.
 */
export function SetupSection({ initiallyOpen, children }: { initiallyOpen: boolean; children: ReactNode }) {
  const [open, setOpen] = useState(initiallyOpen);

  return (
    <details open={open} onToggle={(e) => setOpen(e.currentTarget.open)} className="mb-8">
      <summary className="mb-4 cursor-pointer text-sm font-medium">Timetable, teachers and sessions</summary>
      {children}
    </details>
  );
}
