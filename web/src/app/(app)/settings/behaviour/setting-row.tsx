"use client";

import { useState, useTransition } from "react";
import { toast } from "sonner";

import { revertSetting, saveSetting, type SettingResult } from "@/app/(app)/settings/behaviour/actions";
import { ProvenanceChip } from "@/components/app/provenance-chip";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect } from "@/components/ui/native-select";
import { canRevert, origin, revertsTo, settingValue } from "@/lib/academy-settings";
import type { AcademySetting } from "@/lib/types";

export function SettingRow({ setting, roles }: { setting: AcademySetting; roles: string[] }) {
  const [pending, start] = useTransition();
  // `live` is the row's own copy, replaced by whatever the server says afterwards. Without that,
  // a revert left the old value on screen while the stored one had gone back.
  const [live, setLive] = useState(setting);
  const [revision, setRevision] = useState(0);
  const draft = live.value;

  const act = (run: () => Promise<SettingResult>) =>
    start(async () => {
      const result = await run();

      if (result.ok) {
        toast.success(result.message ?? "Saved.");

        if (result.setting) {
          setLive(result.setting);
          // Re-key the uncontrolled inputs so a number or a choice shows the server's value.
          setRevision((count) => count + 1);
        }
      } else {
        toast.error(result.message ?? "That did not work.");
      }
    });

  const save = (value: boolean | number | string | string[]) => {
    setLive({ ...live, value });
    act(() => saveSetting(live.key, value));
  };

  const id = `setting-${live.key}`;

  return (
    <div className="space-y-2 p-3">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-56 flex-1">
          <Label htmlFor={id} className="text-sm font-medium">
            {live.label}
          </Label>
          <p className="text-xs text-muted-foreground">{live.help}</p>
        </div>

        <div className="flex items-center gap-3">
          {/* The chip is the point of this screen: the value, and whether somebody here chose it. */}
          <ProvenanceChip
            value={settingValue(live)}
            inherited={!live.set_here}
            inheritedFrom={live.origin === "preset" ? "your preset" : undefined}
            setOn="your academy"
            mono={live.type !== "switch"}
          />
          {live.type === "switch" && (
            <Checkbox
              id={id}
              checked={draft === true}
              disabled={pending}
              onCheckedChange={(ticked) => save(ticked === true)}
            />
          )}
          {live.type === "number" && (
            <Input
              key={revision}
              id={id}
              type="number"
              className="w-24"
              min={live.min ?? undefined}
              max={live.max ?? undefined}
              defaultValue={Number(live.value)}
              disabled={pending}
              // On blur rather than on change: a number is typed a digit at a time, and each
              // digit is not a decision.
              onBlur={(event) => {
                const next = Number(event.currentTarget.value);

                if (!Number.isNaN(next) && next !== Number(draft)) {
                  save(next);
                }
              }}
            />
          )}
          {live.type === "choice" && (
            <NativeSelect
              key={revision}
              id={id}
              className="w-56"
              defaultValue={String(live.value ?? "")}
              disabled={pending}
              onChange={(event) => save(event.currentTarget.value)}
            >
              {Object.entries(live.choices ?? {}).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </NativeSelect>
          )}
        </div>
      </div>

      {live.type === "roles" && (
        <ul className="flex flex-wrap gap-3">
          {roles.map((role) => {
            const chosen = Array.isArray(draft) && draft.includes(role);

            return (
              <li key={role}>
                <Label className="flex items-center gap-2 text-sm font-normal">
                  <Checkbox
                    value={role}
                    checked={chosen}
                    disabled={pending}
                    onCheckedChange={(ticked) => {
                      const current = Array.isArray(draft) ? draft : [];

                      save(ticked === true ? [...current, role] : current.filter((held) => held !== role));
                    }}
                  />
                  {role}
                </Label>
              </li>
            );
          })}
        </ul>
      )}

      <p className="text-xs text-muted-foreground">
        {origin(live)}
        {canRevert(live) && (
          <>
            {" · "}
            <Button
              variant="link"
              className="h-auto p-0 text-xs"
              disabled={pending}
              onClick={() => act(() => revertSetting(live.key))}
            >
              Back to {revertsTo(live)}
            </Button>
          </>
        )}
      </p>
    </div>
  );
}
