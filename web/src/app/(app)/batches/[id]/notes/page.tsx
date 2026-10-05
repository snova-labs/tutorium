import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";

import { NotesForm } from "@/app/(app)/batches/[id]/notes/notes-form";
import { PageHeader } from "@/components/app/page-header";
import { Button } from "@/components/ui/button";
import { api, type Paginated } from "@/lib/api";
import { isApiError } from "@/lib/api-error";
import { getMe } from "@/lib/me";
import { can } from "@/lib/permissions";
import type { Batch, Enrollment, NoteCategory, Period } from "@/lib/types";

export const metadata: Metadata = { title: "Notes" };

export default async function BatchNotesPage({ params, searchParams }: PageProps<"/batches/[id]/notes">) {
  const { id } = await params;
  const query = await searchParams;

  if (!/^\d+$/.test(id)) {
    notFound();
  }

  const me = await getMe();

  if (!can(me, "notes.write")) {
    notFound();
  }

  let batch: Batch;
  let enrollments: Paginated<Enrollment>;
  let categories: NoteCategory[];
  let periods: { current: Period; all: Period[] };

  try {
    [batch, enrollments, categories, periods] = await Promise.all([
      api<{ data: Batch }>(`batches/${id}`).then((r) => r.data),
      api<Paginated<Enrollment>>("enrollments", { query: { batch_id: id } }),
      api<{ data: NoteCategory[] }>("note-categories").then((r) => r.data),
      api<{ data: { current: Period; all: Period[] } }>(`batches/${id}/periods`).then((r) => r.data),
    ]);
  } catch (error) {
    if (isApiError(error) && (error.status === 404 || error.status === 403)) {
      notFound();
    }

    throw error;
  }

  const period =
    typeof query.period === "string" && periods.all.some((p) => p.label === query.period) ? query.period : periods.current.label;

  // Learners still in the class. An enrollment that has ended keeps its history but needs no new note.
  const learners = enrollments.data
    .filter((e) => e.ended_on === null)
    .map((e) => ({ enrollmentId: e.id, number: e.number, name: e.learner?.name.display ?? e.number }))
    .sort((a, b) => a.name.localeCompare(b.name));

  return (
    <>
      <PageHeader
        title={`Notes: ${period}`}
        description={`${batch.name}. Notes marked for the report appear on ${period}'s report.`}
        actions={
          <Button asChild variant="ghost" size="sm">
            <Link href={`/batches/${id}`}>Back to batch</Link>
          </Button>
        }
      />

      {learners.length === 0 ? (
        <p className="text-sm text-muted-foreground">Nobody is enrolled in this batch yet.</p>
      ) : categories.length === 0 ? (
        <p className="text-sm text-muted-foreground">
          No kinds of note are set up for this academy yet. An administrator can add them in the admin panel.
        </p>
      ) : (
        <NotesForm batchId={batch.id} period={period} categories={categories} learners={learners} />
      )}
    </>
  );
}
