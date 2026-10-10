"use client";

import { useState, useTransition } from "react";

import { createBatch, type SetupResult } from "@/app/(app)/batches/actions";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect } from "@/components/ui/native-select";
import { suggestCode } from "@/lib/setup";

type Option = { id: number; label: string };

/** Submitted by hand rather than as a form action, so the selects keep their choice after an error. */
export function NewBatchForm({ courses, branches, today, courseNoun }: { courses: Option[]; branches: Option[]; today: string; courseNoun: string }) {
  const [values, setValues] = useState({
    course_id: String(courses[0]?.id ?? ""),
    branch_id: String(branches[0]?.id ?? ""),
    name: "",
    code: "",
    starts_on: today,
    ends_on: "",
    capacity: "",
    delivery_mode: "in_person",
  });
  const [codeTouched, setCodeTouched] = useState(false);
  const [result, setResult] = useState<SetupResult>({ ok: false });
  const [pending, start] = useTransition();
  const set = (field: keyof typeof values) => (e: { target: { value: string } }) => setValues((v) => ({ ...v, [field]: e.target.value }));
  const error = (field: string) => result.errors?.[field];

  return (
    <form
      className="grid gap-4 sm:grid-cols-2"
      onSubmit={(event) => {
        event.preventDefault();
        const data = new FormData();
        Object.entries(values).forEach(([k, v]) => data.set(k, v));
        // On success the action redirects to the new page; otherwise the errors come back.
        start(async () => setResult(await createBatch({ ok: false }, data)));
      }}
    >
      <Field label={courseNoun} id="course_id" error={error("course_id")}>
        <NativeSelect id="course_id" value={values.course_id} onChange={set("course_id")}>
          {courses.map((c) => (
            <option key={c.id} value={c.id}>
              {c.label}
            </option>
          ))}
        </NativeSelect>
      </Field>
      <Field label="Location" id="branch_id" error={error("branch_id")} hint="Its timezone becomes the class's clock.">
        <NativeSelect id="branch_id" value={values.branch_id} onChange={set("branch_id")}>
          {branches.map((b) => (
            <option key={b.id} value={b.id}>
              {b.label}
            </option>
          ))}
        </NativeSelect>
      </Field>
      <Field label="Name" id="name" error={error("name")}>
        <Input
          id="name"
          required
          maxLength={160}
          value={values.name}
          onChange={(e) => {
            setValues((v) => ({ ...v, name: e.target.value, code: codeTouched ? v.code : suggestCode(e.target.value) }));
          }}
        />
      </Field>
      <Field label="Code" id="code" error={error("code")}>
        <Input
          id="code"
          required
          maxLength={32}
          className="font-mono"
          value={values.code}
          onChange={(e) => {
            setCodeTouched(true);
            set("code")(e);
          }}
        />
      </Field>
      <Field label="Starts" id="starts_on" error={error("starts_on")}>
        <Input id="starts_on" type="date" required value={values.starts_on} onChange={set("starts_on")} />
      </Field>
      <Field label="Ends (optional)" id="ends_on" error={error("ends_on")}>
        <Input id="ends_on" type="date" value={values.ends_on} onChange={set("ends_on")} />
      </Field>
      <Field label="Places (optional)" id="capacity" error={error("capacity")}>
        <Input id="capacity" type="number" min={1} max={500} value={values.capacity} onChange={set("capacity")} />
      </Field>
      <Field label="Taught" id="delivery_mode" error={error("delivery_mode")}>
        <NativeSelect id="delivery_mode" value={values.delivery_mode} onChange={set("delivery_mode")}>
          <option value="in_person">In person</option>
          <option value="online">Online</option>
          <option value="hybrid">Hybrid</option>
        </NativeSelect>
      </Field>
      {!result.ok && result.message && <p className="text-sm text-bad sm:col-span-2">{result.message}</p>}
      <div className="sm:col-span-2">
        <Button type="submit" disabled={pending}>
          {pending ? "Creating…" : "Create"}
        </Button>
      </div>
    </form>
  );
}

function Field({ label, id, error, hint, children }: { label: string; id: string; error?: string; hint?: string; children: React.ReactNode }) {
  return (
    <div className="space-y-1.5">
      <Label htmlFor={id}>{label}</Label>
      {children}
      {error ? <p className="text-xs text-bad">{error}</p> : hint && <p className="text-xs text-muted-foreground">{hint}</p>}
    </div>
  );
}
