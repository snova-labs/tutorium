"use client";

import { useActionState, useEffect, useState } from "react";
import { toast } from "sonner";

import { createTemplate, saveTemplate, type TemplateResult } from "@/app/(app)/settings/reports/actions";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect } from "@/components/ui/native-select";
import type { CourseSummary, ReportTemplate } from "@/lib/types";

export function TemplateForm({
  template,
  blocks,
  brands,
  courses,
  courseNoun,
}: {
  /** Absent when adding one. */
  template?: ReportTemplate;
  blocks: Record<string, string>;
  brands: { id: number; name: string }[];
  courses: CourseSummary[];
  courseNoun: string;
}) {
  const [state, action, pending] = useActionState(
    template ? saveTemplate.bind(null, template.id) : createTemplate,
    { ok: false } as TemplateResult,
  );
  const [chosen, setChosen] = useState<string[]>(template?.blocks ?? Object.keys(blocks));
  const id = (field: string) => `${template?.id ?? "new"}-${field}`;

  useEffect(() => {
    if (state.ok && state.message) {
      toast.success(state.message);
    }
  }, [state]);

  const error = (field: string) => (state.ok ? undefined : state.errors?.[field]);

  return (
    <form action={action} className="space-y-4">
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor={id("name")}>Name</Label>
          <Input
            id={id("name")}
            name="name"
            required
            maxLength={120}
            defaultValue={template?.name ?? ""}
            aria-invalid={error("name") ? true : undefined}
          />
          {error("name") ? (
            <p className="text-xs text-bad">{error("name")}</p>
          ) : (
            <p className="text-xs text-muted-foreground">For your own list; guardians never see it.</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor={id("closing")}>Sign-off</Label>
          <Input
            id={id("closing")}
            name="closing"
            maxLength={190}
            placeholder="Warm regards,"
            defaultValue={template?.closing ?? ""}
          />
          <p className="text-xs text-muted-foreground">The last line before your name.</p>
        </div>
      </div>

      <fieldset className="space-y-2">
        <legend className="text-sm font-medium">Sections</legend>
        <ul className="space-y-2">
          {Object.entries(blocks).map(([block, meaning]) => (
            <li key={block}>
              <Label className="flex items-start gap-2 text-sm font-normal">
                <Checkbox
                  name="blocks"
                  value={block}
                  checked={chosen.includes(block)}
                  onCheckedChange={(ticked) =>
                    setChosen(ticked === true ? [...chosen, block] : chosen.filter((c) => c !== block))
                  }
                  className="mt-0.5"
                />
                <span>
                  {block.charAt(0).toUpperCase() + block.slice(1)}
                  <span className="block text-xs text-muted-foreground">{meaning}</span>
                </span>
              </Label>
            </li>
          ))}
        </ul>
        {error("blocks") ? (
          <p className="text-xs text-bad">{error("blocks")}</p>
        ) : (
          <p className="text-xs text-muted-foreground">
            They print in this order. A section with nothing in it is left out of the page anyway.
          </p>
        )}
      </fieldset>

      <div className="grid gap-4 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor={id("brand_id")}>For which brand</Label>
          <NativeSelect id={id("brand_id")} name="brand_id" defaultValue={template?.applies_to.brand_id ?? ""}>
            <option value="">Any</option>
            {brands.map((brand) => (
              <option key={brand.id} value={brand.id}>
                {brand.name}
              </option>
            ))}
          </NativeSelect>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor={id("course_id")}>For which {courseNoun.toLowerCase()}</Label>
          <NativeSelect id={id("course_id")} name="course_id" defaultValue={template?.applies_to.course_id ?? ""}>
            <option value="">Any</option>
            {courses.map((course) => (
              <option key={course.id} value={course.id}>
                {course.name}
              </option>
            ))}
          </NativeSelect>
          <p className="text-xs text-muted-foreground">
            The narrowest match wins: a {courseNoun.toLowerCase()}&apos;s template beats a brand&apos;s, which beats the default.
          </p>
        </div>
      </div>

      <Label className="flex items-start gap-2 text-sm font-normal">
        <Checkbox name="is_default" defaultChecked={template?.is_default} className="mt-0.5" />
        <span>
          Use this when nothing narrower matches
          <span className="block text-xs text-muted-foreground">
            One template is always the default. Taking it off this one leaves it where it is until another takes over.
          </span>
        </span>
      </Label>

      {!state.ok && state.message && Object.keys(state.errors ?? {}).length === 0 && (
        <p className="text-sm text-bad">{state.message}</p>
      )}

      <Button type="submit" disabled={pending}>
        {pending ? "Saving…" : template ? "Save" : "Add template"}
      </Button>
    </form>
  );
}
