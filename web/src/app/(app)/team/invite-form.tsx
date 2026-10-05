"use client";

import { useActionState, useEffect, useRef } from "react";
import { toast } from "sonner";

import { invite, type InviteState } from "@/app/(app)/team/actions";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect } from "@/components/ui/native-select";

export function InviteForm({ roles }: { roles: string[] }) {
  const [state, action, pending] = useActionState(invite, { ok: false } as InviteState);
  const form = useRef<HTMLFormElement>(null);

  useEffect(() => {
    if (state.ok) {
      toast.success(state.message ?? "Invitation sent.");
      form.current?.reset();
    }
  }, [state]);

  return (
    <form ref={form} action={action} className="space-y-3">
      <div className="space-y-1.5">
        <Label htmlFor="email">Email</Label>
        <Input id="email" name="email" type="email" required aria-invalid={state.errors?.email ? true : undefined} />
        {state.errors?.email && <p className="text-xs text-bad">{state.errors.email}</p>}
      </div>
      <div className="space-y-1.5">
        <Label htmlFor="name">Name (optional)</Label>
        <Input id="name" name="name" />
      </div>
      <div className="space-y-1.5">
        <Label htmlFor="role_name">Role</Label>
        <NativeSelect id="role_name" name="role_name" defaultValue={roles.includes("Teacher") ? "Teacher" : roles[0]} required>
          {roles.map((role) => (
            <option key={role} value={role}>
              {role}
            </option>
          ))}
        </NativeSelect>
        <p className="text-xs text-muted-foreground">Only roles you hold every permission of are offered.</p>
      </div>
      <div className="flex items-center gap-2">
        <Checkbox id="scope_all_branches" name="scope_all_branches" defaultChecked />
        <Label htmlFor="scope_all_branches" className="font-normal">
          All locations
        </Label>
      </div>
      {!state.ok && state.message && !state.errors?.email && <p className="text-sm text-bad">{state.message}</p>}
      <Button type="submit" disabled={pending || roles.length === 0}>
        {pending ? "Sending…" : "Send invitation"}
      </Button>
    </form>
  );
}
