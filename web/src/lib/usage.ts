import type { LicenceStatus, Usage, UsageInvoice, UsageMeter } from "@/lib/types";
import { formatLocalDateShort } from "@/lib/time";

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

/**
 * What a self-hosted install needs told about its licence today, or nothing.
 *
 * Hosted academies never see this: they have a subscription, and the server sends null. A lapsed
 * licence reads as a problem rather than a warning, because new enrollments are already being
 * refused — but it is still only a notice, since attendance, grades and exports all carry on.
 *
 * The sentence is composed here rather than printed as the server sent it, so the dates in it are
 * the reader's dates. The server says the same things to `platform:licence` and `platform:verify`,
 * in ISO, for an administrator reading a terminal; keep the two in step. Its sentence is still the
 * fallback for the case this cannot phrase: a file nobody could read at all.
 */
export function licenceNotice(
  status: LicenceStatus | null,
): { tone: "bad" | "warn"; message: string; expires: string | null } | null {
  if (status === null) {
    return null;
  }

  const licence = status.licence;
  const tone = status.ok ? "warn" : "bad";

  if (licence === null) {
    return status.warning === null ? null : { tone, message: status.warning, expires: null };
  }

  const expires = formatLocalDateShort(licence.expires_on);
  const graceEnds = formatLocalDateShort(licence.grace_ends_on);
  const message = licenceMessage(status, licence, expires, graceEnds);

  return message === null ? null : { tone, message, expires: licence.expires_on };
}

function licenceMessage(
  status: LicenceStatus,
  licence: NonNullable<LicenceStatus["licence"]>,
  expires: string,
  graceEnds: string,
): string | null {
  if (licence.lapsed) {
    return (
      `The licence expired on ${expires} and its grace period ended on ${graceEnds}. New enrollments are ` +
      "refused; attendance, grades, reports and exports all still work."
    );
  }

  if (licence.in_grace) {
    return (
      `The licence expired on ${expires}. This install keeps working until ${graceEnds}, and then stops ` +
      "taking new enrollments. Nothing already recorded is affected."
    );
  }

  if (licence.days_left <= 30) {
    return `The licence runs out on ${expires}, in ${licence.days_left === 1 ? "a day" : `${licence.days_left} days`}.`;
  }

  return capMessage(status, licence);
}

/** A cap filling up, said while there is still time to do something about it. */
function capMessage(status: LicenceStatus, licence: NonNullable<LicenceStatus["licence"]>): string | null {
  const cap = licence.max_learners;
  const now = status.learners_now;

  if (cap === null || cap === 0 || now === null) {
    return null;
  }

  if (now >= cap) {
    return (
      `All ${cap} learner places on the licence are in use. Nobody new can be enrolled until a place frees ` +
      "up or the licence is raised; everyone already enrolled carries on as normal."
    );
  }

  // The server decides when this starts being worth saying, and says so by sending a warning.
  return status.warning === null ? null : `${now} of the ${cap} learner places on the licence are in use.`;
}

function learners(count: number): string {
  return count === 1 ? "learner" : "learners";
}

/**
 * "9 October" for a local calendar date.
 *
 * Built from the parts rather than parsed as an instant: a date parsed as midnight shifts a day
 * behind a negative offset, which is what lib/time's formatters exist to avoid.
 */
function shortDate(date: string): string {
  const [year, month, day] = date.split("-").map(Number);

  return new Intl.DateTimeFormat("en-GB", { day: "numeric", month: "long", timeZone: "UTC" }).format(
    new Date(Date.UTC(year, month - 1, day, 12)),
  );
}
