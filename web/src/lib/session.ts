import "server-only";

import { cookies } from "next/headers";

import { SESSION_COOKIE, SESSION_HOURS } from "@/lib/config";

/**
 * The API token for the signed-in person.
 *
 * Held in an httpOnly cookie, so script running in the page (including anything injected) cannot
 * read it. The browser never talks to the API directly: every call goes through this server,
 * which attaches the token (a backend-for-frontend).
 */
export async function getToken(): Promise<string | null> {
  return (await cookies()).get(SESSION_COOKIE)?.value ?? null;
}

export async function setToken(token: string): Promise<void> {
  (await cookies()).set(SESSION_COOKIE, token, {
    httpOnly: true,
    secure: process.env.NODE_ENV === "production",
    sameSite: "lax",
    path: "/",
    maxAge: SESSION_HOURS * 60 * 60,
  });
}

export async function clearToken(): Promise<void> {
  (await cookies()).delete(SESSION_COOKIE);
}
