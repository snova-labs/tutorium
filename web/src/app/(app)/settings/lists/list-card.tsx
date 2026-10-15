"use client";

import { useActionState, useEffect, useRef, useState, useTransition } from "react";
import { toast } from "sonner";

import { addTerm, removeTerm, saveTerm, setTermActive, type TermResult } from "@/app/(app)/settings/lists/actions";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import type { Vocabulary, VocabularyTerm } from "@/lib/types";
import { inOfferedOrder, removalBlock, termSummary } from "@/lib/vocabularies";

export function ListCard({ list }: { list: Vocabulary }) {
  const terms = inOfferedOrder(list);

  return (
    <Card>
      <CardHeader>
        <CardTitle>{list.name}</CardTitle>
        <CardDescription>{list.about}</CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        <ul className="divide-y rounded-md border">
          {terms.map((term) => (
            <li key={term.id} className="p-3">
              <TermRow list={list} term={term} />
            </li>
          ))}
          {terms.length === 0 && <li className="p-3 text-sm text-muted-foreground">Nothing in this list yet.</li>}
        </ul>

        {list.can_manage && <AddTerm list={list} />}
      </CardContent>
    </Card>
  );
}

function TermRow({ list, term }: { list: Vocabulary; term: VocabularyTerm }) {
  const [editing, setEditing] = useState(false);
  const blocked = removalBlock(list, term);
  const [pending, start] = useTransition();

  const act = (run: () => Promise<TermResult>) =>
    start(async () => {
      const result = await run();

      if (result.ok) {
        toast.success(result.message ?? "Saved.");
        setEditing(false);
      } else {
        // The reason a term cannot go comes from the API, which is the only thing that knows.
        toast.error(result.errors?.term ?? result.message ?? "That did not work.");
      }
    });

  if (editing) {
    return <TermForm list={list} term={term} onDone={() => setEditing(false)} />;
  }

  return (
    <div className="flex flex-wrap items-center justify-between gap-3">
      <div className="flex items-center gap-2">
        {term.color && (
          <span
            aria-hidden
            className="size-3 shrink-0 rounded-full border"
            style={{ backgroundColor: term.color }}
          />
        )}
        <div>
          <p className="text-sm font-medium">
            {term.name}
            {list.deactivates && !term.is_active && (
              <Badge variant="outline" className="ml-2">
                Off
              </Badge>
            )}
          </p>
          <p className="text-xs text-muted-foreground">{termSummary(list, term)}</p>
        </div>
      </div>

      {list.can_manage && (
        <div className="flex items-center gap-2">
          <Button size="sm" variant="ghost" onClick={() => setEditing(true)} disabled={pending}>
            Change
          </Button>
          {list.deactivates && (
            <Button
              size="sm"
              variant="ghost"
              disabled={pending}
              onClick={() => act(() => setTermActive(list.key, term.id, !term.is_active))}
            >
              {term.is_active ? "Switch off" : "Switch on"}
            </Button>
          )}
          <Button
            size="sm"
            variant="ghost"
            // Removing is only ever offered for a term nothing has used; the title says why not.
            disabled={pending || blocked !== null}
            title={blocked ?? undefined}
            onClick={() => act(() => removeTerm(list.key, term.id))}
          >
            Remove
          </Button>
        </div>
      )}
    </div>
  );
}

function TermForm({ list, term, onDone }: { list: Vocabulary; term: VocabularyTerm; onDone: () => void }) {
  const [state, action, pending] = useActionState(saveTerm.bind(null, list.key, term.id), { ok: false } as TermResult);

  useEffect(() => {
    if (state.ok && state.message) {
      toast.success(state.message);
      onDone();
    }
  }, [state, onDone]);

  return (
    <Fields
      list={list}
      term={term}
      state={state}
      action={action}
      pending={pending}
      submit="Save"
      onCancel={onDone}
    />
  );
}

function AddTerm({ list }: { list: Vocabulary }) {
  const [state, action, pending] = useActionState(addTerm.bind(null, list.key), { ok: false } as TermResult);
  const form = useRef<HTMLFormElement>(null);

  useEffect(() => {
    if (state.ok && state.message) {
      toast.success(state.message);
      form.current?.reset();
    }
  }, [state]);

  return <Fields list={list} formRef={form} state={state} action={action} pending={pending} submit={`Add a ${list.singular}`} />;
}

function Fields({
  list,
  term,
  state,
  action,
  pending,
  submit,
  onCancel,
  formRef,
}: {
  list: Vocabulary;
  /** Absent when adding one. */
  term?: VocabularyTerm;
  state: TermResult;
  action: (formData: FormData) => void;
  pending: boolean;
  submit: string;
  onCancel?: () => void;
  formRef?: React.RefObject<HTMLFormElement | null>;
}) {
  const id = (field: string) => `${list.key}-${term?.id ?? "new"}-${field}`;
  const error = (field: string) => (state.ok ? undefined : state.errors?.[field]);

  return (
    <form ref={formRef} action={action} className="space-y-3">
      <div className="flex flex-wrap items-end gap-3">
        <div className="min-w-48 flex-1 space-y-1.5">
          <Label htmlFor={id("name")}>Name</Label>
          <Input
            id={id("name")}
            name="name"
            required
            maxLength={60}
            defaultValue={term?.name ?? ""}
            placeholder={list.key === "session-types" ? "Trial lesson" : undefined}
            aria-invalid={error("name") ? true : undefined}
          />
        </div>
        <div className="w-32 space-y-1.5">
          <Label htmlFor={id("color")}>Colour</Label>
          <Input
            id={id("color")}
            name="color"
            maxLength={9}
            defaultValue={term?.color ?? ""}
            placeholder="#1B5FAA"
            aria-invalid={error("color") ? true : undefined}
          />
        </div>
      </div>
      {error("name") && <p className="text-xs text-bad">{error("name")}</p>}
      {error("color") && <p className="text-xs text-bad">{error("color")}</p>}

      <Label className="flex items-start gap-2 text-sm font-normal">
        <Checkbox name="flag" defaultChecked={term ? term.flag : list.flag.default} className="mt-0.5" />
        <span>
          {list.flag.label}
          <span className="block text-xs text-muted-foreground">{list.flag.help}</span>
        </span>
      </Label>

      {term && (
        <p className="text-xs text-muted-foreground">
          Its code stays <code>{term.code}</code>, so anything importing against it keeps working.
        </p>
      )}

      {!state.ok && state.message && Object.keys(state.errors ?? {}).length === 0 && (
        <p className="text-sm text-bad">{state.message}</p>
      )}

      <div className="flex items-center gap-2">
        <Button type="submit" size="sm" variant={term ? "default" : "outline"} disabled={pending}>
          {pending ? "Saving…" : submit}
        </Button>
        {onCancel && (
          <Button type="button" size="sm" variant="ghost" onClick={onCancel} disabled={pending}>
            Cancel
          </Button>
        )}
      </div>
    </form>
  );
}
