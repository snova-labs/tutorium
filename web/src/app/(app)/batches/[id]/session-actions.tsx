"use client";

import { useState, useTransition } from "react";
import { toast } from "sonner";

import { cancelSession, rescheduleSession } from "@/app/(app)/batches/actions";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

/** Cancel one session (with a reason) or move it, from its row. */
export function SessionActions({ batchId, sessionId, date, time }: { batchId: number; sessionId: number; date: string; time: string }) {
  const [mode, setMode] = useState<"closed" | "cancel" | "move">("closed");
  const [reason, setReason] = useState("");
  const [newDate, setNewDate] = useState(date);
  const [newTime, setNewTime] = useState(time);
  const [pending, start] = useTransition();

  const submit = (call: () => ReturnType<typeof cancelSession>) =>
    start(async () => {
      const result = await call();
      (result.ok ? toast.success : toast.error)(result.message ?? "Done.");
      if (result.ok) setMode("closed");
    });

  if (mode === "closed") {
    return (
      <div className="flex justify-end gap-1">
        <Button size="sm" variant="ghost" onClick={() => setMode("move")}>
          Move
        </Button>
        <Button size="sm" variant="ghost" onClick={() => setMode("cancel")}>
          Cancel
        </Button>
      </div>
    );
  }

  return (
    <form
      className="flex flex-wrap items-center justify-end gap-2"
      onSubmit={(event) => {
        event.preventDefault();
        submit(() => (mode === "cancel" ? cancelSession(batchId, sessionId, reason) : rescheduleSession(batchId, sessionId, newDate, newTime)));
      }}
    >
      {mode === "cancel" ? (
        <Input
          aria-label="Why it is cancelled"
          placeholder="Why? (kept on the record)"
          required
          maxLength={255}
          className="h-8 w-56"
          value={reason}
          onChange={(e) => setReason(e.target.value)}
        />
      ) : (
        <>
          <Input aria-label="New date" type="date" required className="h-8 w-40" value={newDate} onChange={(e) => setNewDate(e.target.value)} />
          <Input aria-label="New time" type="time" required className="h-8 w-28" value={newTime} onChange={(e) => setNewTime(e.target.value)} />
        </>
      )}
      <Button type="submit" size="sm" variant={mode === "cancel" ? "destructive" : "default"} disabled={pending}>
        {mode === "cancel" ? "Cancel session" : "Move"}
      </Button>
      <Button type="button" size="sm" variant="ghost" onClick={() => setMode("closed")}>
        Back
      </Button>
    </form>
  );
}
