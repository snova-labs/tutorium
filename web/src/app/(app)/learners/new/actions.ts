"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";

import { api } from "@/lib/api";
import { isApiError } from "@/lib/api-error";

export interface DuplicateCandidate {
  id: number;
  number: string;
  name: string;
  enrolled_in: number;
  reasons: string[];
  confidence: string;
}

export interface NewLearnerState {
  errors: Record<string, string>;
  message?: string;
  duplicates?: DuplicateCandidate[];
  /** What was typed, so a failed submit does not clear the form. */
  values: Record<string, string>;
}

const FIELDS = [
  "legal_name",
  "preferred_name",
  "date_of_birth",
  "email",
  "phone",
  "country",
  "guardian_name",
  "guardian_email",
  "guardian_phone",
] as const;

/**
 * Add a learner, with their main guardian if given.
 *
 * A possible duplicate is a question, not a refusal: the API answers 409 with the matches, the form
 * shows them, and "Add anyway" sends the same details again with force.
 */
export async function createLearner(_: NewLearnerState, formData: FormData): Promise<NewLearnerState> {
  const values = Object.fromEntries(FIELDS.map((f) => [f, String(formData.get(f) ?? "").trim()]));
  const optional = (value: string) => (value === "" ? undefined : value);

  const payload = {
    legal_name: values.legal_name,
    preferred_name: optional(values.preferred_name),
    date_of_birth: optional(values.date_of_birth),
    email: optional(values.email),
    phone: optional(values.phone),
    country: optional(values.country.toUpperCase()),
    guardian:
      values.guardian_name === ""
        ? undefined
        : {
            name: values.guardian_name,
            email: optional(values.guardian_email),
            phone: optional(values.guardian_phone),
          },
    force: formData.get("force") === "1",
  };

  let created: { id: number };

  try {
    created = (await api<{ data: { id: number } }>("learners", { method: "POST", json: payload })).data;
  } catch (error) {
    if (!isApiError(error)) {
      throw error;
    }

    if (error.status === 409) {
      const body = error.body as { data?: { possible_duplicates?: DuplicateCandidate[] } };

      return { errors: {}, values, message: error.message, duplicates: body.data?.possible_duplicates ?? [] };
    }

    const errors: Record<string, string> = {};

    for (const [field, messages] of Object.entries(error.errors)) {
      errors[field.replace("guardian.", "guardian_")] = messages[0];
    }

    return { errors, values, message: Object.keys(errors).length === 0 ? error.message : undefined };
  }

  revalidatePath("/learners");
  // Straight to the new learner, where the next step (enrolling them in a batch) is.
  redirect(`/learners/${created.id}`);
}
