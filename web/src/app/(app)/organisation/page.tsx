import type { Metadata } from "next";
import Link from "next/link";
import { redirect } from "next/navigation";

import { BrandForm, LogoField } from "@/app/(app)/organisation/brand-form";
import { ActionButton } from "@/app/(app)/settings/action-button";
import { PageHeader } from "@/components/app/page-header";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { api, type Paginated } from "@/lib/api";
import { getMe } from "@/lib/me";
import { archiveLocation, reopenLocation } from "@/app/(app)/organisation/actions";
import { localeSummary } from "@/lib/organisation";
import { can } from "@/lib/permissions";
import type { Branch, Brand } from "@/lib/types";

export const metadata: Metadata = { title: "Your academy" };

export default async function OrganisationPage({ searchParams }: PageProps<"/organisation">) {
  const me = await getMe();

  if (!can(me, "organisation.manage")) {
    redirect("/");
  }

  const [brands, branches] = await Promise.all([
    api<Paginated<Brand>>("brands"),
    api<Paginated<Branch>>("branches"),
  ]);

  const brand = brands.data[0] ?? null;
  const added = (await searchParams).added === "1";

  return (
    <>
      <PageHeader
        title="Your academy"
        description="The name, colours and logo that appear on reports, and the locations you teach at."
      />

      {added && (
        <Alert className="mb-6">
          <AlertDescription>Location added. Classes can now be scheduled at it.</AlertDescription>
        </Alert>
      )}

      <div className="space-y-6">
        <Card>
          <CardHeader>
            <CardTitle>Your details</CardTitle>
            <CardDescription>
              What a guardian sees at the top of a report, and who their reply goes to.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <BrandForm brand={brand} academyName={me.tenant.name} />
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Logo</CardTitle>
            <CardDescription>Shown beside your name on every report.</CardDescription>
          </CardHeader>
          <CardContent>
            <LogoField brand={brand} />
          </CardContent>
        </Card>

        <Card className="py-0">
          <CardHeader className="pt-6">
            <CardTitle>Locations</CardTitle>
            <CardDescription>
              A location&apos;s timezone and week decide how everything taught there is scheduled. Add one for each place you
              teach, including online.
            </CardDescription>
          </CardHeader>
          <CardContent className="px-0 pb-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="pl-6">Location</TableHead>
                  <TableHead>Time and week</TableHead>
                  <TableHead className="pr-6 text-right">&nbsp;</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {branches.data.length === 0 && (
                  <TableRow>
                    <TableCell colSpan={3} className="py-10 text-center text-muted-foreground">
                      No locations yet. {brand ? "Add your first one." : "Save your details above first."}
                    </TableCell>
                  </TableRow>
                )}
                {branches.data.map((branch) => (
                  <TableRow key={branch.id}>
                    <TableCell className="pl-6">
                      <div className="font-medium">
                        <Link href={`/organisation/locations/${branch.id}`} className="hover:underline">
                          {branch.name}
                        </Link>
                        {branch.is_active === false && (
                          <Badge variant="outline" className="ml-2">
                            Archived
                          </Badge>
                        )}
                      </div>
                      <div className="font-mono text-xs text-muted-foreground">{branch.code}</div>
                    </TableCell>
                    <TableCell className="text-sm text-muted-foreground">{localeSummary(branch)}</TableCell>
                    <TableCell className="pr-6 text-right">
                      {branch.is_active === false ? (
                        <ActionButton size="sm" variant="outline" run={reopenLocation.bind(null, branch.id)}>
                          Reopen
                        </ActionButton>
                      ) : (
                        <ActionButton
                          size="sm"
                          variant="ghost"
                          run={archiveLocation.bind(null, branch.id)}
                          confirm={`Archive ${branch.name}? New enrollments there are blocked. Every record is kept.`}
                          pendingLabel="Archiving…"
                        >
                          Archive
                        </ActionButton>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
            <div className="border-t px-6 py-4">
              <Button asChild size="sm" disabled={!brand}>
                <Link href="/organisation/locations/new">Add a location</Link>
              </Button>
            </div>
          </CardContent>
        </Card>
      </div>
    </>
  );
}
