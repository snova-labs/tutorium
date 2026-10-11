"use client";

import { useActionState, useEffect } from "react";
import { toast } from "sonner";

import { createLocation, type OrgResult, saveLocation } from "@/app/(app)/organisation/actions";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect } from "@/components/ui/native-select";
import { dayLabel, timezoneChoices, WEEK_DAYS } from "@/lib/organisation";
import { suggestCode } from "@/lib/setup";
import type { Branch } from "@/lib/types";

export function LocationForm({
  brands,
  branch,
  browserTimezone,
}: {
  brands: { id: number; name: string }[];
  branch?: Branch;
  browserTimezone: string;
}) {
  const [state, action, pending] = useActionState(
    branch ? saveLocation.bind(null, branch.id) : createLocation,
    { ok: false } as OrgResult,
  );
  const error = (field: string) => (state.ok ? undefined : state.errors?.[field]);
  const weekend = branch?.locale_rules.weekend_days ?? ["saturday", "sunday"];
  const timezone = branch?.locale_rules.timezone ?? browserTimezone;

  useEffect(() => {
    if (state.ok && state.message) {
      toast.success(state.message);
    }
  }, [state]);

  return (
    <form action={action} className="space-y-5">
      {!branch &&
        (brands.length > 1 ? (
          <div className="space-y-1.5">
            <Label htmlFor="brand_id">Brand</Label>
            <NativeSelect id="brand_id" name="brand_id" defaultValue={brands[0]?.id}>
              {brands.map((brand) => (
                <option key={brand.id} value={brand.id}>
                  {brand.name}
                </option>
              ))}
            </NativeSelect>
          </div>
        ) : (
          <input type="hidden" name="brand_id" value={brands[0]?.id ?? ""} />
        ))}

      <div className="grid gap-4 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor="location-name">Name</Label>
          <Input
            id="location-name"
            name="name"
            required
            maxLength={120}
            defaultValue={branch?.name ?? ""}
            aria-invalid={error("name") ? true : undefined}
            onChange={(event) => {
              if (branch) return;

              const code = event.currentTarget.form?.elements.namedItem("code");

              if (code instanceof HTMLInputElement && code.dataset.touched !== "yes") {
                code.value = suggestCode(event.currentTarget.value);
              }
            }}
          />
          {error("name") ? (
            <p className="text-xs text-bad">{error("name")}</p>
          ) : (
            <p className="text-xs text-muted-foreground">What your staff call it: “Lakeside”, “Head office”, “Online”.</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="location-code">Short code</Label>
          <Input
            id="location-code"
            name="code"
            required
            maxLength={32}
            className="font-mono"
            defaultValue={branch?.code ?? ""}
            onChange={(event) => {
              event.currentTarget.dataset.touched = "yes";
            }}
            aria-invalid={error("code") ? true : undefined}
          />
          {error("code") && <p className="text-xs text-bad">{error("code")}</p>}
        </div>
      </div>

      <div className="space-y-1.5">
        <Label htmlFor="timezone">Timezone</Label>
        <NativeSelect id="timezone" name="timezone" defaultValue={timezone} aria-invalid={error("timezone") ? true : undefined}>
          {timezoneChoices(timezone).map((zone) => (
            <option key={zone} value={zone}>
              {zone}
            </option>
          ))}
        </NativeSelect>
        {error("timezone") ? (
          <p className="text-xs text-bad">{error("timezone")}</p>
        ) : (
          <p className="text-xs text-muted-foreground">
            Every class time here is in this zone. Changing it later does not move classes already scheduled.
          </p>
        )}
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor="week_start">The week starts on</Label>
          <NativeSelect id="week_start" name="week_start" defaultValue={branch?.locale_rules.week_start ?? "monday"}>
            {WEEK_DAYS.map((day) => (
              <option key={day} value={day}>
                {dayLabel(day)}
              </option>
            ))}
          </NativeSelect>
        </div>
        <fieldset className="space-y-2">
          <legend className="text-sm font-medium">Weekend</legend>
          <div className="flex flex-wrap gap-x-4 gap-y-2">
            {WEEK_DAYS.map((day) => (
              <Label key={day} className="flex items-center gap-2 text-sm font-normal">
                <Checkbox name="weekend_days" value={day} defaultChecked={weekend.includes(day)} />
                {dayLabel(day).slice(0, 3)}
              </Label>
            ))}
          </div>
          {error("weekend_days") ? (
            <p className="text-xs text-bad">{error("weekend_days")}</p>
          ) : (
            <p className="text-xs text-muted-foreground">Timetables skip these days. At most three.</p>
          )}
        </fieldset>
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor="location-phone">Phone (optional)</Label>
          <Input id="location-phone" name="phone" maxLength={32} defaultValue={branch?.contact?.phone ?? ""} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="location-email">Email (optional)</Label>
          <Input
            id="location-email"
            name="email"
            type="email"
            maxLength={190}
            defaultValue={branch?.contact?.email ?? ""}
            aria-invalid={error("email") ? true : undefined}
          />
          {error("email") && <p className="text-xs text-bad">{error("email")}</p>}
        </div>
      </div>

      {/* Only when it is not already said beside a field, so a refusal is never printed twice. */}
      {!state.ok && state.message && Object.keys(state.errors ?? {}).length === 0 && (
        <p className="text-sm text-bad">{state.message}</p>
      )}

      <Button type="submit" disabled={pending}>
        {pending ? "Saving…" : branch ? "Save" : "Add location"}
      </Button>
    </form>
  );
}
