import type { Branch } from "@/lib/types";

/** Weekday names as the API spells them, Monday first. */
export const WEEK_DAYS = ["monday", "tuesday", "wednesday", "thursday", "friday", "saturday", "sunday"] as const;

export type WeekDay = (typeof WEEK_DAYS)[number];

export function dayLabel(day: string): string {
  return day.charAt(0).toUpperCase() + day.slice(1);
}

/**
 * Timezone names for a picker, from the browser or Node when either knows them, and a short list
 * of common ones when neither does. Someone in a zone the fallback misses can still be served by
 * the API, which accepts any IANA name.
 */
export function timezoneChoices(current?: string | null): string[] {
  const supported =
    typeof Intl.supportedValuesOf === "function" ? (Intl.supportedValuesOf("timeZone") as string[]) : FALLBACK_ZONES;

  // Some platforms leave UTC out of the list although they accept it.
  const zones = supported.length > 0 ? supported : FALLBACK_ZONES;
  const withUtc = zones.includes("UTC") ? zones : [...zones, "UTC"];

  // The one in use comes first and is never missing, whatever the platform knows — an older
  // platform may also spell a zone the old way ("Asia/Katmandu"), and the API takes either.
  return current && !withUtc.includes(current) ? [current, ...withUtc] : withUtc;
}

const FALLBACK_ZONES = [
  "Asia/Kathmandu",
  "Asia/Kolkata",
  "Asia/Karachi",
  "Asia/Dhaka",
  "Asia/Dubai",
  "Asia/Riyadh",
  "Asia/Singapore",
  "Europe/London",
  "Europe/Berlin",
  "America/New_York",
  "America/Toronto",
  "America/Los_Angeles",
  "Australia/Sydney",
  "UTC",
];

/** How a location reads in a list: where it is in time, and which days it does not teach. */
export function localeSummary(branch: Pick<Branch, "locale_rules">): string {
  const rules = branch.locale_rules;
  const parts = [rules.timezone];

  if (rules.week_start) {
    parts.push(`week starts ${dayLabel(rules.week_start)}`);
  }

  parts.push(
    rules.weekend_days && rules.weekend_days.length > 0
      ? `weekend ${rules.weekend_days.map(dayLabel).join(" and ")}`
      : "no weekend set",
  );

  return parts.join(" · ");
}

/**
 * A colour the report stylesheet can take: "#RGB" or "#RRGGBB". Anything else is refused here
 * rather than written into a style attribute, and the API keeps its own check.
 */
export function isHexColor(value: string): boolean {
  return /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(value.trim());
}

/** What the colour boxes should show: the brand's own, or the product's defaults. */
export const DEFAULT_COLORS = { primary: "#1B5FAA", accent: "#7C3AED" };

export function colorOr(colors: Record<string, string> | null | undefined, key: "primary" | "accent"): string {
  const value = colors?.[key];

  return value && isHexColor(value) ? value : DEFAULT_COLORS[key];
}
