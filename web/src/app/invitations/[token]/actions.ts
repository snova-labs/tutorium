"use server";

import { api } from "@/lib/api";
import { isApiError } from "@/lib/api-error";

export interface AcceptState {
  done: boolean;
  email?: string;
  error?: string;
  passwordError?: string;
}

/** Accept the invitation and set a password. The account exists from this moment. */
export async function accept(token: string, _previous: AcceptState, formData: FormData): Promise<AcceptState> {
  const name = String(formData.get("name") ?? "").trim();

  try {
    const { data } = await api<{ data: { email: string } }>(`invitations/${encodeURIComponent(token)}`, {
      method: "POST",
      anonymous: true,
      json: {
        name: name === "" ? undefined : name,
        password: String(formData.get("password") ?? ""),
        timezone: String(formData.get("timezone") ?? "") || undefined,
      },
    });

    return { done: true, email: data.email };
  } catch (error) {
    if (!isApiError(error)) {
      throw error;
    }

    return {
      done: false,
      passwordError: error.field("password"),
      error: error.field("password") ? undefined : (error.field("token") ?? error.message),
    };
  }
}
