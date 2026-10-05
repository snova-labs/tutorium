import { rawApi } from "@/lib/api";
import { csvDownload } from "@/lib/download";

export async function GET(_: Request, { params }: RouteContext<"/learners/import/[id]/rejects">) {
  const { id } = await params;

  if (!/^\d+$/.test(id)) {
    return new Response("Not found", { status: 404 });
  }

  return csvDownload(await rawApi(`imports/${id}/rejects`), `import-${id}-blocked-rows.csv`);
}
