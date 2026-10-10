import type { StaffMember } from "@/lib/types";

/** ISO weekdays, Monday first, as the API numbers them. */
export const WEEKDAYS = [
  { iso: 1, name: "Monday" },
  { iso: 2, name: "Tuesday" },
  { iso: 3, name: "Wednesday" },
  { iso: 4, name: "Thursday" },
  { iso: 5, name: "Friday" },
  { iso: 6, name: "Saturday" },
  { iso: 7, name: "Sunday" },
];

/** The weekdays starting from the academy's own first day of the week. */
export function weekdaysFrom(firstIso: number): typeof WEEKDAYS {
  const start = WEEKDAYS.findIndex((d) => d.iso === firstIso);

  return start <= 0 ? WEEKDAYS : [...WEEKDAYS.slice(start), ...WEEKDAYS.slice(0, start)];
}

/**
 * A short code suggested from a name ("Grade 8 Maths · Morning" → "GRADE-8-MATHS-MORNING"), in the
 * form the API accepts: letters, digits and dashes, at most 32. Someone can always type their own.
 */
export function suggestCode(name: string): string {
  return name
    .normalize("NFKD")
    .replace(/[̀-ͯ]/g, "")
    .toUpperCase()
    .replace(/[^A-Z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, 32)
    .replace(/-+$/, "");
}

/** "07:00", 90 → "07:00–08:30". Wraps past midnight. */
export function timeRange(start: string, minutes: number): string {
  const [h, m] = start.split(":").map(Number);
  const end = (h * 60 + m + minutes) % (24 * 60);
  const pad = (n: number) => String(n).padStart(2, "0");

  return `${start.slice(0, 5)}–${pad(Math.floor(end / 60))}:${pad(end % 60)}`;
}

/**
 * Who to offer as a class's teachers: active people who teach first (by role), then everyone else
 * active, each group by name. Inactive people are never offered.
 */
export function teacherChoices(staff: StaffMember[]): StaffMember[] {
  const active = staff.filter((s) => s.is_active);
  const teaches = (s: StaffMember) => s.roles.includes("Teacher");
  const byName = (a: StaffMember, b: StaffMember) => a.name.localeCompare(b.name);

  return [...active.filter(teaches).sort(byName), ...active.filter((s) => !teaches(s)).sort(byName)];
}

/**
 * The range to generate sessions for, by default: from today (or the class's start, if later) to
 * its end, or three months on when it has no end date.
 */
export function defaultGenerateRange(startsOn: string, endsOn: string | null, today: string): { from: string; to: string } {
  const from = startsOn > today ? startsOn : today;

  if (endsOn) {
    return { from, to: endsOn };
  }

  const end = new Date(`${from}T00:00:00Z`);
  end.setUTCMonth(end.getUTCMonth() + 3);

  return { from, to: end.toISOString().slice(0, 10) };
}
