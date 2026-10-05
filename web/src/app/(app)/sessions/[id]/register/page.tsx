import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";

import { RegisterForm } from "@/app/(app)/sessions/[id]/register/register-form";
import { PageHeader } from "@/components/app/page-header";
import { ProvenanceChip } from "@/components/app/provenance-chip";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { api } from "@/lib/api";
import { isApiError } from "@/lib/api-error";
import { getMe } from "@/lib/me";
import { can } from "@/lib/permissions";
import { policyProvenance } from "@/lib/register";
import { formatLocalDate } from "@/lib/time";
import type { Register } from "@/lib/types";

export const metadata: Metadata = { title: "Register" };

export default async function RegisterPage({ params }: PageProps<"/sessions/[id]/register">) {
  const { id } = await params;

  if (!/^\d+$/.test(id)) {
    notFound();
  }

  let register: Register;

  try {
    register = (await api<{ data: Register }>(`sessions/${id}/attendance`)).data;
  } catch (error) {
    if (isApiError(error) && (error.status === 404 || error.status === 403)) {
      notFound();
    }

    throw error;
  }

  const me = await getMe();
  const { session, policy } = register;

  const rule = (key: string, value: string) => {
    const origin = policyProvenance(policy.origins[key]);

    return (
      <ProvenanceChip
        value={value}
        inherited={origin.inherited}
        inheritedFrom={origin.from}
        setOn="this batch"
        mono={false}
      />
    );
  };

  return (
    <>
      <PageHeader
        title={session.batch.name}
        description={`${session.type} · ${formatLocalDate(session.local.date)} ${session.local.time} (${session.local.timezone})`}
        actions={
          <Link href={`/batches/${session.batch.id}`} className="text-sm text-muted-foreground hover:underline">
            Back to batch
          </Link>
        }
      />

      {/* The rules are above the register on purpose: someone choosing between Late and Absent
          should see what the system will make of either, and where that rule was set. */}
      <div className="mb-4 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
        <span>Rules for this batch:</span>
        {rule("late_grace_min", `${policy.late_grace_min} min grace`)}
        {rule("allow_late_join", policy.allow_late_join ? "Late counts as attended" : "Late does not count")}
        {rule("is_compulsory", policy.is_compulsory ? "Attendance compulsory" : "Attendance optional")}
        {rule("low_threshold_pct", `Flag below ${policy.low_threshold_pct}%`)}
      </div>

      {!register.counts_in_rate && (
        <Alert className="mb-4">
          <AlertDescription>This type of session is recorded but does not count toward attendance rates.</AlertDescription>
        </Alert>
      )}

      {session.status === "cancelled" && (
        <Alert variant="warn" className="mb-4">
          <AlertDescription>This session was cancelled.</AlertDescription>
        </Alert>
      )}

      <RegisterForm
        key={register.roster.map((r) => `${r.enrollment_id}:${r.status_id}:${r.minutes_late}`).join(",")}
        register={register}
        canRecord={can(me, "attendance.record")}
      />
    </>
  );
}
