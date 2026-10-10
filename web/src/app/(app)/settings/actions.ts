"use server";

import { revalidatePath } from "next/cache";

import { api } from "@/lib/api";
import { isApiError } from "@/lib/api-error";
import { getTerms } from "@/lib/me";
import { termChanges } from "@/lib/settings";
import type { Terms } from "@/lib/types";

export interface SettingsResult {
  ok: boolean;
  message?: string;
  errors?: Record<string, string>;
}

/** Run one settings call. Every page shows these settings somewhere, so all of them refresh. */
async function attempt(run: () => Promise<string>): Promise<SettingsResult> {
  try {
    const message = await run();
    revalidatePath("/", "layout");

    return { ok: true, message };
  } catch (error) {
    if (!isApiError(error)) {
      throw error;
    }

    const errors = Object.fromEntries(
      Object.entries(error.errors).map(([field, messages]) => [field.replace(/^terms\./, ""), messages[0]]),
    );

    return { ok: false, message: error.message, errors };
  }
}

/** Save what the academy calls things. Only the nouns that changed are sent. */
export async function saveTerms(edited: Terms): Promise<SettingsResult> {
  const { changes, errors } = termChanges(await getTerms(), edited);

  if (Object.keys(errors).length > 0) {
    return { ok: false, message: "Every noun needs a word in both boxes.", errors };
  }

  if (Object.keys(changes).length === 0) {
    return { ok: true, message: "Nothing had changed." };
  }

  return attempt(async () => {
    const { data } = await api<{ data: { message: string } }>("terminology", { method: "PUT", json: { terms: changes } });

    return data.message;
  });
}

export async function applyPreset(code: string): Promise<SettingsResult> {
  return attempt(async () => {
    const { data } = await api<{ data: { message: string } }>("presets/apply", { method: "POST", json: { preset_code: code } });

    return data.message;
  });
}

export async function hideSetupGuide(): Promise<SettingsResult> {
  return attempt(async () => (await api<{ data: { message: string } }>("onboarding/dismiss", { method: "POST" })).data.message);
}

export async function reopenSetupGuide(): Promise<SettingsResult> {
  return attempt(async () => (await api<{ data: { message: string } }>("onboarding/reopen", { method: "POST" })).data.message);
}

export async function loadSampleData(): Promise<SettingsResult> {
  return attempt(async () => (await api<{ data: { message: string } }>("onboarding/sample-data", { method: "POST" })).data.message);
}

export async function removeSampleData(): Promise<SettingsResult> {
  return attempt(async () => (await api<{ data: { message: string } }>("onboarding/sample-data", { method: "DELETE" })).data.message);
}
