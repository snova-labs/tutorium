import type { Metadata } from "next";
import Link from "next/link";

import { ListCard } from "@/app/(app)/settings/lists/list-card";
import { PageHeader } from "@/components/app/page-header";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api";
import type { Vocabulary } from "@/lib/types";

export const metadata: Metadata = { title: "Your lists" };

export default async function ListsPage() {
  // No permission check here: the API leaves out the lists this person may not read, and a teacher
  // reading the note categories is ordinary work rather than something to redirect away from.
  const lists = await api<{ data: Vocabulary[] }>("vocabularies").then((r) => r.data);

  return (
    <>
      <PageHeader
        title="Your lists"
        description="The small lists the rest of the product chooses from. A preset filled them in; these are yours to change."
        actions={
          <Button asChild variant="ghost" size="sm">
            <Link href="/settings">Settings</Link>
          </Button>
        }
      />

      <div className="space-y-6">
        {lists.map((list) => (
          <ListCard key={list.key} list={list} />
        ))}

        <p className="text-sm text-muted-foreground">
          Enrolment and attendance statuses are not here. What they do decides what is invoiced and what counts toward
          an attendance figure, so they belong next to those numbers rather than in a list of names.
        </p>
      </div>
    </>
  );
}
