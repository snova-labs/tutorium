import { rawApi } from "@/lib/api";
import { pdfDownload } from "@/lib/download";

/** Exactly what was sent, rebuilt by the API from the report's snapshot. */
export async function GET(_request: Request, { params }: RouteContext<"/reports/[id]/download">) {
  const { id } = await params;

  if (!/^\d+$/.test(id)) {
    return new Response("Not found", { status: 404 });
  }

  const upstream = await rawApi(`reports/${id}/download`);
  const disposition = upstream.headers.get("Content-Disposition") ?? "";
  const filename = /filename="([^"]+)"/.exec(disposition)?.[1] ?? `report-${id}.pdf`;

  return pdfDownload(upstream, filename);
}
