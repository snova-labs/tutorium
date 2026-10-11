import type { Metadata } from "next";
import Link from "next/link";
import { notFound, redirect } from "next/navigation";

import { LocationForm } from "@/app/(app)/organisation/location-form";
import { PageHeader } from "@/components/app/page-header";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { api } from "@/lib/api";
import { isApiError } from "@/lib/api-error";
import { getMe } from "@/lib/me";
import { can } from "@/lib/permissions";
import type { Branch } from "@/lib/types";

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

      <Card className="max-w-2xl">
        <CardContent className="pt-6">
          <LocationForm brands={[]} branch={branch} browserTimezone={me.timezone ?? "UTC"} />
        </CardContent>
      </Card>
    </>
  );
}
