import type { Metadata } from "next";
import Link from "next/link";

import { SignUpForm } from "@/app/sign-up/sign-up-form";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { api } from "@/lib/api";
import type { SignupOptions } from "@/lib/signup";

export const metadata: Metadata = { title: "Create an account" };

export default async function SignUpPage() {
  const options = (await api<{ data: SignupOptions }>("signup/options", { anonymous: true })).data;

  return (
    <main className="flex flex-1 items-center justify-center p-6">
      <div className="w-full max-w-md">
        <div className="mb-6 flex items-center gap-2">
          <span className="grid size-8 place-items-center rounded-md bg-primary text-sm font-bold text-primary-foreground">
            T
          </span>
          <span className="font-semibold">Tutorium</span>
        </div>

        <Card>
          <CardHeader>
            <CardTitle>Start a {options.trial_days}-day trial</CardTitle>
            <CardDescription>{options.terms.join(" ")}</CardDescription>
          </CardHeader>
          <CardContent>
            {options.open ? (
              <SignUpForm options={options} />
            ) : (
              <Alert variant="warn">
                <AlertDescription>New accounts are paused for the moment. Please try again later.</AlertDescription>
              </Alert>
            )}
          </CardContent>
        </Card>

        <p className="mt-4 text-center text-sm text-muted-foreground">
          Already have an account?{" "}
          <Link href="/sign-in" className="font-medium text-foreground underline underline-offset-4">
            Sign in
          </Link>
        </p>
      </div>
    </main>
  );
}
