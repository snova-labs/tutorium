import Link from "next/link";
import { CheckCircle2, Circle } from "lucide-react";

import { PageHeader } from "@/components/app/page-header";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { api } from "@/lib/api";
import { getMe } from "@/lib/me";
import { can } from "@/lib/permissions";

interface Onboarding {
  dismissed: boolean;
  complete: boolean;
  done: number;
  total: number;
  steps: { key: string; title: string; hint: string; done: boolean; detail: string | null }[];
}

interface Trial {
  in_trial: boolean;
  ends_on?: string;
  days_left?: number;
  what_happens_at_the_end?: string[];
}

export default async function HomePage() {
  const me = await getMe();
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
            </CardDescription>
          </CardHeader>
          <CardContent>
            <ol className="space-y-3">
              {onboarding.steps.map((step) => (
                <li key={step.key} className="flex gap-3">
                  {step.done ? (
                    <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-ok" aria-label="Done" />
                  ) : (
                    <Circle className="mt-0.5 size-4 shrink-0 text-faint" aria-label="Not done yet" />
                  )}
                  <div>
                    <div className="text-sm font-medium">{step.title}</div>
                    <div className="text-xs text-muted-foreground">{step.detail ?? step.hint}</div>
                  </div>
                </li>
              ))}
            </ol>
          </CardContent>
        </Card>
      )}

      <div className="mt-6 grid gap-4 sm:grid-cols-2">
        {can(me, "learners.view") && (
          <Card>
            <CardHeader>
              <CardTitle>Learners</CardTitle>
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
            <CardTitle>Batches</CardTitle>
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
