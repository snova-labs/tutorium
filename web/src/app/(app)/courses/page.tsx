import type { Metadata } from "next";
import Link from "next/link";

import { NewCourseForm } from "@/app/(app)/courses/new-course-form";
import { PageHeader } from "@/components/app/page-header";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { api, type Paginated } from "@/lib/api";
import { getMe, getTerms } from "@/lib/me";
import { can } from "@/lib/permissions";
import type { CourseSummary } from "@/lib/types";

export async function generateMetadata(): Promise<Metadata> {
  return { title: (await getTerms()).course.plural };
}

export default async function CoursesPage() {
  const [me, terms, courses] = await Promise.all([getMe(), getTerms(), api<Paginated<CourseSummary>>("courses")]);
  const manages = can(me, "courses.manage");
  const brands = manages ? (await api<{ data: { id: number; name: string }[] }>("brands")).data : [];

  return (
    <>
      <PageHeader
        title={terms.course.plural}
        description={`What you teach. Each ${terms.batch.singular.toLowerCase()} belongs to one, and takes its reporting periods from it.`}
        actions={
          can(me, "batches.manage") && (
            <Button asChild size="sm">
              <Link href="/batches/new">New {terms.batch.singular.toLowerCase()}</Link>
            </Button>
          )
        }
      />

      <div className="grid gap-6 lg:grid-cols-[1fr_22rem]">
        <Card className="h-fit py-0">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="pl-4">{terms.course.singular}</TableHead>
                <TableHead>Reports</TableHead>
                <TableHead className="pr-4 text-right">{terms.batch.plural}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {courses.data.length === 0 && (
                <TableRow>
                  <TableCell colSpan={3} className="py-10 text-center text-muted-foreground">
                    No {terms.course.plural.toLowerCase()} yet.{manages && " Add the first one here."}
                  </TableCell>
                </TableRow>
              )}
              {courses.data.map((course) => (
                <TableRow key={course.id}>
                  <TableCell className="pl-4">
                    <div className="font-medium">{course.name}</div>
                    <div className="font-mono text-xs text-muted-foreground">{course.code}</div>
                  </TableCell>
                  <TableCell>
                    <Badge variant="secondary">
                      {course.periods.label}
                      {course.periods.type === "block" && `, ${course.periods.block_weeks} weeks`}
                    </Badge>
                    {!course.is_active && <Badge variant="outline" className="ml-1">Archived</Badge>}
                  </TableCell>
                  <TableCell className="pr-4 text-right">{course.batches_count ?? 0}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Card>

        {manages && (
          <Card className="h-fit">
            <CardHeader>
              <CardTitle>New {terms.course.singular.toLowerCase()}</CardTitle>
              <CardDescription>Then add a {terms.batch.singular.toLowerCase()} that teaches it.</CardDescription>
            </CardHeader>
            <CardContent>
              <NewCourseForm brands={brands} />
            </CardContent>
          </Card>
        )}
      </div>
    </>
  );
}
