"use client";

import { useState, useTransition } from "react";
import { toast } from "sonner";

import { addSlot, generateSessions, removeSlot, setTeachers, type SetupResult } from "@/app/(app)/batches/actions";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect } from "@/components/ui/native-select";
import { defaultGenerateRange, teacherChoices, timeRange, weekdaysFrom } from "@/lib/setup";
import type { BatchDetail, SessionTypeOption, StaffMember } from "@/lib/types";

const WEEK_START: Record<string, number> = { monday: 1, tuesday: 2, wednesday: 3, thursday: 4, friday: 5, saturday: 6, sunday: 7 };

/** Report how a call went, the same way everywhere on this page. */
function useRun() {
  const [pending, start] = useTransition();
  const run = (call: () => Promise<SetupResult>, then?: () => void) =>
    start(async () => {
      const result = await call();
      (result.ok ? toast.success : toast.error)(result.message ?? (result.ok ? "Saved." : "That did not work."));
      if (result.ok) then?.();
    });

  return [pending, run] as const;
}

/** Timetable, teachers and sessions: everything that turns a new class into one that runs. */
export function BatchSetup({
  batch,
  sessionTypes,
  staff,
  canSchedule,
  today,
}: {
  batch: BatchDetail;
  sessionTypes: SessionTypeOption[];
  staff: StaffMember[];
  canSchedule: boolean;
  today: string;
}) {
  return (
    <div className="grid gap-6 lg:grid-cols-2">
      <Timetable batch={batch} sessionTypes={sessionTypes} />
      <div className="space-y-6">
        <Teachers batch={batch} staff={staff} />
        {canSchedule && <Generate batch={batch} today={today} />}
      </div>
    </div>
  );
}

function Timetable({ batch, sessionTypes }: { batch: BatchDetail; sessionTypes: SessionTypeOption[] }) {
  const days = weekdaysFrom(WEEK_START[batch.branch?.locale_rules.week_start ?? "monday"] ?? 1);
  const [weekday, setWeekday] = useState(String(days[0].iso));
  const [start, setStart] = useState("09:00");
  const [minutes, setMinutes] = useState("60");
  const [typeId, setTypeId] = useState(String(sessionTypes[0]?.id ?? ""));
  const [pending, run] = useRun();
  const ordered = [...batch.timetable].sort(
    (a, b) => days.findIndex((d) => d.iso === a.weekday.iso) - days.findIndex((d) => d.iso === b.weekday.iso) || a.start_time_local.localeCompare(b.start_time_local),
  );

  return (
    <Card>
      <CardHeader>
        <CardTitle>Timetable</CardTitle>
        <CardDescription>The weekly pattern, in the class&apos;s clock ({batch.timezone}). Sessions are generated from it.</CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        {ordered.length === 0 ? (
          <p className="text-sm text-muted-foreground">No regular times yet. Add the first below.</p>
        ) : (
          <ul className="divide-y rounded-md border">
            {ordered.map((slot) => (
              <li key={slot.id} className="flex items-center justify-between gap-2 px-3 py-2 text-sm">
                <span>
                  <span className="font-medium">{slot.weekday.name}</span> {timeRange(slot.start_time_local, slot.duration_min)}
                  <span className="text-muted-foreground"> · {slot.session_type.name ?? "Session"}</span>
                </span>
                <Button
                  size="sm"
                  variant="ghost"
                  disabled={pending}
                  onClick={() => {
                    if (window.confirm(`Remove ${slot.weekday.name} ${slot.start_time_local.slice(0, 5)} from the timetable?`)) {
                      run(() => removeSlot(batch.id, slot.id));
                    }
                  }}
                >
                  Remove
                </Button>
              </li>
            ))}
          </ul>
        )}

        <form
          className="grid grid-cols-2 gap-3 sm:grid-cols-4"
          onSubmit={(event) => {
            event.preventDefault();
            run(() => addSlot(batch.id, { weekday: Number(weekday), start, minutes: Number(minutes), typeId: Number(typeId) }));
          }}
        >
          <div className="space-y-1.5">
            <Label htmlFor="slot-day">Day</Label>
            <NativeSelect id="slot-day" value={weekday} onChange={(e) => setWeekday(e.target.value)}>
              {days.map((d) => (
                <option key={d.iso} value={d.iso}>
                  {d.name}
                </option>
              ))}
            </NativeSelect>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="slot-start">Starts</Label>
            <Input id="slot-start" type="time" required value={start} onChange={(e) => setStart(e.target.value)} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="slot-minutes">Minutes</Label>
            <Input id="slot-minutes" type="number" min={5} max={600} step={5} required value={minutes} onChange={(e) => setMinutes(e.target.value)} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="slot-type">Type</Label>
            <NativeSelect id="slot-type" value={typeId} onChange={(e) => setTypeId(e.target.value)}>
              {sessionTypes.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.name}
                  {t.counts_in_attendance ? "" : " (not in attendance %)"}
                </option>
              ))}
            </NativeSelect>
          </div>
          <div className="col-span-2 sm:col-span-4">
            <Button type="submit" size="sm" disabled={pending}>
              Add to timetable
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  );
}

