import { NextResponse, type NextRequest } from "next/server";

const SESSION_COOKIE = "tutorium_session";

/**
 * Send anyone without a session to sign in, before any page renders.
 *
 * A first gate only: it checks that a session cookie exists, not that it is valid. The API decides
 * that on every call, and a rejected token sends the person back here through /sign-out.
 */
export function proxy(request: NextRequest) {
  const signedIn = request.cookies.has(SESSION_COOKIE);
  const onSignIn = request.nextUrl.pathname.startsWith("/sign-in");

  if (!signedIn && !onSignIn) {
    return NextResponse.redirect(new URL("/sign-in", request.url));
  }

  if (signedIn && onSignIn) {
    return NextResponse.redirect(new URL("/", request.url));
  }

  return NextResponse.next();
}

export const config = {
  matcher: ["/((?!_next/static|_next/image|favicon.ico|sign-out).*)"],
};
