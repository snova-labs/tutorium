import type { Metadata } from "next";
import Link from "next/link";

import { AcceptForm } from "@/app/invitations/[token]/accept-form";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { api } from "@/lib/api";
import { isApiError } from "@/lib/api-error";
import { formatLocalDate } from "@/lib/time";

export const metadata: Metadata = { title: "Accept invitation" };

interface Preview {
  academy: string | null;
  role: string;
  email: string;
  name: string | null;
  expires_on: string;
}

/** The page an invitation email opens. Public: the person has no account yet. */
export default async function InvitationPage({ params }: PageProps<"/invitations/[token]">) {
  const { token } = await params;
  let preview: Preview | null = null;
  let problem: string | null = null;

  try {
    preview = (await api<{ data: Preview }>(`invitations/${encodeURIComponent(token)}`, { anonymous: true })).data;
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
            <CardTitle>{preview ? `Join ${preview.academy ?? "the academy"}` : "This invitation cannot be used"}</CardTitle>
            <CardDescription>
              {preview
                ? `As ${preview.role}, signing in as ${preview.email}. The invitation expires on ${formatLocalDate(preview.expires_on)}.`
                : problem}
            </CardDescription>
          </CardHeader>
          <CardContent>
            {preview ? (
              <AcceptForm token={token} name={preview.name} />
            ) : (
              <p className="text-sm text-muted-foreground">
                Already joined?{" "}
                <Link href="/sign-in" className="font-medium text-foreground underline underline-offset-4">
                  Sign in
                </Link>
              </p>
            )}
          </CardContent>
        </Card>
      </div>
    </main>
  );
}
