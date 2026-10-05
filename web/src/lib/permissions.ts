import type { Me } from "@/lib/types";

/**
 * Whether to offer something in the interface. Never the control itself: the API checks every
 * request. The Owner holds every permission through a rule rather than a stored list, so the API
 * reports no permissions for them; the role is what says so.
 */
export function can(me: Pick<Me, "roles" | "permissions">, permission: string): boolean {
  return me.roles.includes("Owner") || me.permissions.includes(permission);
}
