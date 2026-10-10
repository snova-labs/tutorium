"use client";

import { useState, useTransition } from "react";
import { toast } from "sonner";

import { changeEnrollmentStatus, transferEnrollment, type FormResult } from "@/app/(app)/learners/[id]/actions";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect } from "@/components/ui/native-select";
import { statusChoices, statusConsequence } from "@/lib/settings";
import type { EnrollmentStatusOption } from "@/lib/types";

type Mode = "closed" | "status" | "transfer";

/** Change an enrollment's status, or move the learner to another batch. */
export function EnrollmentControls({
  learnerId,
  enrollmentId,
  currentStatusId,
  statuses,
  batches,
  batchNoun,
}: {
  learnerId: number;
  enrollmentId: number;
  currentStatusId: number;
  statuses: EnrollmentStatusOption[];
  batches: { id: number; label: string }[];
  batchNoun: string;
}) {
  const [mode, setMode] = useState<Mode>("closed");
  const [statusId, setStatusId] = useState("");
  const [batchId, setBatchId] = useState("");
  const [reason, setReason] = useState("");
  const [result, setResult] = useState<FormResult>({ ok: false });
  const [pending, start] = useTransition();

  const choices = statusChoices(statuses, currentStatusId);
  const chosen = choices.find((s) => String(s.id) === statusId);

  const open = (next: Mode) => {
    setMode(next);
    setStatusId("");
    setBatchId("");
    setReason("");
    setResult({ ok: false });
  };

  const submit = (run: () => Promise<FormResult>) =>
    start(async () => {
      const outcome = await run();
      setResult(outcome);

      if (outcome.ok) {
        toast.success(outcome.message ?? "Saved.");
        setMode("closed");
      }
    });

  if (mode === "closed") {
    return (
      <div className="mt-2 flex gap-2">
        {choices.length > 0 && (
          <Button size="sm" variant="outline" onClick={() => open("status")}>
            Change status
          </Button>
        )}
        <Button size="sm" variant="outline" onClick={() => open("transfer")} disabled={batches.length === 0}>
          Move to another {batchNoun}
        </Button>
      </div>
    );
  }

  const error = result.ok ? undefined : (result.errors?.status_id ?? result.errors?.batch_id ?? result.errors?.batch ?? result.message);
  const reasonError = result.ok ? undefined : result.errors?.reason;

  return (
    <form
      className="mt-3 space-y-3 rounded-md border p-3"
      onSubmit={(event) => {
        event.preventDefault();
        submit(() =>
          mode === "status"
            ? changeEnrollmentStatus(learnerId, enrollmentId, Number(statusId), reason)
            : transferEnrollment(learnerId, enrollmentId, Number(batchId), reason),
        );
      }}
    >
      {mode === "status" ? (
        <div className="space-y-1.5">
          <Label htmlFor={`status-${enrollmentId}`}>New status</Label>
          <NativeSelect id={`status-${enrollmentId}`} value={statusId} onChange={(e) => setStatusId(e.target.value)} required>
            <option value="" disabled>
              Choose…
            </option>
            {choices.map((s) => (
              <option key={s.id} value={s.id}>
                {s.name}
              </option>
            ))}
          </NativeSelect>
          {chosen && <p className="text-xs text-muted-foreground">{statusConsequence(chosen)}</p>}
        </div>
      ) : (
        <div className="space-y-1.5">
          <Label htmlFor={`batch-${enrollmentId}`}>Move to</Label>
          <NativeSelect id={`batch-${enrollmentId}`} value={batchId} onChange={(e) => setBatchId(e.target.value)} required>
            <option value="" disabled>
              Choose…
            </option>
            {batches.map((b) => (
              <option key={b.id} value={b.id}>
                {b.label}
              </option>
            ))}
          </NativeSelect>
          <p className="text-xs text-muted-foreground">
            Attendance and grades so far stay where they happened; the new {batchNoun} starts fresh.
          </p>
        </div>
      )}
      <div className="space-y-1.5">
        <Label htmlFor={`reason-${enrollmentId}`}>
          Reason{mode === "status" && chosen?.is_terminal ? "" : " (optional)"}
        </Label>
        <Input
          id={`reason-${enrollmentId}`}
          value={reason}
          maxLength={255}
          required={mode === "status" && chosen?.is_terminal}
          aria-invalid={reasonError ? true : undefined}
          onChange={(e) => setReason(e.target.value)}
        />
        {reasonError && <p className="text-xs text-bad">{reasonError}</p>}
      </div>
      {error && <p className="text-sm text-bad">{error}</p>}
      <div className="flex gap-2">
        <Button type="submit" size="sm" disabled={pending}>
          {pending ? "Saving…" : mode === "status" ? "Change status" : "Move"}
        </Button>
        <Button type="button" size="sm" variant="ghost" disabled={pending} onClick={() => setMode("closed")}>
          Cancel
        </Button>
      </div>
    </form>
  );
}
