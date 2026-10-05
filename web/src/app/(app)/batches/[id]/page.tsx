import type { Metadata } from "next";
import { notFound } from "next/navigation";

import Link from "next/link";

import { PageHeader } from "@/components/app/page-header";
import { ProvenanceChip } from "@/components/app/provenance-chip";
import { SessionTime } from "@/components/app/session-time";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { api, type Paginated } from "@/lib/api";
import { isApiError } from "@/lib/api-error";
import { getMe } from "@/lib/me";
import { can } from "@/lib/permissions";
import type { Batch, ClassSession } from "@/lib/types";

export const metadata: Metadata = { title: "Batch" };

export default async function BatchPage({ params }: PageProps<"/batches/[id]">) {
  const { id } = await params;

  if (!/^\d+$/.test(id)) {
    notFound();
  }

  let batch: Batch;
  let sessions: Paginated<ClassSession>;

  try {
    [batch, sessions] = await Promise.all([
      api<{ data: Batch }>(`batches/${id}`).then((r) => r.data),
      api<Paginated<ClassSession>>(`batches/${id}/sessions`),
    ]);
  } catch (error) {
    // Another academy's batch reads as not found, exactly as the API intends.
    if (isApiError(error) && (error.status === 404 || error.status === 403)) {
      notFound();
    }

    throw error;
  }

  const me = await getMe();

  return (
    <>
      <PageHeader
        title={batch.name}
        description={[batch.course?.name, batch.branch?.name].filter(Boolean).join(" · ")}
        actions={
          <>
            <ProvenanceChip
              value={batch.timezone}
              inherited={batch.timezone_source === "inherited from branch"}
              inheritedFrom={batch.branch?.name}
              setOn="this batch"
            />
            {can(me, "grades.view") && (
              <Button asChild variant="outline" size="sm">
                <Link href={`/batches/${batch.id}/gradebook`}>Grade book</Link>
              </Button>
            )}
            {can(me, "notes.write") && (
              <Button asChild variant="outline" size="sm">
                <Link href={`/batches/${batch.id}/notes`}>Notes</Link>
              </Button>
            )}
            {can(me, "reports.generate") && (
              <Button asChild variant="outline" size="sm">
                <Link href={`/batches/${batch.id}/reports`}>Reports</Link>
              </Button>
            )}
          </>
        }
      />

      <p className="mb-4 text-sm text-muted-foreground">
        Times are in the batch&apos;s clock, as agreed with families.
        {me.timezone && me.timezone !== batch.timezone && " Your own time is shown underneath where it differs."}
      </p>

      <Card className="py-0">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="pl-4">When</TableHead>
              <TableHead>Type</TableHead>
              <TableHead>Status</TableHead>
              <TableHead className="pr-4 text-right">Register</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {sessions.data.length === 0 && (
              <TableRow>
                <TableCell colSpan={4} className="py-10 text-center text-muted-foreground">
                  No sessions yet. They are generated from the timetable.
                </TableCell>
              </TableRow>
            )}
            {sessions.data.map((session) => (
              <TableRow key={session.id}>
                <TableCell className="pl-4">
                  <SessionTime session={session} batchZone={batch.timezone} viewerZone={me.timezone} />
                </TableCell>
                <TableCell className="text-muted-foreground">{session.session_type.name ?? "—"}</TableCell>
                <TableCell>
                  <Badge variant={session.status === "cancelled" ? "destructive" : "secondary"}>
                    {session.status}
                  </Badge>
                  {session.cancel_reason && (
                    <div className="mt-1 text-xs text-muted-foreground">{session.cancel_reason}</div>
                  )}
                </TableCell>
                <TableCell className="pr-4 text-right">
                  {session.status !== "cancelled" && can(me, "attendance.view") && (
                    <Button asChild variant="outline" size="sm">
                      <Link href={`/sessions/${session.id}/register`}>
                        {can(me, "attendance.record") ? "Take register" : "View register"}
                      </Link>
                    </Button>
                  )}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </Card>
    </>
  );
}
