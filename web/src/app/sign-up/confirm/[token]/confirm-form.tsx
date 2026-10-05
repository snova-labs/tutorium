"use client";

import Link from "next/link";
import { useActionState } from "react";

import { confirmSignup, type ConfirmState } from "@/app/sign-up/actions";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button, buttonVariants } from "@/components/ui/button";

const initial: ConfirmState = { status: "waiting" };

export function ConfirmForm({ token }: { token: string }) {
  const [state, action, pending] = useActionState(confirmSignup.bind(null, token), initial);

  if (state.status === "done") {
    return (
      <div className="space-y-4 text-sm">
        <p>
          <span className="font-medium">{state.account}</span> is ready.
          {state.trialEndsOn && ` Your trial runs until ${state.trialEndsOn}.`}
        </p>
        <Link href="/sign-in" className={buttonVariants({ className: "w-full" })}>
          Sign in
        </Link>
      </div>
    );
  }

  return (
    <form action={action} className="space-y-4">
      {state.error && (
        <Alert variant="destructive">
          <AlertDescription>{state.error}</AlertDescription>
        </Alert>
      )}
      <Button type="submit" className="w-full" disabled={pending}>
        {pending ? "Creating your account…" : "Confirm and create my account"}
      </Button>
    </form>
  );
}
