"use client";

import { useActionState, useState } from "react";

import {
  convertTrial,
  openPaymentMethod,
  setCollection,
  type BillingActionState,
} from "@/app/(app)/billing/actions";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import type { Billing } from "@/lib/types";

function Outcome({ state }: { state: BillingActionState }) {
  if (state.message) {
    return (
      <Alert className="mt-3">
        <AlertDescription>{state.message}</AlertDescription>
      </Alert>
    );
  }

  if (state.error) {
    const details = Object.values(state.errors ?? {});

    return (
      <Alert variant="destructive" className="mt-3">
        <AlertDescription>
          {details.length > 0 ? details.map((d) => <p key={d}>{d}</p>) : state.error}
        </AlertDescription>
      </Alert>
    );
  }

  return null;
}

export function PaymentMethodButton({ hasCard }: { hasCard: boolean }) {
  const [state, action, pending] = useActionState(openPaymentMethod, {});

  return (
    <form action={action}>
      <Button type="submit" variant="outline" disabled={pending}>
        {pending ? "Opening…" : hasCard ? "Replace card" : "Add a card"}
      </Button>
      <Outcome state={state} />
    </form>
  );
}

function InvoiceFields({ billing }: { billing: Billing }) {
  return (
    <div className="grid gap-3 sm:grid-cols-2">
      <div className="space-y-1.5">
        <Label htmlFor="legal_name">Legal name on invoices</Label>
        <Input id="legal_name" name="legal_name" defaultValue={billing.billing_details.legal_name ?? ""} />
      </div>
      <div className="space-y-1.5">
        <Label htmlFor="billing_email">Send invoices to</Label>
        <Input
          id="billing_email"
          name="billing_email"
          type="email"
          defaultValue={billing.billing_details.billing_email ?? ""}
        />
      </div>
    </div>
  );
}

export function CollectionForm({ billing }: { billing: Billing }) {
  const [state, action, pending] = useActionState(setCollection, {});
  const toInvoice = billing.payment_method.collection === "card";

  return (
    <form action={action} className="space-y-3">
      <input type="hidden" name="method" value={toInvoice ? "invoice" : "card"} />
      {toInvoice && <InvoiceFields billing={billing} />}
      <Button type="submit" variant="outline" disabled={pending}>
        {toInvoice ? "Switch to invoicing" : "Switch to card"}
      </Button>
      <Outcome state={state} />
    </form>
  );
}

export function ConvertTrialForm({ billing }: { billing: Billing }) {
  const [state, action, pending] = useActionState(convertTrial, {});
  const [method, setMethod] = useState<"card" | "invoice">(
    billing.payment_method.card_available ? "card" : "invoice",
  );

  return (
    <form action={action} className="space-y-4">
      <fieldset className="space-y-2">
        <legend className="text-xs font-medium text-muted-foreground">Plan</legend>
        {billing.available_plans.map((plan, index) => (
          <label key={plan.code} className="flex items-center gap-3 rounded-md border bg-card p-3 text-sm">
            <input type="radio" name="plan_code" value={plan.code} defaultChecked={index === 0} required />
            <span className="font-medium">{plan.name}</span>
            <span className="ml-auto font-mono text-xs text-muted-foreground">
              {plan.unit_price} per learner · minimum {plan.minimum}
            </span>
          </label>
        ))}
      </fieldset>

      <fieldset className="space-y-2">
        <legend className="text-xs font-medium text-muted-foreground">How you will pay</legend>
        <div className="flex gap-4 text-sm">
          {billing.payment_method.card_available && (
            <label className="flex items-center gap-2">
              <input type="radio" name="method" value="card" checked={method === "card"} onChange={() => setMethod("card")} />
              Card, charged monthly
            </label>
          )}
          <label className="flex items-center gap-2">
            <input
              type="radio"
              name="method"
              value="invoice"
              checked={method === "invoice"}
              onChange={() => setMethod("invoice")}
            />
            Invoice, paid by bank transfer
          </label>
        </div>
      </fieldset>

      {method === "invoice" && <InvoiceFields billing={billing} />}

      <Button type="submit" disabled={pending || billing.available_plans.length === 0}>
        {pending ? "Working…" : method === "card" ? "Continue to add a card" : "Start the plan"}
      </Button>
      <p className="text-xs text-muted-foreground">Everything you set up during the trial stays exactly as it is.</p>
      <Outcome state={state} />
    </form>
  );
}
