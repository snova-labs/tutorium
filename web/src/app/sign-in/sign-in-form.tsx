"use client";

import { useActionState, useRef } from "react";

import { signIn, type SignInState } from "@/app/sign-in/actions";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

const initial: SignInState = { step: "credentials", email: "" };

export function SignInForm() {
  const [state, action, pending] = useActionState(signIn, initial);
  // The password is needed again with the emailed code. Kept here, in memory, rather than in a
  // hidden field, so it is never written into the page.
  const password = useRef("");

  const submit = (formData: FormData) => {
    if (state.step === "credentials") {
      password.current = String(formData.get("password") ?? "");
    } else {
      formData.set("password", password.current);
      formData.set("email", state.email);
    }

    return action(formData);
  };

  if (state.step === "code") {
    return (
      <form action={submit} className="space-y-4">
        <p className="text-sm text-muted-foreground">{state.notice ?? "Enter the code we emailed you."}</p>

        <div className="space-y-1.5">
          <Label htmlFor="code">Sign-in code</Label>
          <Input
            id="code"
            name="code"
            inputMode="numeric"
            autoComplete="one-time-code"
            maxLength={16}
            required
            autoFocus
            aria-invalid={state.error ? true : undefined}
          />
        </div>

        {state.error && (
          <Alert variant="destructive">
            <AlertDescription>{state.error}</AlertDescription>
          </Alert>
        )}

        <Button type="submit" className="w-full" disabled={pending}>
          {pending ? "Checking…" : "Sign in"}
        </Button>
        <p className="text-center text-xs text-muted-foreground">
          Signing in as {state.email}. Codes expire after ten minutes.
        </p>
      </form>
    );
  }

  return (
    <form action={submit} className="space-y-4">
      <div className="space-y-1.5">
        <Label htmlFor="email">Work email</Label>
        <Input id="email" name="email" type="email" autoComplete="username" defaultValue={state.email} required autoFocus />
      </div>

      <div className="space-y-1.5">
        <Label htmlFor="password">Password</Label>
        <Input id="password" name="password" type="password" autoComplete="current-password" required />
      </div>

      {state.error && (
        <Alert variant="destructive">
          <AlertDescription>{state.error}</AlertDescription>
        </Alert>
      )}

      <Button type="submit" className="w-full" disabled={pending}>
        {pending ? "Signing in…" : "Sign in"}
      </Button>
    </form>
  );
}
