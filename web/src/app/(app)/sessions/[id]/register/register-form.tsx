"use client";

import { useEffect, useMemo, useState, useTransition } from "react";
import { toast } from "sonner";

import { markRemaining, saveRegister } from "@/app/(app)/sessions/[id]/register/actions";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { hasChanges, marksToSave, presentStatus, type Mark } from "@/lib/register";
import type { Register } from "@/lib/types";
import { cn } from "@/lib/utils";

/**
 * Rendered with a key built from the saved marks (see the page), so after a save it starts again
 * from what the server now holds.
 *
 * The register, built for a teacher standing up with a phone and a class waiting: one large
 * button per status, every mark visible at once, one save for the whole class.
 */
export function RegisterForm({ register, canRecord }: { register: Register; canRecord: boolean }) {
  const initial = useMemo(
    () =>
      Object.fromEntries(
        register.roster.map((row) => [row.enrollment_id, { status_id: row.status_id, minutes_late: row.minutes_late }]),
      ) as Record<number, Mark>,
    [register.roster],
  );

  const [marks, setMarks] = useState<Record<number, Mark>>(initial);
  const [pending, startTransition] = useTransition();
  const dirty = hasChanges(register.roster, marks);
  const present = presentStatus(register.statuses);
  const unmarked = register.roster.filter((row) => (marks[row.enrollment_id]?.status_id ?? null) === null).length;

  // Leaving with unsaved marks loses a class's attendance, so the browser asks first.
  useEffect(() => {
    if (!dirty) return;
    const warn = (event: BeforeUnloadEvent) => event.preventDefault();
    window.addEventListener("beforeunload", warn);
    return () => window.removeEventListener("beforeunload", warn);
  }, [dirty]);

  const set = (enrollmentId: number, change: Partial<Mark>) =>
    setMarks((current) => ({
      ...current,
      [enrollmentId]: { ...{ status_id: null, minutes_late: null }, ...current[enrollmentId], ...change },
    }));

  const report = (result: { ok: boolean; message: string }) =>
    result.ok ? toast.success(result.message) : toast.error(result.message);

  const save = () =>
    startTransition(async () => {
      report(await saveRegister(register.session.id, marksToSave(register.roster, marks, register.statuses)));
    });

  const markRest = () => {
    if (!present) return;

    startTransition(async () => {
      if (dirty) {
        // Save what is on screen first, so "the rest" means the rest of what the teacher sees.
        const saved = await saveRegister(register.session.id, marksToSave(register.roster, marks, register.statuses));
        if (!saved.ok) {
          report(saved);
          return;
        }
      }
      report(await markRemaining(register.session.id, present.id));
    });
  };

  if (register.roster.length === 0) {
    return (
      <Card className="p-10 text-center text-sm text-muted-foreground">
        Nobody was enrolled in this batch on the day of this session.
      </Card>
    );
  }

  return (
    <div className="space-y-4">
      <Card className="divide-y py-0">
        {register.roster.map((row) => {
          const mark = marks[row.enrollment_id];
          const selected = register.statuses.find((s) => s.id === mark?.status_id);

          return (
            <div key={row.enrollment_id} className="flex flex-wrap items-center gap-3 px-4 py-3">
              <div className="min-w-40 flex-1">
                <div className="font-medium">{row.name}</div>
                <div className="font-mono text-xs text-muted-foreground">{row.number}</div>
              </div>

              <div role="radiogroup" aria-label={`Attendance for ${row.name}`} className="flex flex-wrap gap-2">
                {register.statuses.map((status) => {
                  const active = mark?.status_id === status.id;

                  return (
                    <button
                      key={status.id}
                      type="button"
                      role="radio"
                      aria-checked={active}
                      disabled={!canRecord || pending}
                      onClick={() => set(row.enrollment_id, { status_id: status.id })}
                      title={status.name}
                      style={active && status.color ? { backgroundColor: status.color, borderColor: status.color } : undefined}
                      className={cn(
                        // At least 44px: this is tapped standing up, on a phone.
                        "h-11 min-w-11 rounded-md border bg-card px-3 text-sm font-semibold text-muted-foreground transition-colors active:scale-95 disabled:opacity-60",
                        active && "text-white",
                        active && !status.color && "bg-primary",
                      )}
                    >
                      {status.name}
                    </button>
                  );
                })}
              </div>

              {selected?.is_late && (
                <label className="flex items-center gap-2 text-xs text-muted-foreground">
                  Minutes late
                  <Input
                    type="number"
                    inputMode="numeric"
                    min={0}
                    max={600}
                    className="h-11 w-20 font-mono"
                    value={mark?.minutes_late ?? ""}
                    disabled={!canRecord || pending}
                    onChange={(e) =>
                      set(row.enrollment_id, { minutes_late: e.target.value === "" ? null : Number(e.target.value) })
                    }
                  />
                </label>
              )}
            </div>
          );
        })}
      </Card>

      {canRecord ? (
        <div className="sticky bottom-0 z-20 -mx-4 flex flex-wrap items-center gap-3 border-t bg-background/95 px-4 py-3 backdrop-blur">
          <Button onClick={save} disabled={pending || !dirty} size="lg">
            {pending ? "Saving…" : "Save register"}
          </Button>
          {present && unmarked > 0 && (
            <Button onClick={markRest} disabled={pending} variant="outline" size="lg">
              Mark the other {unmarked} {present.name.toLowerCase()}
            </Button>
          )}
          <span className="text-xs text-muted-foreground">
            {dirty ? "Unsaved changes." : unmarked === 0 ? "Everyone is marked." : `${unmarked} not marked yet.`}
          </span>
        </div>
      ) : (
        <p className="text-sm text-muted-foreground">You can see this register but not change it.</p>
      )}
    </div>
  );
}
