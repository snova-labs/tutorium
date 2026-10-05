"use client";

import Link from "next/link";
import { useActionState } from "react";

import { createLearner, type NewLearnerState } from "@/app/(app)/learners/new/actions";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

const initial: NewLearnerState = { errors: {}, values: {} };

export function NewLearnerForm() {
  const [state, action, pending] = useActionState(createLearner, initial);

  const field = (name: string, label: string, props: React.ComponentProps<"input"> = {}) => (
    <div className="space-y-1.5">
      <Label htmlFor={name}>{label}</Label>
      <Input
        id={name}
        name={name}
        defaultValue={state.values[name] ?? ""}
        aria-invalid={state.errors[name] ? true : undefined}
        aria-describedby={state.errors[name] ? `${name}-error` : undefined}
        {...props}
      />
      {state.errors[name] && (
        <p id={`${name}-error`} className="text-xs text-bad">
          {state.errors[name]}
        </p>
      )}
    </div>
  );

  return (
    <form action={action} className="space-y-6">
      <Card>
        <CardHeader>
          <CardTitle>Learner</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-4 sm:grid-cols-2">
          {field("legal_name", "Legal name", { required: true, autoFocus: true })}
          {field("preferred_name", "Preferred name")}
          {field("date_of_birth", "Date of birth", { type: "date" })}
          {field("country", "Country (two letters)", { maxLength: 2, className: "uppercase" })}
          {field("email", "Email", { type: "email" })}
          {field("phone", "Phone", { type: "tel" })}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Main guardian</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-4 sm:grid-cols-3">
          {field("guardian_name", "Name")}
          {field("guardian_email", "Email", { type: "email" })}
          {field("guardian_phone", "Phone", { type: "tel" })}
        </CardContent>
      </Card>

      {state.message && !state.duplicates && (
        <Alert variant="destructive">
          <AlertDescription>{state.message}</AlertDescription>
        </Alert>
      )}

      {state.duplicates && (
        <Alert variant="warn">
          <AlertTitle>{state.message}</AlertTitle>
          <AlertDescription>
            <ul className="mt-1 space-y-1">
              {state.duplicates.map((candidate) => (
                <li key={candidate.id}>
                  <span className="font-mono text-xs">{candidate.number}</span>{" "}
                  <Link href={`/learners/${candidate.id}`} className="underline underline-offset-2">
                    {candidate.name}
                  </Link>
                  :{" "}
                  {candidate.confidence} ({candidate.reasons.join(", ")})
                </li>
              ))}
            </ul>
            <p className="mt-2">Two people can share a name. If this is someone new, add them anyway.</p>
          </AlertDescription>
        </Alert>
      )}

      <div className="flex gap-2">
        <Button type="submit" disabled={pending}>
          {pending ? "Saving…" : "Add learner"}
        </Button>
        {state.duplicates && (
          <Button type="submit" name="force" value="1" variant="outline" disabled={pending}>
            Add anyway
          </Button>
        )}
      </div>
    </form>
  );
}
