import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { CollectionForm, ConvertTrialForm, PaymentMethodButton } from "@/app/(app)/billing/billing-forms";
import { PageHeader } from "@/components/app/page-header";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { api } from "@/lib/api";
import { getMe } from "@/lib/me";
import { can } from "@/lib/permissions";
import type { Billing } from "@/lib/types";

export const metadata: Metadata = { title: "Billing" };

export default async function BillingPage() {
  if (!can(await getMe(), "billing.manage")) {
    notFound();
  }

  const { data: billing } = await api<{ data: Billing }>("billing");
  const method = billing.payment_method;

  return (
    <>
      <PageHeader title="Billing" description="How you pay, and for what. Nothing here needs an email to us." />

      {billing.trial && (
        <Card className="mb-6">
          <CardHeader>
            <CardTitle>{billing.trial.ended ? "Your trial has ended" : "Choose a plan"}</CardTitle>
            <CardDescription>
              {billing.trial.ended
                ? "The account is read-only until a plan starts. Nothing has been deleted."
                : `The trial runs until ${billing.trial.ends_on}.`}
            </CardDescription>
          </CardHeader>
          <CardContent>
            <ConvertTrialForm billing={billing} />
          </CardContent>
        </Card>
      )}

      <div className="grid gap-6 md:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Plan</CardTitle>
          </CardHeader>
          <CardContent className="space-y-1 text-sm">
            <div className="flex items-center gap-2">
              <span className="font-medium">{billing.plan.current ?? "No plan yet"}</span>
              {billing.plan.status && <Badge variant="secondary">{billing.plan.status}</Badge>}
            </div>
            {billing.plan.changing_to && (
              <p className="text-muted-foreground">
                Moving to {billing.plan.changing_to} on {billing.plan.changing_on}.
              </p>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Payment method</CardTitle>
            <CardDescription>{method.summary}</CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            {method.needs_attention && (
              <Alert variant="warn">
                <AlertTitle>The next invoice cannot be collected</AlertTitle>
                <AlertDescription>Add a card that has not expired, or switch to invoicing.</AlertDescription>
              </Alert>
            )}
            {method.card_available && <PaymentMethodButton hasCard={method.card !== null} />}
            {(method.card_available || method.collection === "card") && <CollectionForm billing={billing} />}
          </CardContent>
        </Card>
      </div>
    </>
  );
}
