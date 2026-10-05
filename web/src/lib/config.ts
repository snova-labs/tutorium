import "server-only";

/** Where the Laravel API lives, as seen from the Next.js server. Never sent to the browser. */
export function apiBaseUrl(): string {
  return (process.env.API_BASE_URL ?? "http://127.0.0.1:8000/api/v1").replace(/\/+$/, "");
}

/** How long a signed-in browser keeps its session before signing in again. */
export const SESSION_HOURS = 12;

export const SESSION_COOKIE = "tutorium_session";
