import type { Metadata } from "next";
import { redirect } from "next/navigation";

import { ActionButton } from "@/app/(app)/settings/action-button";
import { applyPreset, loadSampleData, removeSampleData, reopenSetupGuide } from "@/app/(app)/settings/actions";
import { TerminologyForm } from "@/app/(app)/settings/terminology-form";
import { PageHeader } from "@/components/app/page-header";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { api } from "@/lib/api";
import { getMe } from "@/lib/me";
import { can } from "@/lib/permissions";
import type { Onboarding, Preset, Terms } from "@/lib/types";

export const metadata: Metadata = { title: "Settings" };

export default async function SettingsPage() {
  const me = await getMe();

  if (!can(me, "settings.manage")) {
    redirect("/");
  }

  const [terminology, presets, onboarding] = await Promise.all([
    api<{ data: Terms; meta: { defaults: Terms } }>("terminology"),
    api<{ data: Preset[] }>("presets").then((r) => r.data),
    api<{ data: Onboarding }>("onboarding").then((r) => r.data),
  ]);

  return (
    <>
      <PageHeader title="Settings" description="What things are called, and the defaults your academy starts from." />

      <div className="space-y-6">
        <Card>
          <CardHeader>
            <CardTitle>Wording</CardTitle>
            <CardDescription>
              Call things what your academy calls them. Only the words change: links, exports and integrations keep working.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <TerminologyForm terms={terminology.data} defaults={terminology.meta.defaults} />
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Presets</CardTitle>
            <CardDescription>
              A preset adds statuses, grading scales and wording suited to a kind of academy. Applying one again is safe: anything you
              have already changed is left as you left it.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <ul className="grid gap-4 md:grid-cols-2">
              {presets.map((preset) => (
                <li key={preset.code} className="flex flex-col rounded-lg border p-4">
                  <div className="flex items-start justify-between gap-2">
                    <div className="font-medium">{preset.name}</div>
                    {onboarding.preset === preset.code && <Badge variant="secondary">In use</Badge>}
                  </div>
                  {preset.summary && <p className="mt-1 text-sm text-muted-foreground">{preset.summary}</p>}
                  <dl className="mt-3 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
                    {Object.entries(preset.sets).map(([label, value]) => (
                      <div key={label} className="contents">
                        <dt className="text-muted-foreground">{label}</dt>
                        <dd>{value || "—"}</dd>
                      </div>
                    ))}
                  </dl>
                  <div className="mt-auto pt-4">
                    <ActionButton
                      size="sm"
                      variant="outline"
                      run={applyPreset.bind(null, preset.code)}
                      confirm={`Add what “${preset.name}” sets? Nothing you have already changed is overwritten.`}
                      pendingLabel="Applying…"
                    >
                      {onboarding.preset === preset.code ? "Apply again" : "Apply"}
                    </ActionButton>
                  </div>
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Getting started</CardTitle>
            <CardDescription>
              Setup guide: {onboarding.done} of {onboarding.total} steps done
              {onboarding.dismissed ? ", hidden from the home page." : "."}
            </CardDescription>
          </CardHeader>
          <CardContent className="flex flex-wrap gap-2">
            {onboarding.dismissed && (
              <ActionButton size="sm" variant="outline" run={reopenSetupGuide}>
                Show the setup guide again
              </ActionButton>
            )}
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
                Load a sample class to try things out
              </ActionButton>
            )}
          </CardContent>
        </Card>
      </div>
    </>
  );
}
