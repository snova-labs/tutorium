"use server";

import { api } from "@/lib/api";
import { isApiError } from "@/lib/api-error";
import type { ReportRun } from "@/lib/types";

export interface GenerateState {
  runId?: number;
  message?: string;
  error?: string;
}

/** Queue a report for every active learner in the batch. The API answers at once; the work runs on the queue. */
export async function generateReports(batchId: number, period: string, _previous: GenerateState, formData: FormData): Promise<GenerateState> {
  try {
    const { data } = await api<{ data: { run_id: number; message: string } }>(`batches/${batchId}/reports`, {
      method: "POST",
      json: {
        period,
        send: formData.get("send") === "on",
        require_complete: formData.get("require_complete") === "on",
      },
    });

    return { runId: data.run_id, message: data.message };
  } catch (error) {
    if (!isApiError(error)) {
      throw error;
    }

    return { error: error.allMessages()[0] ?? error.message };
  }
}

/** How far a run has got, for the progress bar. */
export async function runStatus(runId: number): Promise<ReportRun> {
  return (await api<{ data: ReportRun }>(`report-runs/${runId}`)).data;
}
