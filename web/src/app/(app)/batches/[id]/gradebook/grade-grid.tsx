"use client";

import { useEffect, useMemo, useState, useTransition } from "react";
import { toast } from "sonner";

import { saveGrades } from "@/app/(app)/batches/[id]/gradebook/actions";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { cellKey, changedCells, defaultStatusId, draftFrom, optionsFor, type Draft } from "@/lib/gradebook";
import type { GradeBook } from "@/lib/types";
import { cn } from "@/lib/utils";

const field =
  "h-8 w-full rounded-md border border-input bg-card px-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-60";

/** Rendered with a key built from the saved grades (see the page), so a save starts it afresh. */
export function GradeGrid({ book, batchId, canEnter }: { book: GradeBook; batchId: number; canEnter: boolean }) {
  const initial = useMemo(() => {
    const drafts: Record<string, Draft> = {};

    for (const row of book.rows) {
      for (const assessment of book.assessments) {
        drafts[cellKey(row.enrollment_id, assessment.id)] = draftFrom(
          assessment.scheme.kind,
          row.cells[String(assessment.id)] ?? null,
        );
      }
    }

    return drafts;
  }, [book]);

  const [drafts, setDrafts] = useState(initial);
  const [pending, startTransition] = useTransition();
  const fallbackStatus = defaultStatusId(book.submission_statuses);
  const changed = changedCells(book, drafts);

  useEffect(() => {
    if (changed.length === 0) return;
    const warn = (event: BeforeUnloadEvent) => event.preventDefault();
    window.addEventListener("beforeunload", warn);
    return () => window.removeEventListener("beforeunload", warn);
  }, [changed.length]);

  const update = (key: string, change: Partial<Draft>) =>
    setDrafts((current) => {
      const next = { ...current[key], ...change };

      // Typing a result without choosing a status means it was handed in.
      if (change.value !== undefined && change.value !== "" && next.status_id === null) {
        next.status_id = fallbackStatus;
      }

      return { ...current, [key]: next };
    });

  const save = () =>
    startTransition(async () => {
      const result = await saveGrades(batchId, changed);
      if (result.ok) {
        toast.success(result.message);
      } else {
        toast.error(result.message);
      }
    });

  if (book.assessments.length === 0) {
    return (
      <Card className="p-10 text-center text-sm text-muted-foreground">
        No assessments are due in this period.
      </Card>
    );
  }

  return (
    <div className="space-y-4">
      <Card className="overflow-x-auto py-0">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b">
              <th className="sticky left-0 z-10 bg-card px-4 py-2 text-left text-xs font-medium text-muted-foreground">
                Learner
              </th>
              {book.assessments.map((assessment) => (
                <th key={assessment.id} className="min-w-36 px-2 py-2 text-left align-bottom text-xs font-medium">
                  <div className="text-foreground">{assessment.title}</div>
                  <div className="font-normal text-muted-foreground">
                    {assessment.type} · {assessment.scheme.label}
                    {assessment.scheme.max_points !== null && ` / ${Number(assessment.scheme.max_points)}`}
                  </div>
                  {assessment.due_local_date && (
                    <div className="font-mono font-normal text-faint">due {assessment.due_local_date}</div>
                  )}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {book.rows.map((row) => (
              <tr key={row.enrollment_id} className="border-b last:border-0">
                <th scope="row" className="sticky left-0 z-10 bg-card px-4 py-2 text-left font-normal">
                  <div className="font-medium">{row.name}</div>
                  <div className="font-mono text-xs text-muted-foreground">{row.number}</div>
                </th>
                {book.assessments.map((assessment) => {
                  const key = cellKey(row.enrollment_id, assessment.id);
                  const draft = drafts[key] ?? { status_id: null, value: "" };
                  const original = initial[key];
                  const edited = draft.status_id !== original?.status_id || draft.value !== original?.value;
                  const kind = assessment.scheme.kind;
                  const options = optionsFor(assessment.scheme);
                  const label = `${assessment.title}, ${row.name}`;

                  return (
                    <td key={assessment.id} className={cn("px-2 py-2 align-top", edited && "bg-accent")}>
                      <div className="space-y-1">
                        {kind === "rubric" ? (
                          <div className="font-mono text-sm" title="Rubrics are scored per criterion, outside this grid.">
                            {row.cells[String(assessment.id)]?.raw_score == null
                              ? "—"
                              : Number(row.cells[String(assessment.id)]?.raw_score)}
                          </div>
                        ) : kind === "pass_fail" ? (
                          <select
                            aria-label={`Result for ${label}`}
                            className={field}
                            value={draft.value}
                            disabled={!canEnter || pending}
                            onChange={(e) => update(key, { value: e.target.value })}
                          >
                            <option value="">—</option>
                            <option value="pass">Pass</option>
                            <option value="fail">Fail</option>
                          </select>
                        ) : options.length > 0 ? (
                          <select
                            aria-label={`Result for ${label}`}
                            className={field}
                            value={draft.value}
                            disabled={!canEnter || pending}
                            onChange={(e) => update(key, { value: e.target.value })}
                          >
                            <option value="">—</option>
                            {options.map((option) => (
                              <option key={option} value={option}>
                                {option}
                              </option>
                            ))}
                          </select>
                        ) : (
                          <input
                            aria-label={`Score for ${label}`}
                            inputMode="decimal"
                            className={cn(field, "font-mono")}
                            value={draft.value}
                            disabled={!canEnter || pending}
                            onChange={(e) => update(key, { value: e.target.value })}
                          />
                        )}
                        {kind !== "rubric" && (
                          <select
                            aria-label={`Submission for ${label}`}
                            className={cn(field, "h-7 text-xs text-muted-foreground")}
                            value={draft.status_id ?? ""}
                            disabled={!canEnter || pending}
                            onChange={(e) => update(key, { status_id: e.target.value === "" ? null : Number(e.target.value) })}
                          >
                            <option value="">Not recorded</option>
                            {book.submission_statuses.map((status) => (
                              <option key={status.id} value={status.id}>
                                {status.name}
                              </option>
                            ))}
                          </select>
                        )}
                      </div>
                    </td>
                  );
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </Card>

      {canEnter && (
        <div className="sticky bottom-0 z-20 -mx-4 flex items-center gap-3 border-t bg-background/95 px-4 py-3 backdrop-blur">
          <Button onClick={save} disabled={pending || changed.length === 0} size="lg">
            {pending ? "Saving…" : "Save grades"}
          </Button>
          <span className="text-xs text-muted-foreground">
            {changed.length === 0 ? "No unsaved changes." : `${changed.length} changed ${changed.length === 1 ? "cell" : "cells"}.`}
          </span>
        </div>
      )}
    </div>
  );
}
