"use client";

import Link from "next/link";
import { useActionState } from "react";

import {
  commitImport,
  discardImport,
  previewImport,
  type ImportState,
} from "@/app/(app)/learners/import/actions";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";

/** One reducer for every step, so the preview survives a failed commit. */
async function step(state: ImportState, formData: FormData): Promise<ImportState> {
  switch (formData.get("intent")) {
    case "preview":
      return previewImport(state, formData);
    case "commit":
      return commitImport(state, formData);
    case "discard":
      return discardImport(state);
    default:
      return state;
  }
}

export function ImportWizard() {
  const [state, action, pending] = useActionState(step, { stage: "upload" } as ImportState);

  if (state.stage === "upload") {
    return (
      <Card>
        <CardHeader>
          <CardTitle>Choose a file</CardTitle>
          <CardDescription>
            An .xlsx or .csv file, up to 5 MB and 5,000 rows. The first row names the columns.{" "}
            <a className="underline" href="/learners/import/template">
              Download the template
            </a>
            .
          </CardDescription>
        </CardHeader>
        <CardContent>
          <form action={action} className="flex flex-wrap items-end gap-3">
            <input type="hidden" name="intent" value="preview" />
            <div className="space-y-1.5">
              <Label htmlFor="file">Spreadsheet</Label>
              <Input id="file" name="file" type="file" accept=".xlsx,.csv" required className="max-w-sm" />
            </div>
            <Button type="submit" disabled={pending}>
              {pending ? "Checking…" : "Check the file"}
            </Button>
          </form>
          {state.error && (
            <Alert variant="destructive" className="mt-4">
              <AlertDescription>{state.error}</AlertDescription>
            </Alert>
          )}
          <p className="mt-4 text-xs text-muted-foreground">Nothing is added until you confirm the next step.</p>
        </CardContent>
      </Card>
    );
  }

  const { preview } = state;
  const totals = preview.totals;

  if (state.stage === "done") {
    return (
      <Alert>
        <AlertTitle>{preview.message}</AlertTitle>
        <AlertDescription>
          <div className="mt-2 flex gap-2">
            <Button asChild size="sm">
              <Link href="/learners">See learners</Link>
            </Button>
            {preview.rejects_url && (
              <Button asChild size="sm" variant="outline">
                <a href={`/learners/import/${preview.id}/rejects`}>Download blocked rows</a>
              </Button>
            )}
          </div>
        </AlertDescription>
      </Alert>
    );
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap gap-2 text-sm">
        <Badge variant="secondary">{preview.file}</Badge>
        <Badge variant="ok">{totals?.ready ?? 0} ready</Badge>
        <Badge variant={totals?.blocked ? "destructive" : "secondary"}>{totals?.blocked ?? 0} blocked</Badge>
        {(totals?.possible_duplicates ?? 0) > 0 && (
          <Badge variant="warn">{totals?.possible_duplicates} possible duplicates</Badge>
        )}
      </div>

      {preview.ignored_columns.length > 0 && (
        <p className="text-sm text-muted-foreground">
          Not imported, because they are not columns we recognise: {preview.ignored_columns.join(", ")}.
        </p>
      )}

      {preview.blocked.length > 0 && (
        <Card className="pb-0">
          <CardHeader>
            <CardTitle>Blocked rows</CardTitle>
            <CardDescription>
              These will not be imported. Row numbers match your spreadsheet.{" "}
              <a className="underline" href={`/learners/import/${preview.id}/rejects`}>
                Download them with the problems listed
              </a>
              , fix them, and import that file again.
            </CardDescription>
          </CardHeader>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="pl-6">Row</TableHead>
                <TableHead>Name</TableHead>
                <TableHead>Problems</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {preview.blocked.map((row) => (
                <TableRow key={row.row}>
                  <TableCell className="pl-6 font-mono">{row.row}</TableCell>
                  <TableCell>{row.values.legal_name ?? <span className="text-faint">(no name)</span>}</TableCell>
                  <TableCell className="whitespace-normal text-bad">{row.reasons.join(" ")}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Card>
      )}

      {preview.possible_duplicates.length > 0 && (
        <Card className="pb-0">
          <CardHeader>
            <CardTitle>Possibly already on file</CardTitle>
            <CardDescription>Left out unless you choose to include them below.</CardDescription>
          </CardHeader>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="pl-6">Row</TableHead>
                <TableHead>Name</TableHead>
                <TableHead>Why</TableHead>
                <TableHead>Existing</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {preview.possible_duplicates.map((row) => (
                <TableRow key={row.row}>
                  <TableCell className="pl-6 font-mono">{row.row}</TableCell>
                  <TableCell>{row.name}</TableCell>
                  <TableCell>{row.reason}</TableCell>
                  <TableCell className="font-mono text-xs">{row.matches.join(", ")}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Card>
      )}

      {state.error && (
        <Alert variant="destructive">
          <AlertDescription>{state.error}</AlertDescription>
        </Alert>
      )}

      <form action={action} className="flex flex-wrap items-center gap-3">
        {preview.possible_duplicates.length > 0 && (
          <div className="flex items-center gap-2">
            <Checkbox id="include_possible_duplicates" name="include_possible_duplicates" />
            <Label htmlFor="include_possible_duplicates" className="text-sm text-foreground">
              Include the possible duplicates
            </Label>
          </div>
        )}
        <Button type="submit" name="intent" value="commit" disabled={pending || (totals?.ready ?? 0) === 0}>
          {pending ? "Working…" : `Import ${totals?.ready ?? 0} ready ${totals?.ready === 1 ? "row" : "rows"}`}
        </Button>
        <Button type="submit" name="intent" value="discard" variant="outline" disabled={pending}>
          Discard
        </Button>
      </form>
    </div>
  );
}
