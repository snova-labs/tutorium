import type { Metadata } from "next";
import Link from "next/link";
import { redirect } from "next/navigation";

import { SettingRow } from "@/app/(app)/settings/behaviour/setting-row";
import { PageHeader } from "@/components/app/page-header";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { api } from "@/lib/api";
import { getMe } from "@/lib/me";
import { can } from "@/lib/permissions";
import type { SettingGroup } from "@/lib/types";

export const metadata: Metadata = { title: "How the academy behaves" };

export default async function BehaviourPage() {
  if (!can(await getMe(), "settings.manage")) {
    redirect("/settings");
  }

  const { data: groups, meta } = await api<{ data: SettingGroup[]; meta: { roles: string[] } }>("settings");

  return (
    <>
      <PageHeader
        title="How the academy behaves"
        description="Each of these says what the product does when nobody says otherwise, and where that answer came from."
        actions={
          <Button asChild variant="ghost" size="sm">
            <Link href="/settings">Settings</Link>
          </Button>
        }
      />

      <div className="space-y-6">
        {groups.map((group) => (
          <Card key={group.key}>
            <CardHeader>
              <CardTitle>{group.name}</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="divide-y rounded-md border">
                {group.settings.map((setting) => (
                  <SettingRow key={setting.key} setting={setting} roles={meta.roles} />
                ))}
              </div>
            </CardContent>
          </Card>
        ))}

        <p className="text-sm text-muted-foreground">
          A week start and a weekend are not here: they belong to a location, which is where a weekend is actually
          decided. They are on each location under Academy.
        </p>
      </div>
    </>
  );
}
