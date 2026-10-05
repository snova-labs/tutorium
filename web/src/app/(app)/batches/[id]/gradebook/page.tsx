import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";

import { GradeGrid } from "@/app/(app)/batches/[id]/gradebook/grade-grid";
import { PageHeader } from "@/components/app/page-header";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api";
import { isApiError } from "@/lib/api-error";
import { getMe } from "@/lib/me";
import { can } from "@/lib/permissions";
import { formatLocalDate } from "@/lib/time";
import type { GradeBook } from "@/lib/types";

export const metadata: Metadata = { title: "Grade book" };

interface Periods {
  current: { label: string };
  all: { label: string; starts_local_date: string; ends_local_date: string }[];
}

export default async function GradeBookPage({ params, searchParams }: PageProps<"/batches/[id]/gradebook">) {
  const { id } = await params;
  const query = await searchParams;
  const period = typeof query.period === "string" ? query.period : undefined;

  if (!/^\d+$/.test(id)) {
    notFound();
  }

  let book: GradeBook;
  let periods: Periods;

  try {
    [book, periods] = await Promise.all([
      api<{ data: GradeBook }>(`batches/${id}/gradebook`, { query: { period } }).then((r) => r.data),
      api<{ data: Periods }>(`batches/${id}/periods`).then((r) => r.data),
    ]);
  } catch (error) {
    if (isApiError(error) && (error.status === 404 || error.status === 403)) {
      notFound();
    }

    throw error;
  }

  const me = await getMe();
  const index = periods.all.findIndex((p) => p.label === book.period.label);
  const previous = index > 0 ? periods.all[index - 1] : undefined;
  const next = index >= 0 && index < periods.all.length - 1 ? periods.all[index + 1] : undefined;

  return (
    <>
      <PageHeader
        title="Grade book"
        description={`${book.period.label}: ${formatLocalDate(book.period.starts_local_date)} to ${formatLocalDate(
          book.period.ends_local_date,
        )}, in the batch's clock (${book.period.timezone})`}
        actions={
          <>
            {previous && (
              <Button asChild variant="outline" size="sm">
                <Link href={{ pathname: `/batches/${id}/gradebook`, query: { period: previous.label } }}>Previous period</Link>
              </Button>
            )}
            {next && (
              <Button asChild variant="outline" size="sm">
                <Link href={{ pathname: `/batches/${id}/gradebook`, query: { period: next.label } }}>Next period</Link>
              </Button>
            )}
            <Button asChild variant="ghost" size="sm">
              <Link href={`/batches/${id}`}>Back to batch</Link>
            </Button>
          </>
        }
      />

      <GradeGrid key={JSON.stringify(book.rows)} book={book} batchId={Number(id)} canEnter={can(me, "grades.enter")} />
    </>
  );
}
