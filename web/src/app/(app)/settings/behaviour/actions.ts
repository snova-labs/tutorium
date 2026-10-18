"use server";

import { revalidatePath } from "next/cache";

import { api } from "@/lib/api";
import { isApiError } from "@/lib/api-error";
import type { AcademySetting } from "@/lib/types";

export interface SettingResult {
  ok: boolean;
  message?: string;
  /** The setting as it now stands, so the row shows the server's answer and not its own guess. */
  setting?: AcademySetting;
}

interface Saved {
  data: AcademySetting;
  meta: { message: string };
}

async function attempt(run: () => Promise<Saved>): Promise<SettingResult> {
  try {
    const saved = await run();
    revalidatePath("/settings/behaviour");

    return { ok: true, message: saved.meta.message, setting: saved.data };
  } catch (error) {
    if (!isApiError(error)) {
      throw error;
    }

    // One field per request, so the first message is the only message.
    return { ok: false, message: Object.values(error.errors)[0]?.[0] ?? error.message };
  }
}

export async function saveSetting(key: string, value: boolean | number | string | string[]): Promise<SettingResult> {
  return attempt(() => api<Saved>(`settings/${key}`, { method: "PUT", json: { value } }));
}

/** Drop this academy's value so the key goes back to what it was given. */
export async function revertSetting(key: string): Promise<SettingResult> {
  return attempt(() => api<Saved>(`settings/${key}`, { method: "DELETE" }));
}
