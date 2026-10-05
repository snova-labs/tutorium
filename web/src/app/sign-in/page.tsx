import type { Metadata } from "next";

import { SignInForm } from "@/app/sign-in/sign-in-form";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

export const metadata: Metadata = { title: "Sign in" };

export default function SignInPage() {
  return (
    <main className="flex flex-1 items-center justify-center p-6">
      <div className="w-full max-w-sm">
        <div className="mb-6 flex items-center gap-2">
          <span className="grid size-8 place-items-center rounded-md bg-primary text-sm font-bold text-primary-foreground">
            T
          </span>
          <span className="font-semibold">Tutorium</span>
        </div>

        <Card>
          <CardHeader>
            <CardTitle>Sign in</CardTitle>
            <CardDescription>Staff accounts only. Guardians and learners do not have logins.</CardDescription>
          </CardHeader>
          <CardContent>
            <SignInForm />
          </CardContent>
        </Card>

        <p className="mt-4 text-center text-[11px] text-faint">
          Five failed attempts pause sign-in for this address for fifteen minutes.
        </p>
      </div>
    </main>
  );
}
