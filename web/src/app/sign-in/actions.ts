"use server";

import { redirect } from "next/navigation";

import { api } from "@/lib/api";
import { isApiError } from "@/lib/api-error";
import { setToken } from "@/lib/session";

export interface SignInState {
  step: "credentials" | "code";
  email: string;
  error?: string;
  /** Shown on the code step: the API's own words about where the code went. */
  notice?: string;
}

/**
 * Sign in against the API. Two steps for the roles an academy has chosen: the password, then the
 * code emailed to them. The API is stateless, so the second step sends the password again with
 * the code; the form keeps the password in memory between the steps, never in the page.
 */
export async function signIn(previous: SignInState, formData: FormData): Promise<SignInState> {
  const email = String(formData.get("email") ?? "").trim();
  const password = String(formData.get("password") ?? "");
  const code = String(formData.get("code") ?? "").trim();

  if (email === "" || password === "") {
    return { step: "credentials", email, error: "Enter your email and password." };
  }

  let token: string;

  try {
    const response = await api<{ data: { token: string } }>("auth/login", {
      method: "POST",
      anonymous: true,
      json: { email, password, device: "web", ...(code !== "" ? { code } : {}) },
    });
    token = response.data.token;
  } catch (error) {
    if (!isApiError(error)) {
      throw error;
    }

    const codeMessage = error.field("code");

    if (codeMessage && code === "") {
      return { step: "code", email, notice: codeMessage };
    }

    if (codeMessage) {
      return { step: "code", email, error: codeMessage };
    }

    return {
      step: previous.step === "code" && error.status !== 422 ? "code" : "credentials",
      email,
      error: error.field("email") ?? error.message,
    };
  }

  await setToken(token);
  redirect("/");
}
