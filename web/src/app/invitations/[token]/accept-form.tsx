"use client";

import Link from "next/link";
import { useActionState, useSyncExternalStore } from "react";

import { accept, type AcceptState } from "@/app/invitations/[token]/actions";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button, buttonVariants } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { canonicalZone } from "@/lib/signup";

export function AcceptForm({ token, name }: { token: string; name: string | null }) {
  const [state, action, pending] = useActionState(accept.bind(null, token), { done: false } as AcceptState);
  // Only the browser knows its timezone; on the server there is none to send.
  const zone = useSyncExternalStore(
    () => () => {},
    // Current names, not the legacy ones Chrome reports ("Asia/Katmandu"), which the API refuses.
    () => canonicalZone(Intl.DateTimeFormat().resolvedOptions().timeZone) ?? "",
    () => "",
  );

  if (state.done) {
    return (
      <div className="space-y-4 text-sm">
        <p>You are in. Sign in as {state.email} with the password you just set.</p>
        <Link href="/sign-in" className={buttonVariants({ className: "w-full" })}>
          Sign in
        </Link>
      </div>
    );
  }

  return (
    <form action={action} className="space-y-4">
      {/* The browser's own timezone, so times are shown in the newcomer's clock from day one. */}
      <input type="hidden" name="timezone" value={zone} />
      <div className="space-y-1.5">
        <Label htmlFor="name">Your name</Label>
        <Input id="name" name="name" defaultValue={name ?? ""} autoComplete="name" />
      </div>
      <div className="space-y-1.5">
        <Label htmlFor="password">Choose a password</Label>
        <Input id="password" name="password" type="password" autoComplete="new-password" minLength={12} required aria-invalid={state.passwordError ? true : undefined} />
        <p className={state.passwordError ? "text-xs text-bad" : "text-xs text-muted-foreground"}>
          {state.passwordError ?? "At least 12 characters. A short phrase works well."}
        </p>
      </div>
      {state.error && (
        <Alert variant="destructive">
          <AlertDescription>{state.error}</AlertDescription>
        </Alert>
      )}
      <Button type="submit" className="w-full" disabled={pending}>
        {pending ? "Joining…" : "Accept and join"}
      </Button>
    </form>
  );
}
