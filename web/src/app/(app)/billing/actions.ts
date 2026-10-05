"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";

import { api } from "@/lib/api";
import { isApiError } from "@/lib/api-error";

export interface BillingActionState {
  error?: string;
  errors?: Record<string, string>;
  message?: string;
}

function failure(error: unknown): BillingActionState {
  if (!isApiError(error)) {
    throw error;
  }

  return {
    error: error.message,
    errors: Object.fromEntries(Object.entries(error.errors).map(([k, v]) => [k, v[0]])),
  };
}

/** Off to the payment provider's own page: card details never pass through us. */
export async function openPaymentMethod(): Promise<BillingActionState> {
  let url: string | null;

  try {
    // No return address is sent, so the API uses its own billing page; it only ever allows its own host.
    url = (await api<{ data: { url: string | null; message: string } }>("billing/payment-method", { method: "POST" })).data.url;
  } catch (error) {
    return failure(error);
  }

  if (url === null) {
    return { message: "This account is invoiced rather than charged automatically." };
  }

  redirect(url);
}

export async function setCollection(_: BillingActionState, formData: FormData): Promise<BillingActionState> {
  const method = String(formData.get("method"));

  try {
    const { data } = await api<{ data: { message: string } }>("billing/collection", {
      method: "PUT",
      json: {
        method,
        legal_name: String(formData.get("legal_name") ?? "").trim() || undefined,
        billing_email: String(formData.get("billing_email") ?? "").trim() || undefined,
      },
    });

    revalidatePath("/billing");

    return { message: data.message };
  } catch (error) {
    return failure(error);
  }
}

/** From trial to paying in one step. */
export async function convertTrial(_: BillingActionState, formData: FormData): Promise<BillingActionState> {
  let result: { converted: boolean; checkout_url: string | null; message: string };

  try {
    result = (
      await api<{ data: typeof result }>("billing/convert", {
        method: "POST",
        json: {
          plan_code: String(formData.get("plan_code") ?? ""),
          method: String(formData.get("method") ?? "card"),
          legal_name: String(formData.get("legal_name") ?? "").trim() || undefined,
          billing_email: String(formData.get("billing_email") ?? "").trim() || undefined,
        },
      })
    ).data;
  } catch (error) {
    return failure(error);
  }

  if (!result.converted && result.checkout_url) {
    redirect(result.checkout_url);
  }

  revalidatePath("/", "layout");

  return { message: result.message };
}
