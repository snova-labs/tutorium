import type { Metadata } from "next";
import Link from "next/link";

import { PageHeader } from "@/components/app/page-header";
import { ProvenanceChip } from "@/components/app/provenance-chip";
import { Badge } from "@/components/ui/badge";
import { Card } from "@/components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { api, type Paginated } from "@/lib/api";
import type { Batch } from "@/lib/types";

export const metadata: Metadata = { title: "Batches" };

export default async function BatchesPage() {
  const batches = await api<Paginated<Batch>>("batches");

  return (
    <>
      <PageHeader
        title="Batches"
        description="Each batch runs on its own clock: its location's, unless it was set on the batch."
      />

      <Card className="py-0">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="pl-4">Batch</TableHead>
              <TableHead>Location</TableHead>
              <TableHead>Clock</TableHead>
              <TableHead>Runs</TableHead>
              <TableHead>Status</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {batches.data.length === 0 && (
              <TableRow>
                <TableCell colSpan={5} className="py-10 text-center text-muted-foreground">
                  No batches you can see yet.
                </TableCell>
              </TableRow>
            )}
            {batches.data.map((batch) => (
              <TableRow key={batch.id}>
                <TableCell className="pl-4">
                  <Link href={`/batches/${batch.id}`} className="font-medium hover:underline">
                    {batch.name}
                  </Link>
                  {batch.course && <div className="text-xs text-muted-foreground">{batch.course.name}</div>}
                </TableCell>
                <TableCell className="text-muted-foreground">{batch.branch?.name ?? "—"}</TableCell>
                <TableCell>
                  <ProvenanceChip
                    value={batch.timezone}
                    inherited={batch.timezone_source === "inherited from branch"}
                    inheritedFrom={batch.branch?.name}
                    setOn="this batch"
                  />
                </TableCell>
                <TableCell className="font-mono text-xs">
                  {batch.runs.starts_on} → {batch.runs.ends_on ?? "open"}
                </TableCell>
                <TableCell>
                  <Badge variant="secondary">{batch.status}</Badge>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </Card>
    </>
  );
}
