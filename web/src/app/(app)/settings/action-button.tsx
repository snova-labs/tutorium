"use client";

import { useTransition, type ComponentProps } from "react";
import { toast } from "sonner";

import type { SettingsResult } from "@/app/(app)/settings/actions";
import { Button } from "@/components/ui/button";

/** A button that runs one settings action and says how it went. */
export function ActionButton({
  run,
  confirm,
  children,
  pendingLabel,
  ...props
}: Omit<ComponentProps<typeof Button>, "onClick"> & {
  run: () => Promise<SettingsResult>;
  /** Asked first, for anything that changes or removes data. */
  confirm?: string;
  pendingLabel?: string;
}) {
  const [pending, start] = useTransition();

  return (
    <Button
      {...props}
      disabled={pending || props.disabled}
      onClick={() => {
        if (confirm && !window.confirm(confirm)) {
          return;
        }

        start(async () => {
          const result = await run();
          (result.ok ? toast.success : toast.error)(result.message ?? "Done.");
        });
      }}
    >
      {pending ? (pendingLabel ?? "Working…") : children}
    </Button>
  );
}
