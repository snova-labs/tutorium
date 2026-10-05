"use client";

import { useTransition } from "react";
import { toast } from "sonner";

import { retryDelivery, sendReport } from "@/app/(app)/reports/actions";
import { Button } from "@/components/ui/button";
import type { ReportDelivery } from "@/lib/types";

export function ReportActions({
  reportId,
  sent,
  failed,
  canSend,
}: {
  reportId: number;
  sent: boolean;
  failed: ReportDelivery[];
  canSend: boolean;
}) {
  const [pending, start] = useTransition();

  const run = (action: () => Promise<{ ok: boolean; message: string }>) =>
    start(async () => {
      const result = await action();
      (result.ok ? toast.success : toast.error)(result.message);
    });

  return (
    <div className="flex flex-wrap justify-end gap-2">
      <Button asChild variant="outline" size="sm">
        <a href={`/reports/${reportId}/download`}>Download</a>
      </Button>
      {canSend && !sent && (
        <Button size="sm" disabled={pending} onClick={() => run(() => sendReport(reportId))}>
          Send
        </Button>
      )}
      {canSend &&
        failed.map((delivery) => (
          <Button
            key={delivery.id}
            size="sm"
            variant="outline"
            disabled={pending}
            title={delivery.error ?? undefined}
            onClick={() => run(() => retryDelivery(delivery.id))}
          >
            Retry {delivery.to}
          </Button>
        ))}
    </div>
  );
}
