"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";

import { api } from "@/lib/api";
import { isApiError } from "@/lib/api-error";

export interface SetupResult {
  ok: boolean;
  message?: string;
  errors?: Record<string, string>;
}

/** Run a call; turn the API's refusal into messages a form can show beside its fields. */
async function attempt(paths: string[], run: () => Promise<string>): Promise<SetupResult> {
  try {
    const message = await run();
    paths.forEach((path) => revalidatePath(path));

    return { ok: true, message };
  } catch (error) {
    if (!isApiError(error)) {
      throw error;
    }

    const errors = Object.fromEntries(Object.entries(error.errors).map(([field, messages]) => [field, messages[0]]));

    return { ok: false, message: Object.keys(errors).length === 0 ? error.message : Object.values(errors)[0], errors };
  }
}

const text = (formData: FormData, name: string) => String(formData.get(name) ?? "").trim();
const optional = (value: string) => (value === "" ? undefined : value);

export async function createCourse(_: SetupResult, formData: FormData): Promise<SetupResult> {
  const periodType = text(formData, "period_type");

  return attempt(["/courses", "/"], async () => {
    await api("courses", {
      method: "POST",
      json: {
        brand_id: Number(text(formData, "brand_id")),
        name: text(formData, "name"),
        code: text(formData, "code"),
        audience: optional(text(formData, "audience")),
        description: optional(text(formData, "description")),
        period_type: periodType,
        period_block_weeks: periodType === "block" ? Number(text(formData, "period_block_weeks") || 4) : undefined,
      },
    });

    return "Course added.";
  });
}

/** Create a class and go straight to it, where its timetable is set. */
export async function createBatch(_: SetupResult, formData: FormData): Promise<SetupResult> {
  let id: number | null = null;

  const result = await attempt(["/batches", "/"], async () => {
    const { data } = await api<{ data: { id: number } }>("batches", {
      method: "POST",
      json: {
        course_id: Number(text(formData, "course_id")),
        branch_id: Number(text(formData, "branch_id")),
        name: text(formData, "name"),
        code: text(formData, "code"),
        starts_on: text(formData, "starts_on"),
        ends_on: optional(text(formData, "ends_on")),
        capacity: optional(text(formData, "capacity")) ? Number(text(formData, "capacity")) : undefined,
        delivery_mode: optional(text(formData, "delivery_mode")),
        // Running from its first day; until then it is planned. Both take enrollments.
        status: text(formData, "starts_on") > new Date().toISOString().slice(0, 10) ? "planned" : "running",
      },
    });
    id = data.id;

    return "Created.";
  });

  if (result.ok && id !== null) {
    redirect(`/batches/${id}?created=1`);
  }

  return result;
}

export async function addSlot(batchId: number, slot: { weekday: number; start: string; minutes: number; typeId: number }): Promise<SetupResult> {
  return attempt([`/batches/${batchId}`], async () => {
    await api(`batches/${batchId}/timetable`, {
      method: "POST",
      json: { weekday: slot.weekday, start_time_local: slot.start, duration_min: slot.minutes, session_type_id: slot.typeId },
    });

    return "Added to the timetable. Generate sessions to put it on the calendar.";
  });
}

export async function removeSlot(batchId: number, slotId: number): Promise<SetupResult> {
  return attempt([`/batches/${batchId}`], async () => {
    const { data } = await api<{ data: { message: string } }>(`batches/${batchId}/timetable/${slotId}`, { method: "DELETE" });

    return data.message;
  });
}

export async function setTeachers(batchId: number, userIds: number[]): Promise<SetupResult> {
  return attempt([`/batches/${batchId}`, "/batches"], async () => {
    await api(`batches/${batchId}/teachers`, {
      method: "PUT",
      json: { teachers: userIds.map((id, i) => ({ user_id: id, role: i === 0 ? "lead" : "assistant" })) },
    });

    return userIds.length === 0 ? "No teacher assigned." : "Teachers saved.";
  });
}

export async function generateSessions(batchId: number, from: string, to: string): Promise<SetupResult> {
  return attempt([`/batches/${batchId}`, "/"], async () => {
    const { data } = await api<{ data: { summary: string } }>(`batches/${batchId}/sessions/generate`, {
      method: "POST",
      json: { from, to },
    });

    return data.summary;
  });
}

export async function cancelSession(batchId: number, sessionId: number, reason: string): Promise<SetupResult> {
  return attempt([`/batches/${batchId}`], async () => {
    await api(`sessions/${sessionId}/cancel`, { method: "POST", json: { reason } });

    return "Session cancelled.";
  });
}

export async function rescheduleSession(batchId: number, sessionId: number, date: string, time: string): Promise<SetupResult> {
  return attempt([`/batches/${batchId}`], async () => {
    await api(`sessions/${sessionId}/reschedule`, {
      method: "POST",
      json: { session_local_date: date, start_time_local: time },
    });

    return "Session moved.";
  });
}
