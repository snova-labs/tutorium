"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";

import { api } from "@/lib/api";
import { isApiError } from "@/lib/api-error";
import { isHexColor } from "@/lib/organisation";

export interface OrgResult {
  ok: boolean;
  message?: string;
  errors?: Record<string, string>;
}

/** Everything here shows in the header or the setup guide, so every screen refreshes. */
async function attempt(run: () => Promise<string>): Promise<OrgResult> {
  try {
    const message = await run();
    revalidatePath("/", "layout");

    return { ok: true, message };
  } catch (error) {
    if (!isApiError(error)) {
      throw error;
    }

    const errors = Object.fromEntries(
      // "colors.primary" is one box on the form, named as the API names it.
      Object.entries(error.errors).map(([field, messages]) => [field, messages[0]]),
    );

    return { ok: false, message: error.message, errors };
  }
}

const text = (formData: FormData, name: string) => String(formData.get(name) ?? "").trim();
const optional = (value: string) => (value === "" ? null : value);
const days = (formData: FormData) => formData.getAll("weekend_days").map(String).filter((day) => day !== "");

/** Save the name, sender and colours that appear on reports. Creates the brand if there is none. */
export async function saveBrand(_: OrgResult, formData: FormData): Promise<OrgResult> {
  const id = text(formData, "id");
  const colors: Record<string, string> = {};

  for (const key of ["primary", "accent"] as const) {
    const value = text(formData, `colors.${key}`);

    if (value !== "") {
      if (!isHexColor(value)) {
        return { ok: false, message: "A colour must look like #1B5FAA.", errors: { [`colors.${key}`]: "Use a colour like #1B5FAA." } };
      }

      colors[key] = value.toLowerCase();
    }
  }

  const json = {
    name: text(formData, "name"),
    code: text(formData, "code"),
    sender_name: optional(text(formData, "sender_name")),
    sender_email: optional(text(formData, "sender_email")),
    colors,
  };

  return attempt(async () => {
    if (id === "") {
      await api("brands", { method: "POST", json });

      return "Saved. Your details now appear on reports.";
    }

    await api(`brands/${id}`, { method: "PATCH", json });

    return "Saved.";
  });
}

export async function uploadLogo(_: OrgResult, formData: FormData): Promise<OrgResult> {
  const id = text(formData, "id");
  const logo = formData.get("logo");

  if (id === "") {
    return { ok: false, message: "Save your details first, then add a logo." };
  }

  if (!(logo instanceof File) || logo.size === 0) {
    return { ok: false, message: "Choose an image first.", errors: { logo: "Choose an image." } };
  }

  const body = new FormData();
  body.set("logo", logo);

  return attempt(async () => {
    await api(`brands/${id}/logo`, { method: "POST", form: body });

    return "Logo saved. It appears at the top of every report.";
  });
}

export async function removeLogo(id: number): Promise<OrgResult> {
  return attempt(async () => {
    await api(`brands/${id}/logo`, { method: "DELETE" });

    return "Logo removed.";
  });
}

/** Add a location, then go back to the list, where it now appears. */
export async function createLocation(_: OrgResult, formData: FormData): Promise<OrgResult> {
  const result = await attempt(async () => {
    await api("branches", {
      method: "POST",
      json: {
        brand_id: Number(text(formData, "brand_id")),
        name: text(formData, "name"),
        code: text(formData, "code"),
        timezone: text(formData, "timezone"),
        week_start: optional(text(formData, "week_start")),
        weekend_days: days(formData),
        phone: optional(text(formData, "phone")),
        email: optional(text(formData, "email")),
      },
    });

    return "Location added.";
  });

  if (result.ok) {
    redirect("/organisation?added=1");
  }

  return result;
}

export async function saveLocation(id: number, _: OrgResult, formData: FormData): Promise<OrgResult> {
  return attempt(async () => {
    await api(`branches/${id}`, {
      method: "PATCH",
      json: {
        name: text(formData, "name"),
        code: text(formData, "code"),
        timezone: text(formData, "timezone"),
        week_start: optional(text(formData, "week_start")),
        weekend_days: days(formData),
        phone: optional(text(formData, "phone")),
        email: optional(text(formData, "email")),
      },
    });

    return "Saved.";
  });
}

/** Archiving keeps every record and stops new enrollments. Nothing here deletes anything. */
export async function archiveLocation(id: number): Promise<OrgResult> {
  return attempt(async () => {
    const { data } = await api<{ data: { message: string } }>(`branches/${id}`, { method: "DELETE" });

    return data.message;
  });
}

export async function reopenLocation(id: number): Promise<OrgResult> {
  return attempt(async () => {
    await api(`branches/${id}`, { method: "PATCH", json: { is_active: true } });

    return "Location reopened.";
  });
}
