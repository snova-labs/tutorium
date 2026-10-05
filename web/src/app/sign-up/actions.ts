"use server";

import { api } from "@/lib/api";
import { isApiError } from "@/lib/api-error";
import type { SignupField, SignupState } from "@/lib/signup";

const FIELDS: SignupField[] = ["name", "owner_name", "owner_email", "password", "country", "timezone", "preset_code"];

/**
 * Send the signup form. The API only stores it and emails a link: nothing is created until the
 * link is used, so success here means "check your email", not "your account is ready".
 */
export async function signUp(_previous: SignupState, formData: FormData): Promise<SignupState> {
  const value = (field: SignupField) => String(formData.get(field) ?? "").trim();
  const values = {
    name: value("name"),
    owner_name: value("owner_name"),
    owner_email: value("owner_email"),
    country: value("country"),
    timezone: value("timezone"),
    preset_code: value("preset_code"),
  };

  try {
    const response = await api<{ data: { email: string } }>("signup", {
      method: "POST",
      anonymous: true,
      json: { ...values, password: String(formData.get("password") ?? "") },
    });

    return { status: "sent", email: response.data.email };
  } catch (error) {
    if (!isApiError(error)) {
      throw error;
    }

    const fields: SignupState["fields"] = {};

    for (const field of FIELDS) {
      const message = error.field(field);

      if (message) {
        fields[field] = message;
      }
    }

    return {
      status: "editing",
      values,
      fields,
      error: Object.keys(fields).length > 0 ? "Check the highlighted answers." : error.message,
    };
  }
}

export interface ConfirmState {
  status: "waiting" | "done";
  account?: string;
  email?: string;
  trialEndsOn?: string | null;
  error?: string;
}

/** Use the emailed link. Only on a click: opening the page alone creates nothing. */
export async function confirmSignup(token: string): Promise<ConfirmState> {
  try {
    const response = await api<{ data: { account: string; email: string; trial_ends_on: string | null } }>(
      `signup/confirm/${encodeURIComponent(token)}`,
      { method: "POST", anonymous: true },
    );

    return {
      status: "done",
      account: response.data.account,
      email: response.data.email,
      trialEndsOn: response.data.trial_ends_on,
    };
  } catch (error) {
    if (!isApiError(error)) {
      throw error;
    }

    return {
      status: "waiting",
      error: error.field("token") ?? error.field("owner_email") ?? error.message,
    };
  }
}
