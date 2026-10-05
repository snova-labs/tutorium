"use client";

import { useActionState } from "react";
import { toast } from "sonner";

import { enrol, type FormResult } from "@/app/(app)/learners/[id]/actions";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect } from "@/components/ui/native-select";

export function EnrolForm({ learnerId, batches }: { learnerId: number; batches: { id: number; label: string }[] }) {
  const [state, action, pending] = useActionState(async (previous: FormResult, formData: FormData) => {
    const result = await enrol(learnerId, previous, formData);

    if (result.ok) {
      toast.success(result.message ?? "Enrolled.");
    }

    return result;
  }, { ok: false } as FormResult);

  if (batches.length === 0) {
    return <p className="text-sm text-muted-foreground">No other batch is taking enrollments.</p>;
  }

  const error = state.errors?.batch_id ?? state.errors?.learner_id ?? (state.ok ? undefined : state.message);

  return (
    <form action={action} className="flex flex-wrap items-end gap-3">
      <div className="min-w-56 flex-1 space-y-1.5">
        <Label htmlFor="batch_id">Batch</Label>
        <NativeSelect id="batch_id" name="batch_id" defaultValue="" required>
          <option value="" disabled>
            Choose…
          </option>
          {batches.map((b) => (
            <option key={b.id} value={b.id}>
              {b.label}
            </option>
          ))}
        </NativeSelect>
      </div>
      <div className="space-y-1.5">
        <Label htmlFor="enrolled_on">From</Label>
        <Input id="enrolled_on" name="enrolled_on" type="date" className="w-40" />
      </div>
      <Button type="submit" disabled={pending}>
        {pending ? "Enrolling…" : "Enrol"}
      </Button>
      {error && <p className="w-full text-sm text-bad">{error}</p>}
      <p className="w-full text-xs text-muted-foreground">Leave the date empty for today, in the batch&apos;s clock.</p>
    </form>
  );
}
