"use client";

import { useTransition } from "react";
import { toast } from "sonner";

import { reinvite, resend, revoke, type InviteState } from "@/app/(app)/team/actions";
import { Button } from "@/components/ui/button";
import { invitationActions } from "@/lib/people";
import type { Invitation } from "@/lib/types";

export function InvitationActions({ invitation }: { invitation: Invitation }) {
  const [pending, start] = useTransition();
  const allowed = invitationActions(invitation.status);

  const run = (action: () => Promise<InviteState>) =>
    start(async () => {
      const result = await action();
      (result.ok ? toast.success : toast.error)(result.message ?? "Done.");
    });

  return (
    <div className="flex justify-end gap-2">
      {allowed.resend && (
        <Button size="sm" variant="outline" disabled={pending} onClick={() => run(() => resend(invitation.id))}>
          Send again
        </Button>
      )}
      {allowed.reinvite && (
        <Button size="sm" variant="outline" disabled={pending} onClick={() => run(() => reinvite(invitation.email, invitation.role))}>
          Invite again
        </Button>
      )}
      {allowed.revoke && (
        <Button size="sm" variant="ghost" disabled={pending} onClick={() => run(() => revoke(invitation.id))}>
          Withdraw
        </Button>
      )}
    </div>
  );
}
