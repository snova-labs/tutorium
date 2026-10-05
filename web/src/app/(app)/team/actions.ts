"use server";

import { revalidatePath } from "next/cache";

import { api } from "@/lib/api";
import { isApiError } from "@/lib/api-error";

export interface InviteState {
  ok: boolean;
  message?: string;
  errors?: Record<string, string>;
}

async function attempt(run: () => Promise<string>): Promise<InviteState> {
  try {
    const message = await run();
    revalidatePath("/team");

    return { ok: true, message };
  } catch (error) {
    if (!isApiError(error)) {
      throw error;
    }

    const errors = Object.fromEntries(Object.entries(error.errors).map(([field, messages]) => [field, messages[0]]));

    return { ok: false, message: error.allMessages()[0] ?? error.message, errors };
  }
}

async function send(email: string, name: string | undefined, role: string, allBranches: boolean): Promise<string> {
  const { data } = await api<{ data: { message: string } }>("invitations", {
    method: "POST",
    json: { email, name, role_name: role, scope_all_branches: allBranches },
  });

  return data.message;
}

/** Invite someone. Nothing is granted until they accept. */
export async function invite(_previous: InviteState, formData: FormData): Promise<InviteState> {
  const email = String(formData.get("email") ?? "").trim();
  const name = String(formData.get("name") ?? "").trim();

  return attempt(() => send(email, name === "" ? undefined : name, String(formData.get("role_name") ?? ""), formData.get("scope_all_branches") === "on"));
}

/** An expired invitation is replaced by inviting the same address again. */
export async function reinvite(email: string, role: string): Promise<InviteState> {
  return attempt(() => send(email, undefined, role, true));
}

export async function resend(invitationId: number): Promise<InviteState> {
  return attempt(async () => (await api<{ data: { message: string } }>(`invitations/${invitationId}/resend`, { method: "POST" })).data.message);
}

export async function revoke(invitationId: number): Promise<InviteState> {
  return attempt(async () => (await api<{ data: { message: string } }>(`invitations/${invitationId}`, { method: "DELETE" })).data.message);
}
