"use server";

import { revalidatePath } from "next/cache";

import { api } from "@/lib/api";
import { isApiError } from "@/lib/api-error";
import type { ImportPreview } from "@/lib/types";

export type ImportState =
  | { stage: "upload"; error?: string }
  | { stage: "preview"; preview: ImportPreview; error?: string }
  | { stage: "done"; preview: ImportPreview };

/** Read and check a spreadsheet. The API writes nothing at this step. */
export async function previewImport(_: ImportState, formData: FormData): Promise<ImportState> {
  const file = formData.get("file");

  if (!(file instanceof File) || file.size === 0) {
    return { stage: "upload", error: "Choose an .xlsx or .csv file." };
  }

  const form = new FormData();
  form.set("file", file, file.name);

  try {
    const { data } = await api<{ data: ImportPreview }>("imports/learners", { method: "POST", form });

    return { stage: "preview", preview: data };
  } catch (error) {
    if (isApiError(error)) {
      return { stage: "upload", error: error.field("file") ?? error.message };
    }

    throw error;
  }
}

/** Write every ready row, all or nothing. */
export async function commitImport(state: ImportState, formData: FormData): Promise<ImportState> {
  if (state.stage !== "preview") {
    return state;
  }

  try {
    const { data } = await api<{ data: ImportPreview }>(`imports/${state.preview.id}/commit`, {
      method: "POST",
      json: { include_possible_duplicates: formData.get("include_possible_duplicates") === "on" },
    });

    revalidatePath("/learners");

    return { stage: "done", preview: data };
  } catch (error) {
    if (isApiError(error)) {
      return { ...state, error: error.field("import") ?? error.message };
    }

    throw error;
  }
}

export async function discardImport(state: ImportState): Promise<ImportState> {
  if (state.stage === "preview") {
    await api(`imports/${state.preview.id}`, { method: "DELETE" });
  }

  return { stage: "upload" };
}
