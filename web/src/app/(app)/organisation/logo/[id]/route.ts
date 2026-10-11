import { rawApi } from "@/lib/api";
import { isApiError } from "@/lib/api-error";

/** Only the formats the API accepts for a logo. Anything else is not served back as an image. */
const ALLOWED = ["image/png", "image/jpeg", "image/webp"];

/**
 * The brand's logo, for an <img> in the client.
 *
 * The browser holds a session cookie rather than the API token, so the image is fetched here, as
 * the signed-in person, and passed through.
 */
export async function GET(_request: Request, { params }: RouteContext<"/organisation/logo/[id]">) {
  const { id } = await params;

  if (!/^\d+$/.test(id)) {
    return new Response("Not found", { status: 404 });
  }

  let upstream: Response;

  try {
    upstream = await rawApi(`brands/${id}/logo`);
  } catch (error) {
    // A removed logo, or a brand this person may not see: nothing to show either way. A redirect
    // to sign in is not an error and must carry on.
    if (!isApiError(error)) {
      throw error;
    }

    return new Response("Not found", { status: error.status === 403 ? 403 : 404 });
  }

  const type = upstream.headers.get("Content-Type")?.split(";")[0].trim() ?? "";

  if (!ALLOWED.includes(type)) {
    return new Response("Not found", { status: 404 });
  }

  return new Response(upstream.body, {
    headers: {
      "Content-Type": type,
      // One academy's logo must never be handed to another's browser from a shared cache.
      "Cache-Control": "private, max-age=300",
    },
  });
}
