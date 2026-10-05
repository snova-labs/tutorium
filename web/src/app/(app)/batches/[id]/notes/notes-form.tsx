"use client";

import { useState, useTransition } from "react";
import { toast } from "sonner";

import { saveNotes } from "@/app/(app)/batches/[id]/notes/actions";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import { NativeSelect } from "@/components/ui/native-select";
import type { NoteDraft } from "@/lib/reports";
import type { NoteCategory } from "@/lib/types";

export interface NoteLearner {
  enrollmentId: number;
  name: string;
  number: string;
}

/**
 * The screen a teacher uses at period end: one box per learner, saved in one go. Each note can be
 * kept off the report: half of what a good teacher writes is for colleagues, not for home.
 */
export function NotesForm({
  batchId,
  period,
  categories,
  learners,
}: {
  batchId: number;
  period: string;
  categories: NoteCategory[];
  learners: NoteLearner[];
}) {
  const [categoryId, setCategoryId] = useState(categories[0]?.id ?? 0);
  const defaultOnReport = categories.find((c) => c.id === categoryId)?.report_visible_default ?? true;
  const blank = (onReport: boolean) =>
    Object.fromEntries(learners.map((l) => [l.enrollmentId, { enrollmentId: l.enrollmentId, body: "", onReport }])) as Record<
      number,
      NoteDraft
    >;
  const [drafts, setDrafts] = useState(() => blank(defaultOnReport));
  const [saving, startSaving] = useTransition();

  const update = (enrollmentId: number, change: Partial<NoteDraft>) =>
    setDrafts((current) => ({ ...current, [enrollmentId]: { ...current[enrollmentId], ...change } }));

  const written = Object.values(drafts).filter((d) => d.body.trim() !== "").length;

  const save = () =>
    startSaving(async () => {
      const result = await saveNotes(batchId, period, categoryId, Object.values(drafts));

      if (result.ok) {
        toast.success(result.message);
        setDrafts(blank(defaultOnReport));
      } else {
        toast.error(result.message);
      }
    });

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end gap-3">
        <div className="w-56 space-y-1.5">
          <Label htmlFor="category">Kind of note</Label>
          <NativeSelect
            id="category"
            value={categoryId}
            onChange={(e) => {
              const id = Number(e.target.value);
              const onReport = categories.find((c) => c.id === id)?.report_visible_default ?? true;
              setCategoryId(id);
              // A new kind of note takes its own default for "on the report", for boxes not yet written.
              setDrafts((current) =>
                Object.fromEntries(
                  Object.entries(current).map(([key, draft]) => [key, draft.body.trim() === "" ? { ...draft, onReport } : draft]),
                ),
              );
            }}
          >
            {categories.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </NativeSelect>
        </div>
        <p className="pb-2 text-sm text-muted-foreground">
          {defaultOnReport ? "Shown on the report unless you untick it." : "Kept internal unless you tick it."}
        </p>
      </div>

      <Card className="divide-y py-0">
        {learners.map((learner) => {
          const draft = drafts[learner.enrollmentId];

          return (
            <div key={learner.enrollmentId} className="grid gap-2 p-4 sm:grid-cols-[12rem_1fr]">
              <div>
                <Label htmlFor={`note-${learner.enrollmentId}`} className="font-medium">
                  {learner.name}
                </Label>
                <div className="text-xs text-muted-foreground">{learner.number}</div>
              </div>
              <div className="space-y-2">
                <textarea
                  id={`note-${learner.enrollmentId}`}
                  value={draft.body}
                  onChange={(e) => update(learner.enrollmentId, { body: e.target.value })}
                  rows={2}
                  maxLength={4000}
                  placeholder="Leave empty to skip"
                  className="w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
                <div className="flex items-center gap-2">
                  <Checkbox
                    id={`report-${learner.enrollmentId}`}
                    checked={draft.onReport}
                    onCheckedChange={(checked) => update(learner.enrollmentId, { onReport: checked === true })}
                  />
                  <Label htmlFor={`report-${learner.enrollmentId}`} className="text-xs font-normal text-muted-foreground">
                    Show on the report
                  </Label>
                </div>
              </div>
            </div>
          );
        })}
      </Card>

      <div className="flex items-center gap-3">
        <Button onClick={save} disabled={saving || written === 0}>
          {saving ? "Saving…" : `Save ${written} ${written === 1 ? "note" : "notes"}`}
        </Button>
        <span className="text-sm text-muted-foreground">Empty boxes are skipped.</span>
      </div>
    </div>
  );
}
