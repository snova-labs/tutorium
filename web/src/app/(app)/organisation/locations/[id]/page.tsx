import type { Metadata } from "next";
import Link from "next/link";
import { notFound, redirect } from "next/navigation";

import { removeClosure } from "@/app/(app)/organisation/actions";
import { ClosureForm } from "@/app/(app)/organisation/closures";
import { LocationForm } from "@/app/(app)/organisation/location-form";
import { ActionButton } from "@/app/(app)/settings/action-button";
import { PageHeader } from "@/components/app/page-header";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { api, type Paginated } from "@/lib/api";
import { isApiError } from "@/lib/api-error";
import { getMe } from "@/lib/me";
import { can } from "@/lib/permissions";
import { formatLocalDate } from "@/lib/time";
import type { Branch, Closure } from "@/lib/types";

export const metadata: Metadata = { title: "Location" };

export default async function LocationPage({ params }: PageProps<"/organisation/locations/[id]">) {
  const me = await getMe();

  if (!can(me, "organisation.manage")) {
    redirect("/");
  }

  const { id } = await params;
  const branch = await api<{ data: Branch }>(`branches/${id}`)
    .then((response) => response.data)
    .catch((error: unknown) => {
      if (isApiError(error) && error.status === 404) {
        notFound();
      }

      throw error;
    });

  const closures = await api<Paginated<Closure>>(`branches/${branch.id}/closures`).then((r) => r.data);

  return (
    <>
      <PageHeader
        title={branch.name}
        description="Changing the timezone or week here affects classes scheduled from now on, not ones already on the calendar."
        actions={
          <>
            {branch.is_active === false && <Badge variant="outline">Archived</Badge>}
            <Button asChild variant="ghost" size="sm">
              <Link href="/organisation">Your academy</Link>
            </Button>
          </>
        }
      />

      <div className="max-w-2xl space-y-6">
        <Card>
          <CardContent className="pt-6">
            <LocationForm brands={[]} branch={branch} browserTimezone={me.timezone ?? "UTC"} />
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Days closed</CardTitle>
            <CardDescription>
              Sessions are not generated for these days, and generating says which days it skipped. Dates already past
              are not listed, because they change nothing from here on.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-5">
            {closures.length === 0 ? (
              <p className="text-sm text-muted-foreground">Nothing closed ahead.</p>
            ) : (
              <ul className="divide-y rounded-lg border">
                {closures.map((closure) => (
                  <li key={closure.id} className="flex items-center justify-between gap-3 px-4 py-2.5">
                    <div>
                      <div className="text-sm font-medium">{closure.name}</div>
                      <div className="text-xs text-muted-foreground">
                        {formatLocalDate(closure.date)}
                        {!closure.blocks_sessions && " · classes still go ahead"}
                      </div>
                    </div>
                    <ActionButton
                      size="sm"
                      variant="ghost"
                      run={removeClosure.bind(null, closure.id)}
                      confirm={`Open ${branch.name} on ${formatLocalDate(closure.date)} again? Sessions already cancelled for it stay cancelled.`}
                      pendingLabel="Removing…"
                    >
                      Remove
                    </ActionButton>
                  </li>
                ))}
              </ul>
            )}

            <ClosureForm branchId={branch.id} />
          </CardContent>
        </Card>
      </div>
    </>
  );
}
