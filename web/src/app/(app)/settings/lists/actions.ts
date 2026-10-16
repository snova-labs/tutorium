"use server";

import { revalidatePath } from "next/cache";

import { api } from "@/lib/api";
import { isApiError } from "@/lib/api-error";
import { draftErrors } from "@/lib/vocabularies";

export interface TermResult {
  ok: boolean;
  message?: string;
  errors?: Record<string, string>;
}

async function attempt(run: () => Promise<string>): Promise<TermResult> {
  try {
    const message = await run();
    revalidatePath("/settings/lists");

    return { ok: true, message };
  } catch (error) {
    if (!isApiError(error)) {
      throw error;
    }

    const errors = Object.fromEntries(
      Object.entries(error.errors).map(([field, messages]) => [field, messages[0]]),
    );

    return { ok: false, message: error.message, errors };
  }
}

function payload(formData: FormData) {
  const text = (name: string) => String(formData.get(name) ?? "").trim();

  return {
    name: text("name"),
    color: text("color") || null,
    flag: formData.get("flag") !== null,
  };
}

export async function addTerm(list: string, _: TermResult, formData: FormData): Promise<TermResult> {
  const json = payload(formData);
  const errors = draftErrors({ name: json.name, color: json.color });

  if (Object.keys(errors).length > 0) {
    return { ok: false, message: Object.values(errors)[0], errors };
  }

  return attempt(async () => {
    const added = await api<{ meta: { message: string } }>(`vocabularies/${list}`, { method: "POST", json });

    return added.meta.message;
  });
}

export async function saveTerm(
  list: string,
  id: number,
  _: TermResult,
  formData: FormData,
): Promise<TermResult> {
  const json = payload(formData);
  const errors = draftErrors({ name: json.name, color: json.color });

  if (Object.keys(errors).length > 0) {
    return { ok: false, message: Object.values(errors)[0], errors };
  }

  return attempt(async () => {
    const saved = await api<{ meta: { message: string } }>(`vocabularies/${list}/${id}`, {
      method: "PATCH",
      json,
    });

    return saved.meta.message;
  });
}

/** Switching one off: the way to retire a term that registers and notes already point at. */
export async function setTermActive(list: string, id: number, active: boolean): Promise<TermResult> {
  return attempt(async () => {
    const saved = await api<{ meta: { message: string } }>(`vocabularies/${list}/${id}`, {
      method: "PATCH",
      json: { is_active: active },
    });

    return active ? saved.meta.message : "Switched off.";
  });
}

export async function removeTerm(list: string, id: number): Promise<TermResult> {
  return attempt(async () => {
    const removed = await api<{ meta: { message: string } }>(`vocabularies/${list}/${id}`, { method: "DELETE" });

    return removed.meta.message;
  });
}
