import { rawApi } from "@/lib/api";
import { csvDownload } from "@/lib/download";

export async function GET() {
  return csvDownload(await rawApi("imports/learners/template"), "learners-template.csv");
}
