"use server";

import { revalidatePath } from "next/cache";

import { api } from "@/lib/api";
import { isApiError } from "@/lib/api-error";

export interface DemoResult {
  ok: boolean;
  message?: string;
}

/** Start loading (or removing) the demo academies. The work runs on the server's queue. */
export async function runDemo(action: "load" | "remove", password: string): Promise<DemoResult> {
  try {
    const { data } = await api<{ data: { message: string } }>("demo", {
      method: action === "load" ? "POST" : "DELETE",
      json: { password },
      anonymous: true,
    });
    revalidatePath("/demo");

    return { ok: true, message: data.message };
  } catch (error) {
    if (!isApiError(error)) {
      throw error;
    }

    return { ok: false, message: error.errors.password?.[0] ?? error.message };
  }
}
