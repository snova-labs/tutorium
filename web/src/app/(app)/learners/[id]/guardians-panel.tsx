"use client";

import { useActionState, useState, useTransition } from "react";
import { toast } from "sonner";

import { addGuardian, setReportRecipient, type FormResult } from "@/app/(app)/learners/[id]/actions";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import type { Guardian } from "@/lib/types";

export function GuardiansPanel({
  learnerId,
  guardians,
  canManage,
}: {
  learnerId: number;
  guardians: Guardian[];
  canManage: boolean;
}) {
  const [pending, start] = useTransition();
  const [adding, setAdding] = useState(false);

  const toggle = (guardian: Guardian, receives: boolean) =>
    start(async () => {
      const result = await setReportRecipient(learnerId, guardian.id, receives);
      (result.ok ? toast.success : toast.error)(result.message ?? "Saved.");
    });

  return (
    <div className="space-y-4">
      {guardians.length === 0 && <p className="text-sm text-muted-foreground">No guardians yet.</p>}

      <ul className="divide-y">
        {guardians.map((guardian) => (
          <li key={guardian.id} className="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0">
            <div>
              <div className="flex items-center gap-2 font-medium">
                {guardian.name}
                {guardian.link?.is_primary && <Badge variant="secondary">Primary</Badge>}
              </div>
              <div className="text-sm text-muted-foreground">
                {[guardian.relation, guardian.contact.email, guardian.contact.phone].filter(Boolean).join(" · ") || "No contact details"}
              </div>
            </div>
            <div className="flex items-center gap-2">
              <Checkbox
                id={`receives-${guardian.id}`}
                checked={guardian.link?.receives_reports ?? false}
                disabled={!canManage || pending}
                onCheckedChange={(checked) => toggle(guardian, checked === true)}
              />
              <Label htmlFor={`receives-${guardian.id}`} className="text-sm font-normal">
                Receives reports
              </Label>
            </div>
          </li>
        ))}
      </ul>

      {canManage &&
        (adding ? (
          <AddGuardianForm learnerId={learnerId} first={guardians.length === 0} onDone={() => setAdding(false)} />
        ) : (
          <Button variant="outline" size="sm" onClick={() => setAdding(true)}>
            Add a guardian
          </Button>
        ))}
    </div>
  );
}

function AddGuardianForm({ learnerId, first, onDone }: { learnerId: number; first: boolean; onDone: () => void }) {
  const [state, action, pending] = useActionState(async (previous: FormResult, formData: FormData) => {
    const result = await addGuardian(learnerId, previous, formData);

    if (result.ok) {
      toast.success(result.message ?? "Guardian added.");
      onDone();
    }

    return result;
  }, { ok: false } as FormResult);

  return (
    <form action={action} className="space-y-3 rounded-md border p-4">
      <div className="grid gap-3 sm:grid-cols-3">
        <div className="space-y-1.5">
          <Label htmlFor="g-name">Name</Label>
          <Input id="g-name" name="name" required aria-invalid={state.errors?.name ? true : undefined} />
          {state.errors?.name && <p className="text-xs text-bad">{state.errors.name}</p>}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="g-email">Email</Label>
          <Input id="g-email" name="email" type="email" aria-invalid={state.errors?.email ? true : undefined} />
          {state.errors?.email && <p className="text-xs text-bad">{state.errors.email}</p>}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="g-phone">Phone</Label>
          <Input id="g-phone" name="phone" type="tel" />
        </div>
      </div>
      <div className="flex flex-wrap gap-4">
        <div className="flex items-center gap-2">
          <Checkbox id="g-primary" name="is_primary" defaultChecked={first} />
          <Label htmlFor="g-primary" className="font-normal">
            Primary contact
          </Label>
        </div>
        <div className="flex items-center gap-2">
          <Checkbox id="g-reports" name="receives_reports" defaultChecked />
          <Label htmlFor="g-reports" className="font-normal">
            Receives reports
          </Label>
        </div>
      </div>
      <p className="text-xs text-muted-foreground">
        Someone already on file with this email is linked rather than added twice, as for siblings.
      </p>
      {state.message && !state.ok && (
        <Alert variant="destructive">
          <AlertDescription>{state.message}</AlertDescription>
        </Alert>
      )}
      <div className="flex gap-2">
        <Button type="submit" size="sm" disabled={pending}>
          {pending ? "Adding…" : "Add guardian"}
        </Button>
        <Button type="button" size="sm" variant="ghost" onClick={onDone}>
          Cancel
        </Button>
      </div>
    </form>
  );
}
