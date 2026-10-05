"use server";

import { revalidatePath } from "next/cache";

import { api } from "@/lib/api";
import { isApiError } from "@/lib/api-error";

type Result = { ok: boolean; message: string };

async function attempt(run: () => Promise<string>): Promise<Result> {
  try {
    const message = await run();
    revalidatePath("/reports");

    return { ok: true, message };
  } catch (error) {
    if (!isApiError(error)) {
      throw error;
    }

    return { ok: false, message: error.allMessages()[0] ?? error.message };
  }
}

/** Email a report to its recipients. */
export async function sendReport(reportId: number): Promise<Result> {
  return attempt(async () => {
    const { data } = await api<{ data: { queued: number } }>(`reports/${reportId}/send`, { method: "POST" });

    return data.queued === 0
      ? "Nobody is set to receive this report."
      : `Sending to ${data.queued} ${data.queued === 1 ? "recipient" : "recipients"}.`;
  });
}

/** Try a failed or bounced delivery again. */
export async function retryDelivery(deliveryId: number): Promise<Result> {
  return attempt(async () => {
    const { data } = await api<{ data: { message: string } }>(`report-deliveries/${deliveryId}/retry`, { method: "POST" });

    return data.message;
  });
}
