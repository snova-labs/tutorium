import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { DemoPanel } from "@/app/demo/demo-panel";
import { api } from "@/lib/api";
import { isApiError } from "@/lib/api-error";
import type { DemoStatus } from "@/lib/demo";

export const metadata: Metadata = { title: "Demo data" };

/**
 * Load or remove the demo academies with the demo password. Exists only where the API says so:
 * never on production, and not until DEMO_PASSWORD is set.
 */
export default async function DemoPage() {
  let status: DemoStatus;

  try {
    status = (await api<{ data: DemoStatus }>("demo", { anonymous: true })).data;
  } catch (error) {
    if (isApiError(error) && error.status === 404) {
      notFound();
    }

    throw error;
  }

  return (
    <main className="flex flex-1 justify-center p-6">
      <div className="w-full max-w-3xl">
        <div className="mb-6 flex items-center gap-2">
          <span className="grid size-8 place-items-center rounded-md bg-primary text-sm font-bold text-primary-foreground">
            T
          </span>
          <span className="font-semibold">Tutorium · demo data</span>
        </div>
        <DemoPanel status={status} />
      </div>
    </main>
  );
}
