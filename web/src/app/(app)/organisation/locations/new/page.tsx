import type { Metadata } from "next";
import Link from "next/link";
import { redirect } from "next/navigation";

import { LocationForm } from "@/app/(app)/organisation/location-form";
import { PageHeader } from "@/components/app/page-header";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { api, type Paginated } from "@/lib/api";
import { getMe } from "@/lib/me";
import { can } from "@/lib/permissions";
import type { Brand } from "@/lib/types";

export const metadata: Metadata = { title: "Add a location" };

export default async function NewLocationPage() {
  const me = await getMe();

  if (!can(me, "organisation.manage")) {
    redirect("/");
  }

  const brands = await api<Paginated<Brand>>("brands");

  // Nothing to attach a location to yet; the details form comes first.
  if (brands.data.length === 0) {
    redirect("/organisation");
  }

  return (
    <>
      <PageHeader
        title="Add a location"
        description="Somewhere you teach. Its timezone and week structure decide how classes there are scheduled."
        actions={
          <Button asChild variant="ghost" size="sm">
            <Link href="/organisation">Your academy</Link>
          </Button>
        }
      />

      <Card className="max-w-2xl">
        <CardContent className="pt-6">
          <LocationForm
            brands={brands.data.map((brand) => ({ id: brand.id, name: brand.name }))}
            browserTimezone={me.timezone ?? "UTC"}
          />
        </CardContent>
      </Card>
    </>
  );
}
