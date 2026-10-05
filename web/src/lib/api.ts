import "server-only";

import { headers } from "next/headers";
import { redirect } from "next/navigation";

import { ApiError } from "@/lib/api-error";
import { apiBaseUrl } from "@/lib/config";
import { getToken } from "@/lib/session";

type Query = Record<string, string | number | boolean | null | undefined>;

export interface ApiOptions {
  method?: "GET" | "POST" | "PUT" | "PATCH" | "DELETE";
  query?: Query;
  /** Sent as JSON. */
  json?: unknown;
  /** Sent as multipart, for file uploads. */
  form?: FormData;
  /** Calls made before sign-in (the login itself) carry no token. */
  anonymous?: boolean;
}

export interface Paginated<T> {
  data: T[];
  meta?: { current_page: number; last_page: number; per_page: number; total: number };
}

/**
 * Call the Laravel API from the server, as the signed-in person.
 *
 * A 401 means the token is gone or revoked, so the person is sent to sign in again (through
 * /sign-out, which clears the stale cookie). Anything else that is not a success becomes an
 * ApiError carrying Laravel's message and per-field errors.
 */
export async function api<T>(path: string, options: ApiOptions = {}): Promise<T> {
  const response = await rawApi(path, options);

  if (response.status === 204) {
    return undefined as T;
  }

  return (await response.json()) as T;
}

/** The same call, returning the response itself: for file downloads. */
export async function rawApi(path: string, options: ApiOptions = {}): Promise<Response> {
  const url = new URL(apiBaseUrl() + "/" + path.replace(/^\/+/, ""));

  for (const [key, value] of Object.entries(options.query ?? {})) {
    if (value !== undefined && value !== null && value !== "") {
      url.searchParams.set(key, String(value));
    }
  }

  const requestHeaders: Record<string, string> = { Accept: "application/json" };

  if (!options.anonymous) {
    const token = await getToken();

    if (token === null) {
      redirect("/sign-in");
    }

    requestHeaders.Authorization = `Bearer ${token}`;
  }

  // Laravel rate-limits sign-in per address and IP. Passing the browser's address on keeps that
  // per person rather than per server (Laravel must trust this server as a proxy; see the README).
  const forwardedFor = (await headers()).get("x-forwarded-for");

  if (forwardedFor) {
    requestHeaders["X-Forwarded-For"] = forwardedFor;
  }

  let body: BodyInit | undefined;

  if (options.form) {
    body = options.form;
  } else if (options.json !== undefined) {
    body = JSON.stringify(options.json);
    requestHeaders["Content-Type"] = "application/json";
  }

  const response = await fetch(url, {
    method: options.method ?? "GET",
    headers: requestHeaders,
    body,
    cache: "no-store",
  });

  if (response.status === 401 && !options.anonymous) {
    redirect("/sign-out");
  }

  if (!response.ok) {
    throw await toApiError(response);
  }

  return response;
}

async function toApiError(response: Response): Promise<ApiError> {
  let body: unknown = null;

  try {
    body = await response.json();
  } catch {
    // Not JSON: an HTML error page from a proxy, say. The status says enough.
  }

  const parsed = (body ?? {}) as { message?: string; errors?: Record<string, string[]> };

  return new ApiError(
    response.status,
    parsed.message ?? defaultMessage(response.status),
    parsed.errors ?? {},
    body,
  );
}

function defaultMessage(status: number): string {
  if (status === 403) return "You do not have permission to do that.";
  if (status === 404) return "That could not be found. It may have been removed.";
  if (status === 423) return "This account is read-only. Your data remains available.";
  if (status === 429) return "Too many attempts. Wait a minute and try again.";

  return "Something went wrong. Try again in a moment.";
}
