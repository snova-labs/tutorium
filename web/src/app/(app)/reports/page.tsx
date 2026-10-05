import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";

import { ReportActions } from "@/app/(app)/reports/report-actions";
import { PageHeader } from "@/components/app/page-header";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { api } from "@/lib/api";
import { getMe } from "@/lib/me";
import { can } from "@/lib/permissions";
import { deliverySummary, retryable } from "@/lib/reports";
import { formatDateTimeIn } from "@/lib/time";
import type { ArchivedReport } from "@/lib/types";

export const metadata: Metadata = { title: "Reports" };

/** Every report generated, newest first: what was sent, to whom, and what to do about a failure. */
export default async function ReportsPage({ searchParams }: PageProps<"/reports">) {
  const me = await getMe();

  if (!can(me, "reports.view_archive")) {
    notFound();
  }

  const query = await searchParams;
  const batchId = typeof query.batch_id === "string" && /^\d+$/.test(query.batch_id) ? query.batch_id : undefined;
  const period = typeof query.period === "string" ? query.period : undefined;

  const reports = await api<{ data: ArchivedReport[]; meta: { total: number; per_page: number } }>("reports", {
    query: { batch_id: batchId, period },
  });

  const zone = me.timezone ?? "UTC";
  const filtered = batchId !== undefined || period !== undefined;

  return (
    <>
      <PageHeader
        title="Reports"
        description={
          reports.meta.total > reports.data.length
            ? `The newest ${reports.data.length} of ${reports.meta.total}.`
            : `${reports.meta.total} ${reports.meta.total === 1 ? "report" : "reports"}${period ? ` for ${period}` : ""}.`
        }
        actions={
          filtered && (
            <Button asChild variant="ghost" size="sm">
              <Link href="/reports">Show all</Link>
            </Button>
          )
        }
      />

      <Card className="py-0">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="pl-4">Report</TableHead>
              <TableHead>Learner</TableHead>
              <TableHead>Period</TableHead>
              <TableHead>Generated</TableHead>
              <TableHead>Delivery</TableHead>
              <TableHead className="pr-4 text-right" />
            </TableRow>
          </TableHeader>
          <TableBody>
            {reports.data.length === 0 && (
              <TableRow>
                <TableCell colSpan={6} className="py-10 text-center text-muted-foreground">
                  No reports yet. Generate them from a batch&apos;s Reports page.
                </TableCell>
              </TableRow>
            )}
            {reports.data.map((report) => {
              const summary = deliverySummary(report.deliveries);
              const failed = retryable(report);

              return (
                <TableRow key={report.id}>
                  <TableCell className="pl-4 font-mono text-xs">{report.number}</TableCell>
                  <TableCell className="font-medium">{report.learner}</TableCell>
                  <TableCell>{report.period}</TableCell>
                  <TableCell className="text-sm text-muted-foreground">{formatDateTimeIn(report.generated_at_utc, zone)}</TableCell>
                  <TableCell>
                    <Badge variant={summary.tone === "bad" ? "destructive" : "secondary"}>{summary.label}</Badge>
                    {failed.map((d) => (
                      <div key={d.id} className="mt-1 text-xs text-bad">
                        {d.to}: {d.error ?? d.status}
                      </div>
                    ))}
                  </TableCell>
                  <TableCell className="pr-4">
                    <ReportActions
                      reportId={report.id}
                      sent={report.deliveries.length > 0}
                      failed={failed}
                      canSend={can(me, "reports.send")}
                    />
                  </TableCell>
                </TableRow>
              );
            })}
          </TableBody>
        </Table>
      </Card>
    </>
  );
}
