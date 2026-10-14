import { rawApi } from "@/lib/api";
import { isApiError } from "@/lib/api-error";

/**
 * The template rendered with neutral sample data, for the person deciding what to send.
 *
 * Never a real learner: the API builds it from a sample, and this route only passes it on.
 */
export async function GET(_request: Request, { params }: RouteContext<"/settings/reports/[id]/preview">) {
  const { id } = await params;

  if (!/^\d+$/.test(id)) {
    return new Response("Not found", { status: 404 });
  }

  try {
    const upstream = await rawApi(`report-templates/${id}/preview`);

    return new Response(await upstream.text(), {
      headers: {
        "Content-Type": "text/html; charset=UTF-8",
        // A sample page, but it is still this academy's wording: never a shared cache.
        "Cache-Control": "private, no-store",
      },
    });
  } catch (error) {
    if (!isApiError(error)) {
      throw error;
    }

    return new Response("Not found", { status: error.status === 403 ? 403 : 404 });
  }
}
