import { NextResponse } from "next/server";

import { apiBaseUrl } from "@/lib/config";
import { clearToken, getToken } from "@/lib/session";

/**
 * Revoke the token at the API, then forget it here.
 *
 * GET as well as POST: the API client redirects here when a token turns out to be dead, and a
 * redirect is always a GET. Revoking an already-dead token is harmless.
 */
async function signOut(): Promise<NextResponse> {
  const token = await getToken();

  if (token !== null) {
    await fetch(`${apiBaseUrl()}/auth/logout`, {
      method: "POST",
      headers: { Accept: "application/json", Authorization: `Bearer ${token}` },
      cache: "no-store",
    }).catch(() => undefined);
  }

  await clearToken();

  // A relative Location, resolved by the browser against the address it used. The server's own
  // idea of its URL (request.url, nextUrl) can name a different host behind a proxy.
  return new NextResponse(null, { status: 303, headers: { Location: "/sign-in" } });
}

export { signOut as GET, signOut as POST };
