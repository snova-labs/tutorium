import type { Metadata } from "next";
import Link from "next/link";

import { PageHeader } from "@/components/app/page-header";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { api, type Paginated } from "@/lib/api";
import { getMe } from "@/lib/me";
import { can } from "@/lib/permissions";
import type { Learner } from "@/lib/types";

export const metadata: Metadata = { title: "Learners" };

export default async function LearnersPage({ searchParams }: PageProps<"/learners">) {
  const params = await searchParams;
  const q = typeof params.q === "string" ? params.q : "";
  const page = typeof params.page === "string" ? Number(params.page) || 1 : 1;

  const [me, learners] = await Promise.all([
    getMe(),
    api<Paginated<Learner>>("learners", { query: { q, page } }),
  ]);

  const lastPage = learners.meta?.last_page ?? 1;

  return (
    <>
      <PageHeader
        title="Learners"
        description={learners.meta ? `${learners.meta.total} on file` : undefined}
        actions={
          can(me, "learners.create") && (
            <>
              <Button asChild variant="outline">
                <Link href="/learners/import">Import</Link>
              </Button>
              <Button asChild>
                <Link href="/learners/new">Add learner</Link>
              </Button>
            </>
          )
        }
      />

      <form className="mb-4 max-w-sm" role="search">
        <Input name="q" defaultValue={q} placeholder="Search by name or number" aria-label="Search learners" />
      </form>

      <Card className="py-0">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="pl-4">Number</TableHead>
              <TableHead>Name</TableHead>
              <TableHead>Main guardian</TableHead>
              <TableHead>Status</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {learners.data.length === 0 && (
              <TableRow>
                <TableCell colSpan={4} className="py-10 text-center text-muted-foreground">
                  {q ? `Nobody matches “${q}”.` : "No learners yet. Add one, or import a spreadsheet."}
                </TableCell>
              </TableRow>
            )}
            {learners.data.map((learner) => (
              <TableRow key={learner.id}>
                <TableCell className="pl-4 font-mono text-xs">{learner.number}</TableCell>
                <TableCell>
                  <div className="font-medium">{learner.name.display}</div>
                  {learner.name.preferred && learner.name.preferred !== learner.name.legal && (
                    <div className="text-xs text-muted-foreground">{learner.name.legal}</div>
                  )}
                </TableCell>
                <TableCell className="text-muted-foreground">{learner.guardians?.[0]?.name ?? "—"}</TableCell>
                <TableCell>
                  <Badge variant="secondary">{learner.status.name ?? "—"}</Badge>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </Card>

      {lastPage > 1 && (
        <nav aria-label="Pages" className="mt-4 flex items-center justify-between text-sm">
          <span className="text-muted-foreground">
            Page {page} of {lastPage}
          </span>
          <div className="flex gap-2">
            {page > 1 && (
              <Button asChild variant="outline" size="sm">
                <Link href={{ pathname: "/learners", query: { q, page: page - 1 } }}>Previous</Link>
              </Button>
            )}
            {page < lastPage && (
              <Button asChild variant="outline" size="sm">
                <Link href={{ pathname: "/learners", query: { q, page: page + 1 } }}>Next</Link>
              </Button>
            )}
          </div>
        </nav>
      )}
    </>
  );
}
