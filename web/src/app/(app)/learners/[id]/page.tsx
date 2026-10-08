import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";

import { EnrolForm } from "@/app/(app)/learners/[id]/enrol-form";
import { EnrollmentControls } from "@/app/(app)/learners/[id]/enrollment-controls";
import { GuardiansPanel } from "@/app/(app)/learners/[id]/guardians-panel";
import { PageHeader } from "@/components/app/page-header";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { api, type Paginated } from "@/lib/api";
import { isApiError } from "@/lib/api-error";
import { getMe, getTerms } from "@/lib/me";
import { attendanceSummary, hasReportRecipient, percent, sortEnrollments } from "@/lib/people";
import { can } from "@/lib/permissions";
import { formatLocalDate } from "@/lib/time";
import type { AttendanceRate, Batch, EnrollmentStatusOption, LearnerDetail, PeriodAverage } from "@/lib/types";

export const metadata: Metadata = { title: "Learner" };

interface Progress {
  period: string;
  attendance: AttendanceRate | null;
  average: PeriodAverage | null;
}

export default async function LearnerPage({ params }: PageProps<"/learners/[id]">) {
  const { id } = await params;

  if (!/^\d+$/.test(id)) {
    notFound();
  }

  let learner: LearnerDetail;

  try {
    learner = (await api<{ data: LearnerDetail }>(`learners/${id}`)).data;
  } catch (error) {
    // Another academy's learner reads as not found, exactly as the API intends.
    if (isApiError(error) && (error.status === 404 || error.status === 403)) {
      notFound();
    }

    throw error;
  }

  const [me, terms] = await Promise.all([getMe(), getTerms()]);
  const enrollments = sortEnrollments(learner.enrollments);
  const running = enrollments.filter((e) => e.ended_on === null);

  // This period's figures for each class the learner is in, side by side with the class.
  const progress = new Map<number, Progress>(
    await Promise.all(
      running.map(async (enrollment): Promise<[number, Progress]> => {
        const [attendance, average] = await Promise.all([
          can(me, "attendance.view")
            ? api<{ data: { period: { label: string }; attendance: AttendanceRate } }>(`enrollments/${enrollment.id}/attendance`).then(
                (r) => r.data,
              )
            : null,
          can(me, "grades.view")
            ? api<{ data: { period: { label: string }; average: PeriodAverage } }>(`enrollments/${enrollment.id}/average`).then((r) => r.data)
            : null,
        ]);

        return [
          enrollment.id,
          {
            period: attendance?.period.label ?? average?.period.label ?? "",
            attendance: attendance?.attendance ?? null,
            average: average?.average ?? null,
          },
        ];
      }),
    ),
  );

  const enrolledIn = new Set(running.map((e) => e.batch?.id));
  const manages = can(me, "enrollments.manage");
  const [openBatches, statuses] = manages
    ? await Promise.all([
        api<Paginated<Batch>>("batches").then((r) =>
          r.data
            .filter((b) => b.accepts_enrollments !== false && !enrolledIn.has(b.id))
            .map((b) => ({ id: b.id, label: [b.name, b.course?.name].filter(Boolean).join(" · ") })),
        ),
        api<{ data: EnrollmentStatusOption[] }>("enrollment-statuses").then((r) => r.data),
      ])
    : [[], []];

  return (
    <>
      <PageHeader
        title={learner.name.display}
        description={[learner.number, learner.name.preferred && learner.name.legal !== learner.name.display ? learner.name.legal : null]
          .filter(Boolean)
          .join(" · ")}
        actions={
          <>
            {learner.status.name && <Badge variant="secondary">{learner.status.name}</Badge>}
            <Button asChild variant="ghost" size="sm">
              <Link href="/learners">All {terms.learner.plural.toLowerCase()}</Link>
            </Button>
          </>
        }
      />

      {!hasReportRecipient(learner) && (
        <Alert variant="warn" className="mb-6">
          <AlertDescription>
            Nobody receives this learner&apos;s reports yet. Add a guardian who receives them, or an email for an adult learner.
          </AlertDescription>
        </Alert>
      )}

      <div className="grid gap-6 lg:grid-cols-[1fr_22rem]">
        <div className="space-y-6">
          <Card>
            <CardHeader>
              <CardTitle>Classes</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              {enrollments.length === 0 && <p className="text-sm text-muted-foreground">Not enrolled in any batch yet.</p>}
              <ul className="divide-y">
                {enrollments.map((enrollment) => {
                  const figures = progress.get(enrollment.id);

                  return (
                    <li key={enrollment.id} className="py-3 first:pt-0">
                      <div className="flex flex-wrap items-center justify-between gap-2">
                        <div>
                          {enrollment.batch ? (
                            <Link href={`/batches/${enrollment.batch.id}`} className="font-medium hover:underline">
                              {enrollment.batch.name}
                            </Link>
                          ) : (
                            <span className="font-medium">{enrollment.number}</span>
                          )}
                          <div className="text-xs text-muted-foreground">
                            Since {formatLocalDate(enrollment.enrolled_on)}
                            {enrollment.ended_on && `, until ${formatLocalDate(enrollment.ended_on)}`}
                          </div>
                        </div>
                        <Badge variant={enrollment.ended_on ? "outline" : "secondary"}>{enrollment.status.name ?? "—"}</Badge>
                      </div>
                      {enrollment.status.reason && <p className="mt-1 text-xs text-muted-foreground">{enrollment.status.reason}</p>}
                      {figures && (figures.attendance || figures.average) && (
                        <dl className="mt-2 grid grid-cols-2 gap-2 text-sm">
                          {figures.attendance && (
                            <div>
                              <dt className="text-xs text-muted-foreground">Attendance, {figures.period}</dt>
                              <dd>{attendanceSummary(figures.attendance)}</dd>
                            </div>
                          )}
                          {figures.average && (
                            <div>
                              <dt className="text-xs text-muted-foreground">Average, {figures.period}</dt>
                              <dd title={figures.average.explanation}>
                                {percent(figures.average.percentage)}
                                {figures.average.ungraded > 0 && (
                                  <span className="text-muted-foreground">, {figures.average.ungraded} not graded</span>
                                )}
                              </dd>
                            </div>
                          )}
                        </dl>
                      )}
                      {manages && enrollment.ended_on === null && (
                        <EnrollmentControls
                          learnerId={learner.id}
                          enrollmentId={enrollment.id}
                          currentStatusId={enrollment.status.id}
                          statuses={statuses}
                          batches={openBatches}
                          batchNoun={terms.batch.singular.toLowerCase()}
                        />
                      )}
                    </li>
                  );
                })}
              </ul>
              {manages && <EnrolForm learnerId={learner.id} batches={openBatches} />}
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Guardians</CardTitle>
            </CardHeader>
            <CardContent>
              <GuardiansPanel learnerId={learner.id} guardians={learner.guardians} canManage={can(me, "guardians.manage")} />
            </CardContent>
          </Card>
        </div>

        <Card className="h-fit">
          <CardHeader>
            <CardTitle>Details</CardTitle>
          </CardHeader>
          <CardContent>
            <dl className="space-y-3 text-sm">
              <Detail label="Date of birth" value={learner.date_of_birth ? formatLocalDate(learner.date_of_birth) : null} />
              <Detail label="Email" value={learner.contact.email} />
              <Detail label="Phone" value={learner.contact.phone} />
              <Detail label="Country" value={learner.country} />
              <Detail label="Home timezone" value={learner.home_timezone} />
              {learner.status.reason && <Detail label="Status reason" value={learner.status.reason} />}
            </dl>
          </CardContent>
        </Card>
      </div>
    </>
  );
}

function Detail({ label, value }: { label: string; value: string | null }) {
  return (
    <div>
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd>{value ?? "—"}</dd>
    </div>
  );
}
