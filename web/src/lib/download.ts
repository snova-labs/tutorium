import "server-only";

/** Pass a CSV from the API through to the browser as a download. */
export async function csvDownload(upstream: Response, filename: string): Promise<Response> {
  return new Response(await upstream.text(), {
    headers: {
      "Content-Type": "text/csv; charset=UTF-8",
      "Content-Disposition": `attachment; filename="${filename.replace(/[^\w.-]/g, "_")}"`,
      "Cache-Control": "no-store",
    },
  });
}

/** Pass a PDF from the API through to the browser as a download. */
export async function pdfDownload(upstream: Response, filename: string): Promise<Response> {
  return new Response(upstream.body, {
    headers: {
      "Content-Type": "application/pdf",
      "Content-Disposition": `attachment; filename="${filename.replace(/[^\w.-]/g, "_")}"`,
      "Cache-Control": "no-store",
    },
  });
}
