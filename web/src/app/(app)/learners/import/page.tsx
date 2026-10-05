import type { Metadata } from "next";

import { ImportWizard } from "@/app/(app)/learners/import/import-wizard";
import { PageHeader } from "@/components/app/page-header";

export const metadata: Metadata = { title: "Import learners" };

export default function ImportPage() {
  return (
    <>
      <PageHeader
        title="Import learners"
        description="Learners and their main guardian from a spreadsheet: checked first, then added in one go."
      />
      <ImportWizard />
    </>
  );
}
