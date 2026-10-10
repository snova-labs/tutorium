import type { Metadata } from "next";
import Link from "next/link";
import { redirect } from "next/navigation";

import { NewBatchForm } from "@/app/(app)/batches/new/new-batch-form";
import { PageHeader } from "@/components/app/page-header";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { api, type Paginated } from "@/lib/api";
import { getMe, getTerms } from "@/lib/me";
import { can } from "@/lib/permissions";
import type { Branch, CourseSummary } from "@/lib/types";

export async function generateMetadata(): Promise<Metadata> {
  return { title: `New ${(await getTerms()).batch.singular.toLowerCase()}` };
}

export default async function NewBatchPage() {
  const [me, terms] = await Promise.all([getMe(), getTerms()]);

  if (!can(me, "batches.manage")) {
    redirect("/batches");
  }

  const [courses, branches] = await Promise.all([
    api<Paginated<CourseSummary>>("courses", { query: { active_only: true } }).then((r) => r.data),
    api<Paginated<Branch>>("branches").then((r) => r.data),
  ]);

  return (
    <>
      <PageHeader
        title={`New ${terms.batch.singular.toLowerCase()}`}
        description="Its timetable, teachers and sessions come next, on its own page."
        actions={
          <Button asChild variant="ghost" size="sm">
            <Link href="/batches">All {terms.batch.plural.toLowerCase()}</Link>
          </Button>
        }
      />

      <Card className="max-w-2xl">
        <CardContent>
          {courses.length === 0 ? (
            <Alert variant="warn">
              <AlertDescription>
                Add a {terms.course.singular.toLowerCase()} first: every {terms.batch.singular.toLowerCase()} teaches one.{" "}
                <Link href="/courses" className="font-medium underline underline-offset-4">
                  Go to {terms.course.plural.toLowerCase()}
                </Link>
              </AlertDescription>
            </Alert>
          ) : (
            <NewBatchForm
              courses={courses.map((c) => ({ id: c.id, label: c.name }))}
              branches={branches.map((b) => ({ id: b.id, label: `${b.name} · ${b.locale_rules.timezone}` }))}
              today={new Date().toISOString().slice(0, 10)}
              courseNoun={terms.course.singular}
            />
          )}
        </CardContent>
      </Card>
    </>
  );
}
