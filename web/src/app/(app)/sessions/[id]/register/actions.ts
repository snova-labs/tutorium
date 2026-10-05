"use server";

import { revalidatePath } from "next/cache";

import { api } from "@/lib/api";
import { isApiError } from "@/lib/api-error";

export interface RegisterResult {
  ok: boolean;
  message: string;
}

function failure(error: unknown): RegisterResult {
  if (!isApiError(error)) {
    throw error;
  }

  return { ok: false, message: error.allMessages()[0] ?? error.message };
}

/** Save the whole register in one request: one transaction, so nobody wonders which half saved. */
export async function saveRegister(
  sessionId: number,
  marks: { enrollment_id: number; status_id: number; minutes_late: number | null }[],
): Promise<RegisterResult> {
  if (marks.length === 0) {
    return { ok: false, message: "Mark at least one learner first." };
  }

  try {
    const { data } = await api<{ data: { message: string } }>(`sessions/${sessionId}/attendance`, {
      method: "PUT",
      json: { marks },
    });

    revalidatePath(`/sessions/${sessionId}/register`);

    return { ok: true, message: data.message };
  } catch (error) {
    return failure(error);
  }
}

/** Mark everyone not yet marked, leaving every existing mark alone. */
export async function markRemaining(sessionId: number, statusId: number): Promise<RegisterResult> {
  try {
    await api(`sessions/${sessionId}/attendance/remaining`, { method: "POST", json: { status_id: statusId } });
    revalidatePath(`/sessions/${sessionId}/register`);

    return { ok: true, message: "Everyone not yet marked has been marked." };
  } catch (error) {
    return failure(error);
  }
}
