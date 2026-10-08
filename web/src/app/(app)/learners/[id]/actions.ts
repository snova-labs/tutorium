"use server";

import { revalidatePath } from "next/cache";

import { api } from "@/lib/api";
import { isApiError } from "@/lib/api-error";

export interface FormResult {
  ok: boolean;
  message?: string;
  errors?: Record<string, string>;
}

async function attempt(learnerId: number, run: () => Promise<string | undefined>): Promise<FormResult> {
  try {
    const message = await run();
    revalidatePath(`/learners/${learnerId}`);

    return { ok: true, message };
  } catch (error) {
    if (!isApiError(error)) {
      throw error;
    }

    const errors = Object.fromEntries(Object.entries(error.errors).map(([field, messages]) => [field, messages[0]]));

    return { ok: false, message: Object.keys(errors).length === 0 ? error.message : undefined, errors };
  }
}

/** Add a guardian to this learner, creating them or linking someone already on file with that email. */
export async function addGuardian(learnerId: number, _previous: FormResult, formData: FormData): Promise<FormResult> {
  const value = (name: string) => {
    const v = String(formData.get(name) ?? "").trim();

    return v === "" ? undefined : v;
  };

  return attempt(learnerId, async () => {
    await api(`learners/${learnerId}/guardians`, {
      method: "POST",
      json: {
        name: value("name"),
        email: value("email"),
        phone: value("phone"),
        is_primary: formData.get("is_primary") === "on",
        receives_reports: formData.get("receives_reports") === "on",
      },
    });

    return "Guardian added.";
  });
}

/** Whether a guardian receives this learner's reports. */
export async function setReportRecipient(learnerId: number, guardianId: number, receives: boolean): Promise<FormResult> {
  return attempt(learnerId, async () => {
    const { data } = await api<{ data: { message: string } }>(`learners/${learnerId}/guardians/${guardianId}/recipient`, {
      method: "PUT",
      json: { receives_reports: receives },
    });

    return data.message;
  });
}

/** Enrol the learner in a batch. */
export async function enrol(learnerId: number, _previous: FormResult, formData: FormData): Promise<FormResult> {
  const batchId = Number(formData.get("batch_id"));
  const enrolledOn = String(formData.get("enrolled_on") ?? "").trim();

  if (!batchId) {
    return { ok: false, errors: { batch_id: "Choose a batch." } };
  }

  return attempt(learnerId, async () => {
    await api("enrollments", {
      method: "POST",
      json: { learner_id: learnerId, batch_id: batchId, enrolled_on: enrolledOn === "" ? undefined : enrolledOn },
    });

    return "Enrolled.";
  });
}

/** Move one enrollment to another status. Ending it needs a reason, which the API insists on too. */
export async function changeEnrollmentStatus(
  learnerId: number,
  enrollmentId: number,
  statusId: number,
  reason: string,
): Promise<FormResult> {
  return attempt(learnerId, async () => {
    await api(`enrollments/${enrollmentId}/status`, {
      method: "PUT",
      json: { status_id: statusId, reason: reason.trim() === "" ? undefined : reason.trim() },
    });

    return "Status changed.";
  });
}

/** Move the learner to another batch: this enrollment closes, and a new one opens there. */
export async function transferEnrollment(
  learnerId: number,
  enrollmentId: number,
  batchId: number,
  reason: string,
): Promise<FormResult> {
  if (!batchId) {
    return { ok: false, errors: { batch_id: "Choose where they are moving to." } };
  }

  return attempt(learnerId, async () => {
    await api(`enrollments/${enrollmentId}/transfer`, {
      method: "POST",
      json: { batch_id: batchId, reason: reason.trim() === "" ? undefined : reason.trim() },
    });

    return "Moved. Attendance and grades so far stay with the old enrollment.";
  });
}
