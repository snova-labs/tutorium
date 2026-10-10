import Link from "next/link";
import { CheckCircle2, Circle } from "lucide-react";

import { PageHeader } from "@/components/app/page-header";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { ActionButton } from "@/app/(app)/settings/action-button";
import { hideSetupGuide, loadSampleData, removeSampleData } from "@/app/(app)/settings/actions";
import { api } from "@/lib/api";
import { getMe, getTerms } from "@/lib/me";
import { can } from "@/lib/permissions";
import { stepHref } from "@/lib/settings";
import type { Onboarding } from "@/lib/types";

interface Trial {
  in_trial: boolean;
  ends_on?: string;
  days_left?: number;
  what_happens_at_the_end?: string[];
}

export default async function HomePage() {
  const [me, terms] = await Promise.all([getMe(), getTerms()]);
  const manages = can(me, "settings.manage");
  const [onboarding, trial] = await Promise.all([
    api<{ data: Onboarding }>("onboarding").then((r) => r.data),
    api<{ data: Trial }>("trial").then((r) => r.data),
  ]);

  return (
    <>
      <PageHeader title={`Hello, ${me.name.split(" ")[0]}`} description={me.tenant.name} />

      {trial.in_trial && (
        <Alert className="mb-6">
          <AlertTitle>
            Trial: {trial.days_left} {trial.days_left === 1 ? "day" : "days"} left, ending {trial.ends_on}
          </AlertTitle>
          <AlertDescription>
            <ul className="list-disc pl-4">
              {trial.what_happens_at_the_end?.map((line) => <li key={line}>{line}</li>)}
            </ul>
            {can(me, "billing.manage") && (
              <Button asChild size="sm" className="mt-2">
                <Link href="/billing">Choose a plan</Link>
              </Button>
            )}
          </AlertDescription>
        </Alert>
      )}

      {!onboarding.dismissed && !onboarding.complete && (
        <Card>
          <CardHeader>
            <CardTitle>Getting set up</CardTitle>
            <CardDescription>
              {onboarding.done} of {onboarding.total} done
              {onboarding.can_record_attendance && " · Teachers can take registers now; the rest can wait."}
            </CardDescription>
          </CardHeader>
          <CardContent>
            <ol className="space-y-3">
              {onboarding.steps.map((step) => {
                const href = step.done ? null : stepHref(step.key);

                return (
                  <li key={step.key} className="flex gap-3">
                    {step.done ? (
                      <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-ok" aria-label="Done" />
                    ) : (
                      <Circle className="mt-0.5 size-4 shrink-0 text-faint" aria-label="Not done yet" />
                    )}
                    <div>
                      <div className="text-sm font-medium">
                        {href ? (
                          <Link href={href} className="hover:underline">
                            {step.title}
                          </Link>
                        ) : (
                          step.title
                        )}
                      </div>
                      <div className="text-xs text-muted-foreground">{step.detail ?? step.hint}</div>
                    </div>
                  </li>
                );
              })}
            </ol>
            {manages && (
              <div className="mt-4 flex flex-wrap gap-2">
                {onboarding.sample_data_loaded ? (
                  <ActionButton
                    size="sm"
                    variant="outline"
                    run={removeSampleData}
                    confirm="Remove the sample class and everything recorded in it?"
                    pendingLabel="Removing…"
                  >
                    Remove sample data
                  </ActionButton>
                ) : (
                  <ActionButton size="sm" variant="outline" run={loadSampleData} pendingLabel="Loading…">
                    Try it with a sample class
                  </ActionButton>
                )}
                <ActionButton size="sm" variant="ghost" run={hideSetupGuide}>
                  Hide this guide
                </ActionButton>
              </div>
            )}
          </CardContent>
        </Card>
      )}

      <div className="mt-6 grid gap-4 sm:grid-cols-2">
        {can(me, "learners.view") && (
          <Card>
            <CardHeader>
              <CardTitle>{terms.learner.plural}</CardTitle>
              <CardDescription>Everyone you teach, and who receives their reports.</CardDescription>
            </CardHeader>
            <CardContent className="flex gap-2">
              <Button asChild variant="outline" size="sm">
                <Link href="/learners">Open</Link>
              </Button>
              {can(me, "learners.create") && (
                <Button asChild variant="outline" size="sm">
                  <Link href="/learners/import">Import a spreadsheet</Link>
                </Button>
              )}
            </CardContent>
          </Card>
        )}
        <Card>
          <CardHeader>
            <CardTitle>{terms.batch.plural}</CardTitle>
            <CardDescription>Classes, their clocks and their sessions.</CardDescription>
          </CardHeader>
          <CardContent>
            <Button asChild variant="outline" size="sm">
              <Link href="/batches">Open</Link>
            </Button>
          </CardContent>
        </Card>
      </div>
    </>
  );
}
