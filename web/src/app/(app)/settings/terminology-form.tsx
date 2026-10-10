"use client";

import { useState, useTransition } from "react";
import { toast } from "sonner";

import { saveTerms } from "@/app/(app)/settings/actions";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { TERM_KEYS } from "@/lib/settings";
import type { TermKey, Terms } from "@/lib/types";

export function TerminologyForm({ terms, defaults }: { terms: Terms; defaults: Terms }) {
  const [edited, setEdited] = useState<Terms>(terms);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [pending, start] = useTransition();

  const set = (key: TermKey, form: "singular" | "plural", value: string) =>
    setEdited((current) => ({ ...current, [key]: { ...current[key], [form]: value } }));

  return (
    <form
      className="space-y-4"
      onSubmit={(event) => {
        event.preventDefault();
        start(async () => {
          const result = await saveTerms(edited);
          setErrors(result.errors ?? {});
          (result.ok ? toast.success : toast.error)(result.message ?? "Saved.");
        });
      }}
    >
      <div className="grid grid-cols-[1fr_1fr_1fr] gap-x-3 gap-y-2 text-sm">
        <div className="text-xs font-medium text-muted-foreground">What it is</div>
        <div className="text-xs font-medium text-muted-foreground">One</div>
        <div className="text-xs font-medium text-muted-foreground">Many</div>
        {TERM_KEYS.map(({ key, meaning }) => (
          <TermRow
            key={key}
            meaning={meaning}
            fallback={defaults[key]}
            value={edited[key]}
            errors={{ singular: errors[`${key}.singular`], plural: errors[`${key}.plural`] }}
            name={key}
            onChange={(form, value) => set(key, form, value)}
          />
        ))}
      </div>
      <div className="flex flex-wrap items-center gap-3">
        <Button type="submit" disabled={pending}>
          {pending ? "Saving…" : "Save wording"}
        </Button>
        <Button type="button" variant="ghost" disabled={pending} onClick={() => setEdited(defaults)}>
          Put back the original words
        </Button>
        <p className="text-xs text-muted-foreground">Used everywhere: on screens, reports and emails.</p>
      </div>
    </form>
  );
}

function TermRow({
  name,
  meaning,
  fallback,
  value,
  errors,
  onChange,
}: {
  name: string;
  meaning: string;
  fallback: { singular: string; plural: string };
  value: { singular: string; plural: string };
  errors: { singular?: string; plural?: string };
  onChange: (form: "singular" | "plural", value: string) => void;
}) {
  return (
    <>
      <div className="self-center">
        <div>{meaning}</div>
        <div className="text-xs text-muted-foreground">Originally “{fallback.singular}”</div>
      </div>
      {(["singular", "plural"] as const).map((form) => (
        <div key={form}>
          <Input
            aria-label={`${meaning}, ${form === "singular" ? "one" : "many"}`}
            name={`${name}.${form}`}
            value={value[form]}
            maxLength={60}
            aria-invalid={errors[form] ? true : undefined}
            onChange={(event) => onChange(form, event.target.value)}
          />
          {errors[form] && <p className="mt-1 text-xs text-bad">{errors[form]}</p>}
        </div>
      ))}
    </>
  );
}
