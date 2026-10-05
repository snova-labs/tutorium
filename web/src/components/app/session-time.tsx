import { formatDateTimeIn, formatLocalDate, viewerTimeDiffers, zoneAbbreviation } from "@/lib/time";
import type { ClassSession } from "@/lib/types";

interface SessionTimeProps {
  session: ClassSession;
  /** The batch's zone, which the local date and time belong to. */
  batchZone: string;
  /** The signed-in person's display zone, if they have one. */
  viewerZone?: string | null;
}

/**
 * A session's time, as agreed, with its zone named.
 *
 * The local date and time are shown exactly as stored. They are never recomputed from UTC, so a
 * 21:00 Toronto session on the last day of the month stays on that day. When the viewer's zone
 * gives a different reading, their time is added underneath, labelled as theirs.
 */
export function SessionTime({ session, batchZone, viewerZone }: SessionTimeProps) {
  const zone = zoneAbbreviation(batchZone, new Date(session.utc.starts_at));

  return (
    <div className="leading-tight">
      <div>
        <span className="font-mono">{formatLocalDate(session.local.date)}</span>{" "}
        <span className="font-mono">{session.local.time}</span>{" "}
        <span className="text-xs text-muted-foreground" title={batchZone}>
          {zone}
        </span>
      </div>
      {viewerTimeDiffers(session.utc.starts_at, batchZone, viewerZone) && (
        <div className="text-xs text-muted-foreground">
          Your time:{" "}
          <span className="font-mono">{formatDateTimeIn(session.utc.starts_at, viewerZone)}</span>{" "}
          {zoneAbbreviation(viewerZone, new Date(session.utc.starts_at))}
        </div>
      )}
    </div>
  );
}
