"use client";

import { Plus, Trash2 } from "lucide-react";
import { useActionState, useEffect, useState } from "react";
import { toast } from "sonner";

import { type AssessmentResult, createAssessment } from "@/app/(app)/batches/[id]/gradebook/actions";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect } from "@/components/ui/native-select";
import {
  type Criterion,
  criteriaTotal,
  defaultDueDate,
  draftErrors,
  isRubric,
  needsMaximum,
  schemeHint,
} from "@/lib/assessments";
import type { GradeBook, GradingVocabularies } from "@/lib/types";

const blank: Criterion = { name: "", max_points: "", descriptor: "" };

export function NewAssessment({
  batchId,
  period,
  vocabularies,
}: {
  batchId: number;
  period: GradeBook["period"];
  vocabularies: GradingVocabularies;
}) {
  const [state, action, pending] = useActionState(createAssessment.bind(null, batchId), { ok: false } as AssessmentResult);
  const [title, setTitle] = useState("");
  const [schemeId, setSchemeId] = useState(String(vocabularies.grading_schemes[0]?.id ?? ""));
  const [maximum, setMaximum] = useState("");
  const [criteria, setCriteria] = useState<Criterion[]>([{ ...blank }]);
  const [shown, setShown] = useState<Record<string, string>>({});

  const scheme = vocabularies.grading_schemes.find((option) => String(option.id) === schemeId);
  const [due, setDue] = useState(() => defaultDueDate(period, new Date().toISOString().slice(0, 10)));

  useEffect(() => {
    if (state.ok && state.message) {
      toast.success(state.message);
      // Cleared in an effect: this is the moment the API confirmed it.
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setTitle("");
      setMaximum("");
      setCriteria([{ ...blank }]);
      setShown({});
    }
  }, [state]);

  // The API's refusal, or the one the form made first; both are keyed the same way.
  const error = (field: string) => shown[field] ?? (state.ok ? undefined : state.errors?.[field]);

  return (
    <form
      action={action}
      onSubmit={(event) => {
        const errors = draftErrors({ title, scheme, maximum, due, criteria });

        if (Object.keys(errors).length > 0) {
          event.preventDefault();
          setShown(errors);
        }
      }}
      className="space-y-4"
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor="title">Title</Label>
          <Input
            id="title"
            name="title"
            maxLength={190}
            value={title}
            onChange={(event) => setTitle(event.target.value)}
            aria-invalid={error("title") ? true : undefined}
          />
          {error("title") && <p className="text-xs text-bad">{error("title")}</p>}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="assessment_type_id">Kind</Label>
          <NativeSelect id="assessment_type_id" name="assessment_type_id" defaultValue={vocabularies.assessment_types[0]?.id}>
            {vocabularies.assessment_types.map((type) => (
              <option key={type.id} value={type.id}>
                {type.name}
              </option>
            ))}
          </NativeSelect>
          <p className="text-xs text-muted-foreground">Decides how it is weighted in the average.</p>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="grading_scheme_id">Marked as</Label>
          <NativeSelect
            id="grading_scheme_id"
            name="grading_scheme_id"
            value={schemeId}
            onChange={(event) => setSchemeId(event.target.value)}
            aria-invalid={error("grading_scheme_id") ? true : undefined}
          >
            {vocabularies.grading_schemes.map((option) => (
              <option key={option.id} value={option.id}>
                {option.name}
              </option>
            ))}
          </NativeSelect>
          {error("grading_scheme_id") ? (
            <p className="text-xs text-bad">{error("grading_scheme_id")}</p>
          ) : (
            <p className="text-xs text-muted-foreground">{schemeHint(scheme, maximum)}</p>
          )}
        </div>
        <div className="grid grid-cols-2 gap-4">
          {needsMaximum(scheme) && (
            <div className="space-y-1.5">
              <Label htmlFor="max_points">Out of</Label>
              <Input
                id="max_points"
                name="max_points"
                type="number"
                min={0.01}
                step="any"
                value={maximum}
                onChange={(event) => setMaximum(event.target.value)}
                aria-invalid={error("max_points") ? true : undefined}
              />
              {error("max_points") && <p className="text-xs text-bad">{error("max_points")}</p>}
            </div>
          )}
          <div className="space-y-1.5">
            <Label htmlFor="due_local_date">Due</Label>
            <Input
              id="due_local_date"
              name="due_local_date"
              type="date"
              required
              value={due}
              onChange={(event) => setDue(event.target.value)}
              min={period.starts_local_date}
              max={period.ends_local_date}
              aria-invalid={error("due_local_date") ? true : undefined}
            />
            {error("due_local_date") ? (
              <p className="text-xs text-bad">{error("due_local_date")}</p>
            ) : (
              <p className="text-xs text-muted-foreground">In the class&apos;s clock ({period.timezone}).</p>
            )}
          </div>
        </div>
      </div>

      {isRubric(scheme) && (
        <fieldset className="space-y-3 rounded-lg border p-4">
          <legend className="px-1 text-sm font-medium">
            Criteria <span className="text-muted-foreground">· {criteriaTotal(criteria)} points in total</span>
          </legend>
          {criteria.map((criterion, index) => (
            <div key={index} className="flex flex-wrap items-end gap-2">
              <div className="min-w-40 flex-1 space-y-1.5">
                <Label htmlFor={`criterion-${index}`} className="text-xs font-normal text-muted-foreground">
                  What is being marked
                </Label>
                <Input
                  id={`criterion-${index}`}
                  name="criterion_name"
                  maxLength={190}
                  value={criterion.name}
                  onChange={(event) =>
                    setCriteria(criteria.map((c, i) => (i === index ? { ...c, name: event.target.value } : c)))
                  }
                />
              </div>
              <div className="w-24 space-y-1.5">
                <Label htmlFor={`criterion-points-${index}`} className="text-xs font-normal text-muted-foreground">
                  Out of
                </Label>
                <Input
                  id={`criterion-points-${index}`}
                  name="criterion_points"
                  type="number"
                  min={0.01}
                  step="any"
                  value={criterion.max_points}
                  onChange={(event) =>
                    setCriteria(criteria.map((c, i) => (i === index ? { ...c, max_points: event.target.value } : c)))
                  }
                />
              </div>
              <div className="min-w-40 flex-1 space-y-1.5">
                <Label htmlFor={`criterion-descriptor-${index}`} className="text-xs font-normal text-muted-foreground">
                  Note for the marker (optional)
                </Label>
                <Input
                  id={`criterion-descriptor-${index}`}
                  name="criterion_descriptor"
                  maxLength={500}
                  value={criterion.descriptor}
                  onChange={(event) =>
                    setCriteria(criteria.map((c, i) => (i === index ? { ...c, descriptor: event.target.value } : c)))
                  }
                />
              </div>
              <Button
                type="button"
                variant="ghost"
                size="icon"
                aria-label={`Remove criterion ${index + 1}`}
                disabled={criteria.length === 1}
                onClick={() => setCriteria(criteria.filter((_, i) => i !== index))}
              >
                <Trash2 className="size-4" />
              </Button>
            </div>
          ))}
          {(error("criteria.0.name") || error("criteria.0.max_points")) && (
            <p className="text-xs text-bad">{error("criteria.0.name") ?? error("criteria.0.max_points")}</p>
          )}
          <Button type="button" variant="outline" size="sm" onClick={() => setCriteria([...criteria, { ...blank }])}>
            <Plus className="size-4" /> Another criterion
          </Button>
        </fieldset>
      )}

      <div className="space-y-1.5">
        <Label htmlFor="description">What the class is asked to do (optional)</Label>
        <Input id="description" name="description" maxLength={2000} />
      </div>

      {!state.ok && state.message && Object.keys(state.errors ?? {}).length === 0 && (
        <p className="text-sm text-bad">{state.message}</p>
      )}

      <Button type="submit" disabled={pending}>
        {pending ? "Adding…" : "Add assessment"}
      </Button>
    </form>
  );
}
