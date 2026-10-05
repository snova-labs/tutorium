"use server";

import { api } from "@/lib/api";
import { isApiError } from "@/lib/api-error";
import { notesToSave, type NoteDraft } from "@/lib/reports";

/** Save a whole class's notes in one request. Empty boxes are left out. */
export async function saveNotes(
  batchId: number,
  period: string,
  categoryId: number,
  drafts: NoteDraft[],
): Promise<{ ok: boolean; message: string }> {
  const notes = notesToSave(drafts, categoryId);

  if (notes.length === 0) {
    return { ok: false, message: "Write at least one note first." };
  }

  try {
    const { data } = await api<{ data: { message: string } }>(`batches/${batchId}/notes`, {
      method: "POST",
      json: { period, notes },
    });

    return { ok: true, message: data.message };
  } catch (error) {
    if (!isApiError(error)) {
      throw error;
    }

    return { ok: false, message: error.allMessages()[0] ?? error.message };
  }
}
