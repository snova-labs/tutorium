"use client";

import { useActionState, useEffect, useRef, useState } from "react";
import { toast } from "sonner";

import { createCourse, type SetupResult } from "@/app/(app)/batches/actions";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect } from "@/components/ui/native-select";
import { suggestCode } from "@/lib/setup";

export function NewCourseForm({ brands }: { brands: { id: number; name: string }[] }) {
  const [state, action, pending] = useActionState(createCourse, { ok: false } as SetupResult);
  const [name, setName] = useState("");
  const [code, setCode] = useState("");
  const [codeTouched, setCodeTouched] = useState(false);
  const [period, setPeriod] = useState("monthly");
  const form = useRef<HTMLFormElement>(null);

  useEffect(() => {
    if (state.ok) {
      toast.success(state.message ?? "Added.");
      form.current?.reset();
      // Reset in an effect: this is the moment the server confirmed the save.
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setName("");
      setCode("");
      setCodeTouched(false);
      setPeriod("monthly");
    }
  }, [state]);

  const error = (field: string) => (state.ok ? undefined : state.errors?.[field]);

  return (
    <form ref={form} action={action} className="space-y-3">
      {brands.length > 1 ? (
        <div className="space-y-1.5">
          <Label htmlFor="brand_id">Brand</Label>
          <NativeSelect id="brand_id" name="brand_id" defaultValue={brands[0]?.id}>
            {brands.map((b) => (
              <option key={b.id} value={b.id}>
                {b.name}
              </option>
            ))}
          </NativeSelect>
        </div>
      ) : (
        <input type="hidden" name="brand_id" value={brands[0]?.id ?? ""} />
      )}
      <div className="space-y-1.5">
        <Label htmlFor="course-name">Name</Label>
        <Input
          id="course-name"
          name="name"
          required
          maxLength={160}
          value={name}
          aria-invalid={error("name") ? true : undefined}
          onChange={(e) => {
            setName(e.target.value);
            if (!codeTouched) setCode(suggestCode(e.target.value));
          }}
        />
        {error("name") && <p className="text-xs text-bad">{error("name")}</p>}
      </div>
      <div className="space-y-1.5">
        <Label htmlFor="course-code">Code</Label>
        <Input
          id="course-code"
          name="code"
          required
          maxLength={32}
          className="font-mono"
          value={code}
          aria-invalid={error("code") ? true : undefined}
          onChange={(e) => {
            setCode(e.target.value);
            setCodeTouched(true);
          }}
        />
        {error("code") ? (
          <p className="text-xs text-bad">{error("code")}</p>
        ) : (
          <p className="text-xs text-muted-foreground">Letters, digits and dashes. Shown on reports and exports.</p>
        )}
      </div>
      <div className="space-y-1.5">
        <Label htmlFor="audience">Who it is for</Label>
        <NativeSelect id="audience" name="audience" defaultValue="">
          <option value="">Not specified</option>
          <option value="kids">Children</option>
          <option value="teens">Teenagers</option>
          <option value="adults">Adults</option>
          <option value="corporate">Corporate</option>
        </NativeSelect>
      </div>
      <div className="space-y-1.5">
        <Label htmlFor="period_type">Reports cover</Label>
        <NativeSelect id="period_type" name="period_type" value={period} onChange={(e) => setPeriod(e.target.value)}>
          <option value="monthly">A month</option>
          <option value="quarter">A quarter</option>
          <option value="block">A block of weeks</option>
          <option value="term">A term (dates you set)</option>
        </NativeSelect>
        {period === "block" && (
          <div className="flex items-center gap-2 pt-1 text-sm">
            <Input name="period_block_weeks" type="number" min={1} max={52} defaultValue={4} className="w-20" aria-label="Weeks per block" />
            weeks each
          </div>
        )}
      </div>
      <div className="space-y-1.5">
        <Label htmlFor="description">Description (optional)</Label>
        <Input id="description" name="description" maxLength={2000} />
      </div>
      {!state.ok && state.message && !Object.keys(state.errors ?? {}).some((f) => ["name", "code"].includes(f)) && (
        <p className="text-sm text-bad">{state.message}</p>
      )}
      <Button type="submit" disabled={pending}>
        {pending ? "Adding…" : "Add"}
      </Button>
    </form>
  );
}
