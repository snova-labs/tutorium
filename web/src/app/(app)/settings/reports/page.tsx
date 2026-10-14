import type { Metadata } from "next";
import Link from "next/link";
import { redirect } from "next/navigation";

import { TemplateForm } from "@/app/(app)/settings/reports/template-form";
import { SetupSection } from "@/app/(app)/batches/[id]/setup-section";
import { PageHeader } from "@/components/app/page-header";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { api, type Paginated } from "@/lib/api";
import { getMe, getTerms } from "@/lib/me";
import { can } from "@/lib/permissions";
import { appliesTo, blockSummary, matchesNothing } from "@/lib/report-templates";
import type { Brand, CourseSummary, ReportTemplate } from "@/lib/types";

export const metadata: Metadata = { title: "What reports contain" };

interface TemplateList {
  data: ReportTemplate[];
  meta: { blocks: Record<string, string> };
}

export default async function ReportTemplatesPage() {
  const [me, terms] = await Promise.all([getMe(), getTerms()]);

  if (!can(me, "settings.manage")) {
    redirect("/settings");
  }

  const [templates, brands, courses] = await Promise.all([
    api<TemplateList>("report-templates"),
    api<Paginated<Brand>>("brands").then((r) => r.data),
    api<Paginated<CourseSummary>>("courses").then((r) => r.data),
  ]);

  const courseNoun = terms.course.singular;
  const brandChoices = brands.map((brand) => ({ id: brand.id, name: brand.name }));

  return (
    <>
      <PageHeader
        title="What reports contain"
        description="Which sections a report carries, and which reports each template answers for. Reports already sent keep the sections they were sent with."
        actions={
          <Button asChild variant="ghost" size="sm">
            <Link href="/settings">Settings</Link>
          </Button>
        }
      />

      <div className="space-y-6">
        {templates.data.map((template) => (
          <Card key={template.id}>
            <CardHeader>
              <CardTitle className="flex flex-wrap items-center gap-2">
                {template.name}
                {template.is_default && <Badge variant="secondary">Default</Badge>}
                {matchesNothing(template) && <Badge variant="outline">Answers for nothing</Badge>}
              </CardTitle>
              <CardDescription>
                {appliesTo(template, courseNoun)} · {blockSummary(template.blocks, templates.meta.blocks)}
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <Button asChild variant="outline" size="sm">
                {/* Opened rather than embedded: it is a page of its own, rendered from sample data. */}
                <Link href={`/settings/reports/${template.id}/preview`} target="_blank" rel="noreferrer">
                  See a sample
                </Link>
              </Button>
              <SetupSection initiallyOpen={false} label="Change this template">
                <TemplateForm
                  template={template}
                  blocks={templates.meta.blocks}
                  brands={brandChoices}
                  courses={courses}
                  courseNoun={courseNoun}
                />
              </SetupSection>
            </CardContent>
          </Card>
        ))}

        <Card>
          <CardHeader>
            <CardTitle>Another template</CardTitle>
            <CardDescription>
              For a {courseNoun.toLowerCase()} that should report differently: a certification course without engagement
              stars, say, or a short report for a trial class.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <TemplateForm blocks={templates.meta.blocks} brands={brandChoices} courses={courses} courseNoun={courseNoun} />
          </CardContent>
        </Card>
      </div>
    </>
  );
}
