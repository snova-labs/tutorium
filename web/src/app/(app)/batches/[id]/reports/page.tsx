import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";

import { GenerateForm } from "@/app/(app)/batches/[id]/reports/generate-form";
import { PageHeader } from "@/components/app/page-header";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { api } from "@/lib/api";
import { isApiError } from "@/lib/api-error";
import { getMe } from "@/lib/me";
import { can } from "@/lib/permissions";
import { formatLocalDate } from "@/lib/time";
import type { Batch, Period, ReportReadiness } from "@/lib/types";

export const metadata: Metadata = { title: "Reports" };

/**
 * Before the button, not after: who is ready, who has gaps, and who has nobody to send to, so a
 * coordinator closes the gaps first instead of finding them in a report a parent already has.
 */
export default async function BatchReportsPage({ params, searchParams }: PageProps<"/batches/[id]/reports">) {
  const { id } = await params;
  const query = await searchParams;
  const period = typeof query.period === "string" ? query.period : undefined;

  if (!/^\d+$/.test(id)) {
    notFound();
  }

  let batch: Batch;
  let readiness: ReportReadiness;
  let periods: { all: Period[] };

  try {
    [batch, readiness, periods] = await Promise.all([
      api<{ data: Batch }>(`batches/${id}`).then((r) => r.data),
      api<{ data: ReportReadiness }>(`batches/${id}/reports/readiness`, { query: { period } }).then((r) => r.data),
      api<{ data: { all: Period[] } }>(`batches/${id}/periods`).then((r) => r.data),
    ]);
  } catch (error) {
    if (isApiError(error) && (error.status === 404 || error.status === 403)) {
      notFound();
    }

    throw error;
  }

  const me = await getMe();
  const current = readiness.period;
  const index = periods.all.findIndex((p) => p.label === current.label);
  const previous = index > 0 ? periods.all[index - 1] : undefined;
  const next = index >= 0 && index < periods.all.length - 1 ? periods.all[index + 1] : undefined;
  const sendable = readiness.learners.filter((l) => !l.blocked).length;

  return (
    <>
      <PageHeader
        title={`Reports: ${current.label}`}
        description={`${batch.name} · ${formatLocalDate(current.starts_local_date)} to ${formatLocalDate(current.ends_local_date)}`}
        actions={
          <>
            {previous && (
              <Button asChild variant="outline" size="sm">
                <Link href={{ pathname: `/batches/${id}/reports`, query: { period: previous.label } }}>Previous period</Link>
              </Button>
            )}
            {next && (
              <Button asChild variant="outline" size="sm">
                <Link href={{ pathname: `/batches/${id}/reports`, query: { period: next.label } }}>Next period</Link>
              </Button>
            )}
            <Button asChild variant="ghost" size="sm">
              <Link href={`/batches/${id}`}>Back to batch</Link>
            </Button>
          </>
        }
      />

      <div className="mb-6 grid gap-3 sm:grid-cols-3">
        <Stat label="Ready" value={readiness.ready} tone="good" />
        <Stat label="With gaps" value={readiness.with_gaps} tone={readiness.with_gaps > 0 ? "warn" : "neutral"} />
        <Stat label="Nobody to send to" value={readiness.without_recipients} tone={readiness.without_recipients > 0 ? "bad" : "neutral"} />
      </div>

      <div className="grid gap-6 lg:grid-cols-[1fr_20rem]">
        <Card className="py-0">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="pl-4">Learner</TableHead>
                <TableHead>Ready?</TableHead>
                <TableHead className="pr-4">Recipients</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {readiness.learners.length === 0 && (
                <TableRow>
                  <TableCell colSpan={3} className="py-10 text-center text-muted-foreground">
                    Nobody is actively enrolled in this batch.
                  </TableCell>
                </TableRow>
              )}
              {readiness.learners.map((row) => (
                <TableRow key={row.enrollment_id}>
                  <TableCell className="pl-4 font-medium">{row.learner}</TableCell>
                  <TableCell>
                    {row.readiness.is_ready ? (
                      <Badge variant="secondary">Ready</Badge>
                    ) : (
                      <ul className="space-y-0.5 text-sm text-warn">
                        {row.readiness.gaps.map((gap) => (
                          <li key={gap}>{gap}</li>
                        ))}
                      </ul>
                    )}
                  </TableCell>
                  <TableCell className="pr-4 text-sm">
                    {row.blocked ? (
                      <span className="text-bad">Nobody: add a guardian who receives reports</span>
                    ) : row.recipients > 0 ? (
                      `${row.recipients} ${row.recipients === 1 ? "guardian" : "guardians"}`
                    ) : (
                      "The learner"
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Card>

        {can(me, "reports.generate") && (
          <Card className="h-fit">
            <CardHeader>
              <CardTitle>Generate</CardTitle>
              <CardDescription>
                One PDF per learner for {current.label}, built from attendance, grades and the notes marked for the
                report.
                {readiness.without_recipients > 0 &&
                  ` ${readiness.without_recipients} will be generated but cannot be sent until someone is set to receive them.`}
              </CardDescription>
            </CardHeader>
            <CardContent>
              <GenerateForm
                batchId={batch.id}
                period={current.label}
                total={readiness.learners.length}
                withGaps={readiness.with_gaps}
                canSend={can(me, "reports.send") && sendable > 0}
              />
            </CardContent>
          </Card>
        )}
      </div>
    </>
  );
}

function Stat({ label, value, tone }: { label: string; value: number; tone: "good" | "warn" | "bad" | "neutral" }) {
  const colour = { good: "text-ok", warn: "text-warn", bad: "text-bad", neutral: "text-foreground" }[tone];

  return (
    <Card className="gap-1 py-4">
      <CardContent className="px-4">
        <div className={`text-2xl font-semibold tabular-nums ${colour}`}>{value}</div>
        <div className="text-sm text-muted-foreground">{label}</div>
      </CardContent>
    </Card>
  );
}
