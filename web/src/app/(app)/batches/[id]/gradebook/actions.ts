"use server";

import { revalidatePath } from "next/cache";

import { api } from "@/lib/api";
import { isApiError } from "@/lib/api-error";

/** Save every changed cell in one request: the API writes them in a single transaction. */
export async function saveGrades(
  batchId: number,
  cells: Record<string, unknown>[],
): Promise<{ ok: boolean; message: string }> {
  if (cells.length === 0) {
    return { ok: false, message: "Nothing has changed yet." };
  }

  try {
    const { data } = await api<{ data: { message: string } }>(`batches/${batchId}/gradebook`, {
      method: "PUT",
      json: { cells },
    });

    revalidatePath(`/batches/${batchId}/gradebook`);

    return { ok: true, message: data.message };
  } catch (error) {
    if (!isApiError(error)) {
      throw error;
    }

    // The scheme's own message, which names the criterion or the scale.
    return { ok: false, message: error.allMessages()[0] ?? error.message };
  }
}
