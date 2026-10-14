"use server";

import { revalidatePath } from "next/cache";

import { api } from "@/lib/api";
import { isApiError } from "@/lib/api-error";
import { draftErrors } from "@/lib/report-templates";

export interface TemplateResult {
  ok: boolean;
  message?: string;
  errors?: Record<string, string>;
}

async function attempt(run: () => Promise<string>): Promise<TemplateResult> {
  try {
    const message = await run();
    revalidatePath("/settings/reports");

    return { ok: true, message };
  } catch (error) {
    if (!isApiError(error)) {
      throw error;
    }

    const errors = Object.fromEntries(
      // "blocks.0" is the same box on the form as "blocks": one list of sections.
      Object.entries(error.errors).map(([field, messages]) => [field.replace(/^blocks\..*/, "blocks"), messages[0]]),
    );

    return { ok: false, message: error.message, errors };
  }
}

/** What the form sends for both adding and changing: the fields are the same. */
function payload(formData: FormData) {
  const text = (name: string) => String(formData.get(name) ?? "").trim();

  return {
    name: text("name"),
    blocks: formData.getAll("blocks").map(String),
    closing: text("closing") || null,
    brand_id: text("brand_id") === "" ? null : Number(text("brand_id")),
    course_id: text("course_id") === "" ? null : Number(text("course_id")),
    is_default: formData.get("is_default") !== null,
  };
}

export async function createTemplate(_: TemplateResult, formData: FormData): Promise<TemplateResult> {
  const json = payload(formData);
  const errors = draftErrors({ name: json.name, blocks: json.blocks });

  if (Object.keys(errors).length > 0) {
    return { ok: false, message: Object.values(errors)[0], errors };
  }

  return attempt(async () => {
    await api("report-templates", { method: "POST", json });

    return "Added.";
  });
}

export async function saveTemplate(id: number, _: TemplateResult, formData: FormData): Promise<TemplateResult> {
  const json = payload(formData);
  const errors = draftErrors({ name: json.name, blocks: json.blocks });

  if (Object.keys(errors).length > 0) {
    return { ok: false, message: Object.values(errors)[0], errors };
  }

  return attempt(async () => {
    await api(`report-templates/${id}`, { method: "PATCH", json });

    // Reports already sent keep the sections they were sent with; this is for the next ones.
    return "Saved. Reports from now on use this.";
  });
}
