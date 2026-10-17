import type { AcademySetting } from "@/lib/types";

/**
 * How a value reads on the screen, whatever its type.
 *
 * The chip shows this, so a list of roles has to read as a sentence rather than as an array, and
 * a number has to carry its unit where it has one.
 */
export function settingValue(setting: AcademySetting): string {
  const { value } = setting;

  if (Array.isArray(value)) {
    return value.length === 0 ? "Nobody" : value.join(", ");
  }

  if (typeof value === "boolean") {
    return value ? "Yes" : "No";
  }

  if (value === null) {
    return "Not set";
  }

  if (setting.type === "choice") {
    return setting.choices?.[String(value)] ?? String(value);
  }

  return String(value);
}

/**
 * Where the value came from, in words.
 *
 * Three answers worth telling apart: somebody here chose it, the preset chose it when the account
 * was created, or nothing has ever decided and this is what the product ships with. The middle one
 * is the one that goes unexamined for years, which is why it says so plainly.
 */
export function origin(setting: AcademySetting): string {
  if (setting.set_here) {
    // A row stored before the origin column existed: a value was chosen here, but by whom and
    // when is not recorded, and inventing an answer would be worse than saying so.
    return setting.origin === null ? "Already set on your academy" : "Set by your academy";
  }

  if (setting.origin === "preset") {
    return "Chosen by your preset when the academy was created";
  }

  if (setting.source !== "system" && setting.source !== "tenant") {
    return `Inherited from the ${setting.source}`;
  }

  return "What the product ships with";
}

/** Whether there is anything to revert: only a value this academy set is its own to drop. */
export function canRevert(setting: AcademySetting): boolean {
  return setting.set_here;
}

/**
 * What a revert would land on.
 *
 * Reverting drops this academy's row, and a preset's row for the same key was replaced when
 * somebody saved over it — so the honest answer is the shipped default, not the preset's value.
 */
export function revertsTo(setting: AcademySetting): string {
  return settingValue({ ...setting, value: setting.shipped_default });
}
