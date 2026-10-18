import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";

import { PageHeader } from "@/components/app/page-header";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { api } from "@/lib/api";
import { getMe, getTerms } from "@/lib/me";
import { can } from "@/lib/permissions";
import type { Usage, UsageInvoice } from "@/lib/types";
import { formatLocalDateShort, formatLocalRange } from "@/lib/time";
import {
  dailyBars,
  entitlementValue,
  hasFallen,
  inPlanOrder,
  invoiceTone,
  licenceNotice,
  meterHeadline,
  outstanding,
} from "@/lib/usage";

export const metadata: Metadata = { title: "What you are billed for" };

export default async function UsagePage() {
  const [me, terms] = await Promise.all([getMe(), getTerms()]);

  if (!can(me, "billing.manage") && !can(me, "dashboard.tenant")) {
    notFound();
  }

  // Invoices are for whoever handles the money; the meter is for anyone who runs the academy.
  const seesInvoices = can(me, "billing.manage");
  const [{ data: usage }, invoices] = await Promise.all([
    api<{ data: Usage }>("usage"),
    seesInvoices ? api<{ data: UsageInvoice[] }>("usage/invoices").then((r) => r.data) : Promise.resolve([]),
  ]);

  const learners = terms.learner.plural.toLowerCase();
  const bars = dailyBars(usage.meter);
  const owed = outstanding(invoices);
  const licence = licenceNotice(usage.licence);

  return (
    <>
      <PageHeader
        title="What you are billed for"
        description={`The ${learners} your plan counts, as they are counted. Nothing here waits for an invoice to tell you.`}
        actions={
          can(me, "billing.manage") ? (
            <Button asChild variant="ghost" size="sm">
              <Link href="/billing">Billing</Link>
            </Button>
          ) : undefined
        }
      />

      <div className="space-y-6">
        {/* Self-hosted only. A cap that fills up has to say so while there is still time to do
            something about it — not on the day an enrollment is refused. */}
        {licence && (
          <Card className={licence.tone === "bad" ? "border-bad" : undefined}>
            <CardHeader>
              <CardTitle className="text-base">
                {licence.tone === "bad" ? "Your licence needs attention" : "About your licence"}
              </CardTitle>
              <CardDescription>{licence.message}</CardDescription>
            </CardHeader>
            {usage.licence?.licence && (
              <CardContent className="text-xs text-muted-foreground">
                Licence {usage.licence.licence.id} for {usage.licence.licence.licensee} ·{" "}
                {usage.licence.licence.max_learners === null
                  ? "unlimited learners"
                  : `${usage.licence.learners_now ?? 0} of ${usage.licence.licence.max_learners} learner places in use`}{" "}
                {/* "Runs to" a date in the past reads as a mistake, so an expired licence says it expired. */}
                · {usage.licence.licence.lapsed || usage.licence.licence.in_grace ? "expired" : "runs to"}{" "}
                {formatLocalDateShort(usage.licence.licence.expires_on)}
              </CardContent>
            )}
          </Card>
        )}

        <Card>
          <CardHeader>
            <CardTitle>This period</CardTitle>
            <CardDescription>
              {formatLocalRange(usage.meter.period.start, usage.meter.period.end)} · measured through{" "}
              {formatLocalDateShort(usage.meter.measured_through)}
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div>
              <p className="text-3xl font-semibold tabular-nums">{usage.meter.will_be_billed_for}</p>
              <p className="text-sm">{meterHeadline(usage.meter)}</p>
              <p className="mt-1 text-xs text-muted-foreground">{usage.meter.basis}</p>
            </div>

            {hasFallen(usage.meter) && (
              <p className="text-xs text-muted-foreground">
                The figure has come down since its peak. This period still bills at the peak; next period starts again
                from its own highest day.
              </p>
            )}

            {bars.length > 0 && (
              <div>
                {/* Deliberately not a charting library: one number a day, and the shape is the point. */}
                <ul className="flex h-24 items-end gap-0.5" aria-hidden>
                  {bars.map((day) => (
                    <li
                      key={day.date}
                      title={`${formatLocalDateShort(day.date)}: ${day.count}${day.corrected ? " (corrected)" : ""}`}
                      style={{ height: `${day.percent}%` }}
                      className={`flex-1 rounded-sm ${day.corrected ? "bg-muted-foreground" : "bg-primary"}`}
                    />
                  ))}
                </ul>
                <p className="mt-2 text-xs text-muted-foreground">
                  One bar a day, {formatLocalDateShort(bars[0].date)} to{" "}
                  {formatLocalDateShort(bars[bars.length - 1].date)}. A grey bar is a day whose count was corrected
                  afterwards.
                </p>
              </div>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>What counts</CardTitle>
            <CardDescription>
              Which {learners} the figure above includes. The rules are the same ones the invoice uses.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <p className="text-sm font-medium">Counted</p>
                <p className="text-sm text-muted-foreground">{usage.what_counts.counted.join(", ") || "None"}</p>
              </div>
              <div>
                <p className="text-sm font-medium">Not counted</p>
                <p className="text-sm text-muted-foreground">{usage.what_counts.not_counted.join(", ") || "None"}</p>
              </div>
            </div>
            <ul className="list-disc space-y-1 pl-5 text-sm text-muted-foreground">
              {usage.what_counts.rules.map((rule) => (
                <li key={rule}>{rule}</li>
              ))}
            </ul>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="flex flex-wrap items-center gap-2">
              {/* A self-hosted install has no plan and never will: the licence is the plan. */}
              {usage.plan.name ?? (usage.licence?.licence ? "Self-hosted licence" : "No plan")}
              {usage.plan.status && <Badge variant="secondary">{usage.plan.status}</Badge>}
            </CardTitle>
            <CardDescription>What the plan allows, and where each answer comes from: {usage.plan.source}.</CardDescription>
          </CardHeader>
          <CardContent>
            <ul className="divide-y rounded-md border">
              {inPlanOrder(usage.plan.entitlements).map((entitlement) => (
                <li key={entitlement.key} className="flex flex-wrap items-baseline justify-between gap-2 p-3">
                  <div>
                    <p className="text-sm font-medium">{entitlement.key}</p>
                    {entitlement.reason && <p className="text-xs text-muted-foreground">{entitlement.reason}</p>}
                  </div>
                  <div className="text-right">
                    <p className="text-sm tabular-nums">{entitlementValue(entitlement.value)}</p>
                    <p className="text-xs text-muted-foreground">
                      {entitlement.source}
                      {entitlement.enforcement === "soft" && " · not enforced"}
                    </p>
                  </div>
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>

        {/* A self-hosted install is licensed annually, not metered monthly: there are no invoices
            here to be settled or outstanding, and an empty card saying so is a lie by layout. */}
        {seesInvoices && usage.licence === null && (
          <Card>
            <CardHeader>
              <CardTitle>Invoices</CardTitle>
              <CardDescription>
                {owed.length === 0
                  ? "Every invoice is settled."
                  : `${owed.length} ${owed.length === 1 ? "invoice is" : "invoices are"} still open.`}
              </CardDescription>
            </CardHeader>
            <CardContent>
              {invoices.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                  Nothing invoiced yet. The first one covers the period you are in now.
                </p>
              ) : (
                <ul className="divide-y rounded-md border">
                  {invoices.map((item) => (
                    <li key={item.number} className="flex flex-wrap items-baseline justify-between gap-2 p-3">
                      <div>
                        <p className="text-sm font-medium">
                          {item.number}
                          <Badge
                            variant={invoiceTone(item.status) === "muted" ? "outline" : "secondary"}
                            className={`ml-2 ${invoiceTone(item.status) === "bad" ? "text-bad" : ""}`}
                          >
                            {item.status}
                          </Badge>
                        </p>
                        <p className="text-xs text-muted-foreground">
                          {formatLocalRange(item.period.start, item.period.end)}
                          {/* The day the quantity came from, so a figure can always be traced. */}
                          {item.basis_date &&
                            ` · Peak of ${item.quantity} on ${formatLocalDateShort(item.basis_date)}`}
                        </p>
                        {item.notes && <p className="text-xs text-muted-foreground">{item.notes}</p>}
                      </div>
                      <p className="text-sm tabular-nums">{item.total}</p>
                    </li>
                  ))}
                </ul>
              )}
            </CardContent>
          </Card>
        )}
      </div>
    </>
  );
}
