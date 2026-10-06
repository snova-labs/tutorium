import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { InvitationActions } from "@/app/(app)/team/invitation-actions";
import { InviteForm } from "@/app/(app)/team/invite-form";
import { PageHeader } from "@/components/app/page-header";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { api } from "@/lib/api";
import { getMe } from "@/lib/me";
import { invitationStatusLabel } from "@/lib/people";
import { can } from "@/lib/permissions";
import { formatLocalDate } from "@/lib/time";
import type { Invitation } from "@/lib/types";

export const metadata: Metadata = { title: "Team" };

export default async function TeamPage() {
  const me = await getMe();

  if (!can(me, "users.manage")) {
    notFound();
  }

  const { data } = await api<{ data: { invitations: Invitation[]; roles_you_can_grant: string[] } }>("invitations");

  return (
    <>
      <PageHeader title="Team" description="Invite colleagues. An invitation grants nothing until it is accepted." />

      <div className="grid gap-6 lg:grid-cols-[1fr_20rem]">
        <Card className="py-0">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="pl-4">Email</TableHead>
                <TableHead>Role</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>Expires</TableHead>
                <TableHead className="pr-4" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.invitations.length === 0 && (
                <TableRow>
                  <TableCell colSpan={5} className="py-10 text-center text-muted-foreground">
                    No invitations yet.
                  </TableCell>
                </TableRow>
              )}
              {data.invitations.map((invitation) => (
                <TableRow key={invitation.id}>
                  <TableCell className="pl-4">
                    <div className="font-medium">{invitation.email}</div>
                    {invitation.invited_by && <div className="text-xs text-muted-foreground">Invited by {invitation.invited_by}</div>}
                  </TableCell>
                  <TableCell>{invitation.role}</TableCell>
                  <TableCell>
                    <Badge variant={invitation.status === "accepted" ? "secondary" : "outline"}>{invitationStatusLabel(invitation.status)}</Badge>
                  </TableCell>
                  <TableCell className="text-sm text-muted-foreground">
                    {invitation.status === "pending" || invitation.status === "expired" ? formatLocalDate(invitation.expires_on) : "—"}
                  </TableCell>
                  <TableCell className="pr-4">
                    <InvitationActions invitation={invitation} />
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Card>

        <Card className="h-fit">
          <CardHeader>
            <CardTitle>Invite someone</CardTitle>
            <CardDescription>They get an email with a link to set a password. The link works for 7 days.</CardDescription>
          </CardHeader>
          <CardContent>
            <InviteForm roles={data.roles_you_can_grant} />
          </CardContent>
        </Card>
      </div>
    </>
  );
}
