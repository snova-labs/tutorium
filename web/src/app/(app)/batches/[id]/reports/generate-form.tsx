"use client";

import Link from "next/link";
import { useActionState, useEffect, useState } from "react";

import { generateReports, runStatus, type GenerateState } from "@/app/(app)/batches/[id]/reports/actions";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import { runFinished, runLabel, runReasons } from "@/lib/reports";
import type { ReportRun } from "@/lib/types";

const initial: GenerateState = {};

export function GenerateForm({
  batchId,
  period,
  total,
  withGaps,
  canSend,
}: {
  batchId: number;
  period: string;
  total: number;
  withGaps: number;
  canSend: boolean;
}) {
  const [state, action, pending] = useActionState(generateReports.bind(null, batchId, period), initial);

  if (state.runId) {
    return <RunProgress runId={state.runId} batchId={batchId} period={period} />;
  }

  return (
    <form action={action} className="space-y-4">
      {withGaps > 0 && (
        <div className="flex items-start gap-2">
          <Checkbox id="require_complete" name="require_complete" />
          <Label htmlFor="require_complete" className="leading-snug font-normal">
            Skip the {withGaps} {withGaps === 1 ? "learner" : "learners"} with gaps. Their reports can be generated once
            the gaps are closed.
          </Label>
        </div>
      )}

      {canSend && (
        <div className="flex items-start gap-2">
          <Checkbox id="send" name="send" />
          <Label htmlFor="send" className="leading-snug font-normal">
            Email each report to its recipients as soon as it is ready. Leave this off to check them in the archive first.
          </Label>
        </div>
      )}

      {state.error && (
        <Alert variant="destructive">
          <AlertDescription>{state.error}</AlertDescription>
        </Alert>
      )}

      <Button type="submit" disabled={pending || total === 0}>
        {pending ? "Starting…" : `Generate ${total} ${total === 1 ? "report" : "reports"}`}
      </Button>
    </form>
  );
}

function RunProgress({ runId, batchId, period }: { runId: number; batchId: number; period: string }) {
  const [run, setRun] = useState<ReportRun | null>(null);

  useEffect(() => {
    let stopped = false;

    const poll = async () => {
      const latest = await runStatus(runId);

      if (stopped) {
        return;
      }

      setRun(latest);

      if (!runFinished(latest.status)) {
        setTimeout(poll, 2000);
      }
    };

    void poll();

    return () => {
      stopped = true;
    };
  }, [runId]);

  const percent = run ? Math.round(run.progress) : 0;

  return (
    <div className="space-y-3" aria-live="polite">
      <div className="flex items-baseline justify-between text-sm">
        <span className="font-medium">{run ? runLabel(run.status) : "Starting"}</span>
        {run && (
          <span className="text-muted-foreground tabular-nums">
            {run.succeeded + run.failed} of {run.total}
          </span>
        )}
      </div>
      <div className="h-2 overflow-hidden rounded-full bg-muted" role="progressbar" aria-valuenow={percent} aria-valuemin={0} aria-valuemax={100}>
        <div className="h-full bg-primary transition-[width]" style={{ width: `${percent}%` }} />
      </div>
      {run && run.failed > 0 && (
        <p className="text-sm text-bad">
          {run.failed} {run.failed === 1 ? "report" : "reports"} could not be generated. The rest are unaffected.
        </p>
      )}
      {run && runFinished(run.status) && run.failures.length > 0 && (
        <ul className="space-y-1 text-sm text-muted-foreground">
          {runReasons(run.failures).map(({ reason, count }) => (
            <li key={reason}>
              {count > 1 && <span className="font-medium text-foreground">{count} × </span>}
              {reason}
            </li>
          ))}
        </ul>
      )}
      <p className="text-sm text-muted-foreground">You can leave this page; generation carries on.</p>
      {run && runFinished(run.status) && (
        <Button asChild variant="outline" size="sm">
          <Link href={{ pathname: "/reports", query: { batch_id: batchId, period } }}>Open these reports</Link>
        </Button>
      )}
    </div>
  );
}
