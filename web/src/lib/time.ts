/**
 * Displaying times without letting them be misread (SL-LOC-005 §2).
 *
 * The batch's clock is authoritative: a session is at the local date and time that was agreed, in
 * the batch's zone. Any time that could be misread states its zone. Someone viewing from another
 * zone sees their own time as well, never instead.
 *
 * Pure functions on purpose: no "now", no browser zone, so the server and the browser render the
 * same text and the rules can be tested.
 */

/** "Mon 6 Oct" from a local calendar date, without passing it through any zone. */
export function formatLocalDate(date: string, locale = "en-GB"): string {
  const [year, month, day] = date.split("-").map(Number);

  // Noon UTC on that calendar day, formatted in UTC: the date cannot shift whatever the server's zone.
  return new Intl.DateTimeFormat(locale, {
    weekday: "short",
    day: "numeric",
    month: "short",
    timeZone: "UTC",
  }).format(new Date(Date.UTC(year, month - 1, day, 12)));
}

/**
 * "1 Sep 2026" from a local calendar date: no weekday, with the year.
 *
 * For a date whose day of the week carries nothing — a trial's last day, an assessment's due date,
 * when a batch started. `formatLocalDate` keeps the weekday, which a session needs and a table of
 * date ranges does not.
 */
export function formatLocalDateShort(date: string, locale = "en-GB"): string {
  const [year, month, day] = date.split("-").map(Number);

  return new Intl.DateTimeFormat(locale, {
    day: "numeric",
    month: "short",
    year: "numeric",
    timeZone: "UTC",
  }).format(new Date(Date.UTC(year, month - 1, day, 12)));
}

/**
 * A span of local dates as a person would say it: "1–31 October 2026", or both ends when it
 * crosses a month.
 */
export function formatLocalRange(start: string, end: string, locale = "en-GB"): string {
  const [startYear, startMonth, startDay] = start.split("-").map(Number);
  const [endYear, endMonth, endDay] = end.split("-").map(Number);

  if (startYear === endYear && startMonth === endMonth) {
    const month = new Intl.DateTimeFormat(locale, { month: "long", timeZone: "UTC" })
      .format(new Date(Date.UTC(endYear, endMonth - 1, endDay, 12)));

    return `${startDay}–${endDay} ${month} ${endYear}`;
  }

  return `${formatLocalDateShort(start, locale)} – ${formatLocalDateShort(end, locale)}`;
}

/** The short name of a zone at an instant: "NPT", "GMT+5:45", "EDT". */
export function zoneAbbreviation(timeZone: string, at: Date, locale = "en-GB"): string {
  const part = new Intl.DateTimeFormat(locale, { timeZone, timeZoneName: "short" })
    .formatToParts(at)
    .find((p) => p.type === "timeZoneName");

  return part?.value ?? timeZone;
}

/** "17:00" for an instant, in a zone. */
export function formatTimeIn(instant: string | Date, timeZone: string, locale = "en-GB"): string {
  return new Intl.DateTimeFormat(locale, {
    hour: "2-digit",
    minute: "2-digit",
    hourCycle: "h23",
    timeZone,
  }).format(typeof instant === "string" ? new Date(instant) : instant);
}

/** "Tue 7 Oct, 06:15" for an instant, in a zone. */
export function formatDateTimeIn(instant: string | Date, timeZone: string, locale = "en-GB"): string {
  return new Intl.DateTimeFormat(locale, {
    weekday: "short",
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
    hourCycle: "h23",
    timeZone,
  }).format(typeof instant === "string" ? new Date(instant) : instant);
}

/**
 * Whether the viewer's own time is worth showing beside the batch time: only when their zone is
 * known and actually gives a different clock reading for this session.
 */
export function viewerTimeDiffers(
  utcInstant: string,
  batchZone: string,
  viewerZone: string | null | undefined,
): viewerZone is string {
  if (!viewerZone || viewerZone === batchZone) {
    return false;
  }

  return formatDateTimeIn(utcInstant, batchZone) !== formatDateTimeIn(utcInstant, viewerZone);
}
