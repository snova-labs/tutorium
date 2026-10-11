"use client";

import { useActionState, useEffect, useRef } from "react";
import { toast } from "sonner";

import { addClosure, type OrgResult } from "@/app/(app)/organisation/actions";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

/** Closing a day stops sessions being generated for it from here on. */
export function ClosureForm({ branchId }: { branchId: number }) {
  const [state, action, pending] = useActionState(addClosure.bind(null, branchId), { ok: false } as OrgResult);
  const form = useRef<HTMLFormElement>(null);

  useEffect(() => {
    if (state.ok && state.message) {
      toast.success(state.message);
      form.current?.reset();
    }
  }, [state]);

  const error = (field: string) => (state.ok ? undefined : state.errors?.[field]);

  return (
    <form ref={form} action={action} className="space-y-3">
      <div className="flex flex-wrap items-end gap-3">
        <div className="space-y-1.5">
          <Label htmlFor="closure-date">Date</Label>
          <Input id="closure-date" name="date" type="date" required aria-invalid={error("date") ? true : undefined} />
        </div>
        <div className="min-w-48 flex-1 space-y-1.5">
          <Label htmlFor="closure-name">What it is</Label>
          <Input id="closure-name" name="name" required maxLength={120} placeholder="Dashain, staff training, works" />
        </div>
      </div>
      {error("date") && <p className="text-xs text-bad">{error("date")}</p>}
      {error("name") && <p className="text-xs text-bad">{error("name")}</p>}

      <Label className="flex items-start gap-2 text-sm font-normal">
        <Checkbox name="blocks_sessions" defaultChecked className="mt-0.5" />
        <span>
          No teaching that day
          <span className="block text-xs text-muted-foreground">
            Leave this off to mark the day without stopping classes.
          </span>
        </span>
      </Label>
      <Label className="flex items-start gap-2 text-sm font-normal">
        <Checkbox name="cancel_sessions" className="mt-0.5" />
        <span>
          Cancel classes already scheduled that day
          <span className="block text-xs text-muted-foreground">
            Each one keeps its record and says it was closed. Otherwise they stay, and you are told how many there are.
          </span>
        </span>
      </Label>

      {!state.ok && state.message && Object.keys(state.errors ?? {}).length === 0 && (
        <p className="text-sm text-bad">{state.message}</p>
      )}

      <Button type="submit" variant="outline" disabled={pending}>
        {pending ? "Closing…" : "Close this day"}
      </Button>
    </form>
  );
}
