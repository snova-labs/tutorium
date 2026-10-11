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

export interface AssessmentResult {
  ok: boolean;
  message?: string;
  errors?: Record<string, string>;
}

/**
 * Add an assessment to this class, published so it appears in the grade book at once. An
 * unpublished one would be invisible here, which is a confusing place to leave something
 * somebody just created.
 */
export async function createAssessment(
  batchId: number,
  _: AssessmentResult,
  formData: FormData,
): Promise<AssessmentResult> {
  const text = (name: string) => String(formData.get(name) ?? "").trim();
  const number = (value: string) => (value === "" ? undefined : Number(value));

  const criteria = formData
    .getAll("criterion_name")
    .map(String)
    .map((name, index) => ({
      name: name.trim(),
      max_points: Number(formData.getAll("criterion_points").map(String)[index] ?? 0),
      descriptor: String(formData.getAll("criterion_descriptor").map(String)[index] ?? "").trim() || undefined,
    }))
    .filter((criterion) => criterion.name !== "");

  try {
    await api(`batches/${batchId}/assessments`, {
      method: "POST",
      json: {
        assessment_type_id: Number(text("assessment_type_id")),
        grading_scheme_id: Number(text("grading_scheme_id")),
        title: text("title"),
        description: text("description") || undefined,
        due_local_date: text("due_local_date") || undefined,
        max_points: number(text("max_points")),
        criteria: criteria.length > 0 ? criteria : undefined,
        publish: true,
      },
    });

    revalidatePath(`/batches/${batchId}/gradebook`);

    return { ok: true, message: "Added. It is in the grade book now." };
  } catch (error) {
    if (!isApiError(error)) {
      throw error;
    }

    const errors = Object.fromEntries(Object.entries(error.errors).map(([field, messages]) => [field, messages[0]]));

    return { ok: false, message: error.message, errors };
  }
}
