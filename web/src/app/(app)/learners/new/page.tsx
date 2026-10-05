import type { Metadata } from "next";

import { NewLearnerForm } from "@/app/(app)/learners/new/new-learner-form";
import { PageHeader } from "@/components/app/page-header";

export const metadata: Metadata = { title: "Add learner" };

export default function NewLearnerPage() {
  return (
    <>
      <PageHeader title="Add learner" description="We check for someone already on file before adding." />
      <NewLearnerForm />
    </>
  );
}