function Teachers({ batch, staff }: { batch: BatchDetail; staff: StaffMember[] }) {
  const [chosen, setChosen] = useState<number[]>(batch.teachers.map((t) => t.id));
  const [pending, run] = useRun();
  const choices = teacherChoices(staff);
  const changed = chosen.join() !== batch.teachers.map((t) => t.id).join();

  return (
    <Card>
      <CardHeader>
        <CardTitle>Teachers</CardTitle>
        <CardDescription>They see this class, take its registers and mark its work. The first one ticked leads.</CardDescription>
      </CardHeader>
      <CardContent className="space-y-3">
        <ul className="max-h-64 space-y-2 overflow-y-auto">
          {choices.map((person) => (
            <li key={person.id} className="flex items-center gap-2 text-sm">
              <Checkbox
                id={`teacher-${person.id}`}
                checked={chosen.includes(person.id)}
                onCheckedChange={(on) => setChosen((c) => (on ? [...c, person.id] : c.filter((id) => id !== person.id)))}
              />
              <Label htmlFor={`teacher-${person.id}`} className="font-normal">
                {person.name} <span className="text-muted-foreground">· {person.roles.join(", ")}</span>
              </Label>
            </li>
          ))}
        </ul>
        <Button size="sm" disabled={pending || !changed} onClick={() => run(() => setTeachers(batch.id, chosen))}>
          Save teachers
        </Button>
      </CardContent>
    </Card>
  );
}

function Generate({ batch, today }: { batch: BatchDetail; today: string }) {
  const initial = defaultGenerateRange(batch.runs.starts_on, batch.runs.ends_on, today);
  const [from, setFrom] = useState(initial.from);
  const [to, setTo] = useState(initial.to);
  const [summary, setSummary] = useState<string | null>(null);
  const [pending, start] = useTransition();

  return (
    <Card>
      <CardHeader>
        <CardTitle>Sessions</CardTitle>
        <CardDescription>
          Put the timetable on the calendar for a stretch of dates. Safe to repeat: sessions that already exist are left alone, and
          closure days are skipped. {batch.sessions_count ?? 0} so far.
        </CardDescription>
      </CardHeader>
      <CardContent>
        <form
          className="flex flex-wrap items-end gap-3"
          onSubmit={(event) => {
            event.preventDefault();
            start(async () => {
              const result = await generateSessions(batch.id, from, to);
              setSummary(result.message ?? null);
              (result.ok ? toast.success : toast.error)(result.message ?? "Done.");
            });
          }}
        >
          <div className="space-y-1.5">
            <Label htmlFor="gen-from">From</Label>
            <Input id="gen-from" type="date" required value={from} onChange={(e) => setFrom(e.target.value)} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="gen-to">To</Label>
            <Input id="gen-to" type="date" required value={to} onChange={(e) => setTo(e.target.value)} />
          </div>
          <Button type="submit" disabled={pending || batch.timetable.length === 0}>
            {pending ? "Generating…" : "Generate sessions"}
          </Button>
        </form>
        {batch.timetable.length === 0 && <p className="mt-2 text-xs text-muted-foreground">Add a timetable first.</p>}
        {summary && <p className="mt-3 text-sm" role="status">{summary}</p>}
      </CardContent>
    </Card>
  );
}
