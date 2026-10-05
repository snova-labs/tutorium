import type { Metadata } from "next";
import Link from "next/link";

import { ConfirmForm } from "@/app/sign-up/confirm/[token]/confirm-form";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { api } from "@/lib/api";
import { isApiError } from "@/lib/api-error";

export const metadata: Metadata = { title: "Confirm your account" };

interface Pending {
  academy: string;
  email: string;
  expires_at: string;
}

/**
 * The page an emailed link opens. Showing it uses nothing (mail scanners open links too); the
 * account is created only when the button is pressed.
 */
export default async function ConfirmPage({ params }: PageProps<"/sign-up/confirm/[token]">) {
  const { token } = await params;
  let pending: Pending | null = null;
  let problem: string | null = null;

  try {
    pending = (await api<{ data: Pending }>(`signup/confirm/${encodeURIComponent(token)}`, { anonymous: true })).data;
  } catch (error) {
    if (!isApiError(error)) {
      throw error;
    }

    problem = error.field("token") ?? error.message;
  }

  return (
    <main className="flex flex-1 items-center justify-center p-6">
      <div className="w-full max-w-sm">
        <Card>
          <CardHeader>
            <CardTitle>{pending ? `Create ${pending.academy}` : "This link cannot be used"}</CardTitle>
            <CardDescription>
              {pending ? `For ${pending.email}. Your trial starts when you confirm.` : problem}
            </CardDescription>
          </CardHeader>
          <CardContent>
            {pending ? (
              <ConfirmForm token={token} />
            ) : (
              <p className="text-sm text-muted-foreground">
                <Link href="/sign-up" className="font-medium text-foreground underline underline-offset-4">
                  Sign up again
                </Link>{" "}
                for a new link, or{" "}
                <Link href="/sign-in" className="font-medium text-foreground underline underline-offset-4">
                  sign in
                </Link>{" "}
                if your account already exists.
              </p>
            )}
          </CardContent>
        </Card>
      </div>
    </main>
  );
}
