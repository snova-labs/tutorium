import type { Usage, UsageInvoice, UsageMeter } from "@/lib/types";

/**
 * The one sentence the meter has to get across: what this month will be billed as, and why that
 * number rather than today's.
 *
 * The peak is what counts, so a figure that has since come down still bills at the peak. Saying so
 * plainly here is the whole point of showing the meter at all.
 */
export function meterHeadline(meter: UsageMeter): string {
  if (meter.peak === meter.today) {
    return `${meter.today} ${learners(meter.today)} this period.`;
  }

  const on = meter.peak_on ? ` on ${shortDate(meter.peak_on)}` : "";

  return `${meter.peak} ${learners(meter.peak)}: the highest count so far${on}. Today it is ${meter.today}.`;
}

/** Whether the figure has come down since its peak, which is worth saying out loud. */
export function hasFallen(meter: UsageMeter): boolean {
  return meter.peak > meter.today;
}

/** The period as a person would say it: "1–31 October". */
export function periodLabel(period: { start: string; end: string }): string {
  const start = new Date(`${period.start}T00:00:00`);
  const end = new Date(`${period.end}T00:00:00`);
  const sameMonth = start.getMonth() === end.getMonth() && start.getFullYear() === end.getFullYear();

  if (sameMonth) {
    return `${start.getDate()}–${end.getDate()} ${month(end)} ${end.getFullYear()}`;
  }

  return `${shortDate(period.start)} – ${shortDate(period.end)}`;
}

/** A day in the period, as a bar: the proportion of the peak, for a bar chart with no library. */
export function dailyBars(meter: UsageMeter): { date: string; count: number; corrected: boolean; percent: number }[] {
  const highest = Math.max(...meter.daily.map((d) => d.count), 1);

  return meter.daily.map((day) => ({
    ...day,
    // Never zero-width: a day that was counted at zero should still be a visible tick.
    percent: day.count === 0 ? 1 : Math.round((day.count / highest) * 100),
  }));
}

/**
 * How an entitlement reads on the screen, given values can be limits, switches or strings.
 *
 * The object case is defensive rather than expected: a plan feature is stored as `{"value": x}`
 * and the API unwraps it, but a row written by hand or by an older release can still arrive
 * wrapped, and "[object Object]" on a billing screen is the worst of the available answers.
 */
export function entitlementValue(value: number | boolean | string | null | { value?: unknown }): string {
  if (value !== null && typeof value === "object") {
    return entitlementValue((value.value ?? null) as number | boolean | string | null);
  }

  if (value === null) {
    // A limit of null is unlimited, which is not the same as a limit of zero.
    return "Unlimited";
  }

  if (typeof value === "boolean") {
    return value ? "Included" : "Not included";
  }

  return String(value);
}

/**
 * Entitlements worth showing, in a stable order.
 *
 * A default-sourced entitlement is the product's own baseline rather than anything this account
 * chose, so the ones that came from a plan, a licence or an override come first.
 */
export function inPlanOrder(entitlements: Usage["plan"]["entitlements"]): Usage["plan"]["entitlements"] {
  const rank = { override: 0, licence: 1, plan: 2, default: 3 } as const;

  return [...entitlements].sort((a, b) => rank[a.source] - rank[b.source] || a.key.localeCompare(b.key));
}

/** An invoice's status as a badge tone. Overdue is the only one worth alarm. */
export function invoiceTone(status: string): "good" | "bad" | "muted" {
  if (status === "paid") {
    return "good";
  }

  return status === "overdue" || status === "failed" ? "bad" : "muted";
}

/** The total of what is still owed, which no single invoice row tells you. */
export function outstanding(invoices: UsageInvoice[]): UsageInvoice[] {
  return invoices.filter((invoice) => invoice.status !== "paid" && invoice.status !== "void");
}

function learners(count: number): string {
  return count === 1 ? "learner" : "learners";
}

function shortDate(date: string): string {
  const parsed = new Date(`${date}T00:00:00`);

  return `${parsed.getDate()} ${month(parsed)}`;
}

function month(date: Date): string {
  return date.toLocaleDateString("en-GB", { month: "long" });
}
