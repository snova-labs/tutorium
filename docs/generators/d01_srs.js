// Doc 01 — Software Requirements Specification
const L = require('./tlib');
const { H1, H2, H3, P, B, N, NOTE, CODE, TBL, BREAK, cover, buildDoc, save, TableOfContents, PRODUCT } = L;

const ch = [];
ch.push(...cover(
  'Software Requirements Specification',
  'Tenant application and platform control plane — functional and non-functional requirements',
  'SL-SRS-001',
  [['Parent', 'SL-PRD-000 (Product Vision) — this document specifies what SL-PRD-000 promises'],
   ['Referenced by', 'SL-ARC-002, SL-DAT-003, SL-SEC-004, SL-LOC-005, SL-BIL-006, SL-OPS-007, SL-PLN-008']]
));
ch.push(H1('Table of Contents'));
ch.push(new TableOfContents('TOC', { hyperlink: true, headingStyleRange: '1-2' }));
ch.push(BREAK());

ch.push(H1('1. About This Document'));
ch.push(H2('1.1 Purpose and structure'));
ch.push(P(`This SRS defines what ${PRODUCT} must do. It follows ISO/IEC/IEEE 29148 in spirit, adapted for incremental delivery by a small team. It is split deliberately, because a SaaS product is two products:`));
ch.push(B('**Part A — Tenant Application (§3):** what a customer\u2019s staff use every day. This is the product they pay for.'));
ch.push(B('**Part B — Platform Control Plane (§4):** what the operator uses to provision, meter, bill and support tenants. Customers never see it.'));
ch.push(P('Detailed design lives in companion documents and is referenced rather than repeated: architecture and tenancy model in **SL-ARC-002**, schema in **SL-DAT-003**, isolation and compliance in **SL-SEC-004**, calendars and locales in **SL-LOC-005**, plans and metering in **SL-BIL-006**, integrations and operations in **SL-OPS-007**, sequencing in **SL-PLN-008**.'));

ch.push(H2('1.2 Requirement notation'));
ch.push(TBL(['Element', 'Convention'], [
  ['ID', '`FR-<AREA>-<n>` for functional, `NFR-<AREA>-<n>` for non-functional. IDs are permanent; withdrawn requirements are struck, never renumbered.'],
  ['Phase tag', '**[P1]**…**[P5]** — the delivery phase from SL-PRD-000 §11. A requirement with no tag is out of current scope.'],
  ['Wording', '"must" = mandatory for the tagged phase. "may" = permitted design latitude. Acceptance criteria are given where behavior could otherwise be misread.'],
], [1400, 8346]));

ch.push(H2('1.3 Actors'));
ch.push(TBL(['Actor', 'Belongs to', 'Description'], [
  ['Platform Operator', 'snova-labs', 'Runs the service. Provisions tenants, sets plans, investigates support issues, never browses customer data casually.'],
  ['Tenant Owner', 'Customer', 'The account holder. Full authority inside their tenant, including billing and user management.'],
  ['Tenant Admin (composed role)', 'Customer', 'Management, front desk, accountant, coordinator — permissions and branch scope assigned by the Tenant Owner. Not a hard-coded type.'],
  ['Teacher', 'Customer', 'Records attendance, assesses work, writes notes, generates reports for assigned batches only.'],
  ['Learner (P5)', 'Customer', 'Adult learner viewing their own progress.'],
  ['Guardian (P5)', 'Customer', 'Parent or sponsor viewing a linked learner\u2019s progress and reports.'],
  ['System', '—', 'Scheduled jobs: session generation, metering snapshots, report runs, delivery retries.'],
], [2300, 1500, 5946]));

ch.push(H2('1.4 Terminology (canonical, before tenant overrides)'));
ch.push(TBL(['Term', 'Meaning'], [
  ['Tenant', 'One paying customer account. The isolation boundary for all data.'],
  ['Brand', 'A trading identity within a tenant: name, logo, colors, report template, sender identity, optional domain. A tenant has at least one.'],
  ['Branch', 'A location or delivery unit under a brand, with its own timezone and staff.'],
  ['Course', 'A programme of study, defining default policies for its batches.'],
  ['Batch', 'A scheduled run of a course at a branch: dates, timetable, timezone, teachers, enrolled learners. The anchor for scheduling and assessment.'],
  ['Session', 'One occurrence of a batch\u2019s timetable, or an ad-hoc class.'],
  ['Enrollment', 'The link between a learner and a batch. All academic records attach here, never to the learner directly.'],
  ['Reporting period', 'The window a report covers — month, term, quarter or custom block, defined per course.'],
  ['Assessment', 'Any gradable item, of a configurable type, under a configurable grading scheme.'],
  ['Preset', 'A bundle of defaults applied at onboarding (SL-PRD-000 §7).'],
], [2200, 7546]));
ch.push(NOTE('Every term above is renameable per tenant (FR-CFG-4). "Learner" may display as Student, Trainee or Participant; "Batch" as Class, Group or Cohort. The canonical names are used in code, the API and these documents.'));
ch.push(BREAK());

ch.push(H1('2. Overall Description'));
ch.push(H2('2.1 Product context'));
ch.push(P('A multi-tenant web application with a shared database and row-level tenant isolation (rationale in SL-ARC-002 §3), a REST API from day one, background workers for scheduled and bulk work, and swappable drivers for every external service. The same codebase runs in **cloud mode** (many tenants, metered billing) and **self-hosted mode** (one tenant, licence-key entitlements).'));
ch.push(H2('2.2 Operating assumptions'));
ch.push(B('Customers and their learners may be in any country; a single tenant may have branches in several timezones and learners in more.'));
ch.push(B('Staff are non-technical. Any workflow requiring more than one page of instructions is a design failure.'));
ch.push(B('Early infrastructure is deliberately small (one modest server). The architecture must scale out later without rework, but must not *require* managed cloud services to run (NFR-COST-1, NFR-PORT-1).'));
ch.push(B('English is the launch language, with the interface fully externalized for translation.'));
ch.push(H2('2.3 Design constraints'));
ch.push(B('All instants stored in UTC; all calendar-sensitive values additionally stored in the owning entity\u2019s local terms (SL-LOC-005).'));
ch.push(B('No business identifier format may be hard-coded; all come from configurable sequences (FR-CFG-3).'));
ch.push(B('No permission decision may test a user "type"; only named permissions (FR-IAM-2).'));
ch.push(B('Academic history is never hard-deleted by a user action; soft deletion plus an append-only audit log.'));
ch.push(B('No product name in code, schema, or namespaces (SL-PRD-000 §12 D1).'));
ch.push(BREAK());

// ============================ PART A ============================
ch.push(H1('3. Part A — Tenant Application Requirements'));

ch.push(H2('3.1 Onboarding & Presets (ONB)'));
ch.push(B('**FR-ONB-1 [P2]** A new tenant is created with a chosen preset (SL-PRD-000 §7) which seeds terminology, lookups, policies, grading scheme, period type, calendar, week start, ID sequences, report template, locale and currency.'));
ch.push(B('**FR-ONB-2 [P2]** A guided setup checklist tracks completion: brand details and logo, first branch with timezone, first course, first batch with timetable, staff invitations, first learners. Progress is visible and resumable.'));
ch.push(B('**FR-ONB-3 [P2]** Sample data may be loaded and removed in one action, so an evaluator sees a populated product without polluting real data. Sample records are clearly labelled and use neutral names ("Sample Learner").'));
ch.push(B('**FR-ONB-4 [P2]** Acceptance: a new tenant reaches a state where attendance can be recorded within 15 minutes, without support contact.'));
ch.push(B('**FR-ONB-5 [P3]** A tenant may re-apply an updated preset component (e.g., a newer report template) without losing its own customizations; conflicts are shown before applying.'));

ch.push(H2('3.2 Configuration & Terminology (CFG)'));
ch.push(B('**FR-CFG-1 [P1]** Settings resolve through the hierarchy tenant → brand → branch → course → batch. The UI shows the effective value and the level it came from, and allows overriding at any level the user may edit.'));
ch.push(B('**FR-CFG-2 [P1]** All controlled vocabularies are editable data: session types, attendance statuses, submission statuses, enrollment statuses, note categories, assessment types, relation types, custom learner fields.'));
ch.push(B('**FR-CFG-3 [P1]** ID sequences are configurable per entity and optionally per brand or branch: prefix, separator, padding width, next number, and whether the sequence is shared or per-scope. Changing a format affects only future records.'));
ch.push(B('**FR-CFG-4 [P2]** Terminology overrides: a tenant may rename canonical entities for display (learner, guardian, batch, course, session, assessment, period). Overrides apply to UI labels, emails and reports; the API keeps canonical names.'));
ch.push(B('**FR-CFG-5 [P2]** Module toggles per tenant/course: guardians, rubrics, multiple brands, meeting links, notes visibility on reports.'));
ch.push(B('**FR-CFG-6 [P1]** Every configuration change is audited with actor, before and after values.'));

ch.push(H2('3.3 Identity, Roles & Access (IAM)'));
ch.push(B('**FR-IAM-1 [P1]** Staff authenticate with email and password: hashed credentials, login throttling, password reset by email, session invalidation on password change.'));
ch.push(B('**FR-IAM-2 [P1]** Authorization is permission-based. Permissions are granular strings grouped by module (e.g., `learners.create`, `attendance.record`, `grades.edit`, `reports.send`, `settings.manage`, `billing.manage`). Roles are named permission sets, created and edited by the Tenant Owner.'));
ch.push(B('**FR-IAM-3 [P1]** Each user has a scope: all branches, or an explicit list. Teachers additionally see only batches they are assigned to. Scope is enforced server-side on every read and write, not merely hidden in the UI.'));
ch.push(B('**FR-IAM-4 [P1]** Seeded example roles (Owner, Management, Front Desk, Teacher) are fully editable and deletable — they are conveniences, not fixed types.'));
ch.push(B('**FR-IAM-5 [P2]** Staff invitations by email with expiring tokens; pending invitations are visible and revocable.'));
ch.push(B('**FR-IAM-6 [P3]** Optional TOTP two-factor authentication per user; the Tenant Owner may require it for chosen roles.'));
ch.push(B('**FR-IAM-7 [P5]** Optional SSO via Microsoft Entra ID per tenant (SL-OPS-007).'));
ch.push(B('**FR-IAM-8 [P1]** The authentication identity is separate from person records, so a guardian or learner record can later be granted a login by linking rather than duplicating (enables P5 portals without migration).'));

ch.push(H2('3.4 Organization Structure (ORG)'));
ch.push(B('**FR-ORG-1 [P1]** Manage brands: name, logo, colors, report template, email sender identity, default locale. A tenant always has at least one; the multi-brand UI stays hidden until enabled.'));
ch.push(B('**FR-ORG-2 [P1]** Manage branches under a brand: name, code, address, contacts, **IANA timezone**, week start and weekend days, active flag.'));
ch.push(B('**FR-ORG-3 [P2]** Per-branch calendar of holidays and closures; session generation skips closed dates and flags conflicts.'));
ch.push(B('**FR-ORG-4 [P2]** Branch archival preserves history and blocks new enrollments without deleting records.'));

ch.push(H2('3.5 Courses, Batches & Scheduling (SCH)'));
ch.push(B('**FR-SCH-1 [P1]** Manage courses: name, code, description, audience, default session types, default attendance policy, default grading scheme, default period type, assessment type weights. Adding a new programme is data entry.'));
ch.push(B('**FR-SCH-2 [P1]** Manage batches: course, branch, name, code, start and end dates, capacity, **timezone** (defaults to branch, overridable for online cohorts), assigned teachers, delivery mode (in-person, online, hybrid).'));
ch.push(B('**FR-SCH-3 [P1]** Weekly timetable per batch (weekday, start time, duration, session type), from which sessions are generated for a chosen range. Generation is idempotent and reports what it created, skipped and conflicted.'));
ch.push(B('**FR-SCH-4 [P1]** Individual sessions may be added, rescheduled or cancelled with a reason; cancellations retain history and never silently delete attendance.'));
ch.push(B('**FR-SCH-5 [P2]** Batch cloning for the next intake, copying timetable, policies and assessments (without learners or grades).'));
ch.push(B('**FR-SCH-6 [P2]** A session may carry a meeting URL; the field is manual until the Teams driver exists (FR-MS-4).'));
ch.push(B('**FR-SCH-7 [P1]** Acceptance: a batch in a timezone with daylight saving generates sessions whose UTC instants shift correctly across the DST boundary, while local clock times stay constant.'));

ch.push(H2('3.6 Learners, Guardians & Enrollment (PPL)'));
ch.push(B('**FR-PPL-1 [P1]** Learner record: identifier from sequence, legal name, preferred name, date of birth, gender (optional, configurable list), country, home timezone, contact details, photo, status with reason and date, tenant-defined custom fields.'));
ch.push(B('**FR-PPL-2 [P1]** Guardian records are first-class and many-to-many with learners: name, relation, emails, phone, preferred channel, locale, timezone, per-link flags for primary contact and "receives reports". The whole guardian module is disableable for adult-learner tenants (FR-CFG-5).'));
ch.push(B('**FR-PPL-3 [P1]** Enrollment links a learner to a batch with its own status history (Active, Completed, Transferred, Withdrawn, On hold) and reasons. Transfers preserve prior attendance and grades against the original enrollment.'));
ch.push(B('**FR-PPL-4 [P1]** Duplicate detection on create — matching name plus guardian email or phone — with an explicit override path.'));
ch.push(B('**FR-PPL-5 [P2]** Bulk import of learners and guardians from `.xlsx`/CSV: downloadable template, validation preview showing rejects with row numbers and reasons, transactional commit, reconciliation summary.'));
ch.push(B('**FR-PPL-6 [P2]** Every list view exports to `.xlsx` honoring current filters and column selection.'));
ch.push(B('**FR-PPL-7 [P3]** Employer/sponsor records linkable to enrollments, as an alternative report recipient for corporate training tenants.'));

ch.push(H2('3.7 Attendance (ATT)'));
ch.push(B('**FR-ATT-1 [P1]** Attendance is recorded per session per enrolled learner using the tenant\u2019s configurable status set (seeded Present, Late, Absent, Excused), each status carrying flags for whether it counts as attended and whether it is negative.'));
ch.push(B('**FR-ATT-2 [P1]** Attendance policy resolves through course → batch: attendance compulsory or informational; late join allowed with grace minutes; which session types count toward the headline percentage; low-attendance threshold.'));
ch.push(B('**FR-ATT-3 [P1]** Bulk grid entry marks a whole roster in one screen and one transactional save; re-submitting updates existing marks rather than duplicating.'));
ch.push(B('**FR-ATT-4 [P1]** Per-record notes and per-record minutes-late where relevant.'));
ch.push(B('**FR-ATT-5 [P1]** The attendance grid must be usable on a phone-sized screen — teachers frequently mark from the classroom.'));
ch.push(B('**FR-ATT-6 [P2]** Summary views by session, learner and period; make-up session linking; low-attendance alerts against the configured threshold.'));
ch.push(B('**FR-ATT-7 [P5]** Import of attendance from Microsoft Teams meeting reports for online sessions.'));
ch.push(B('**FR-ATT-8 [P1]** Acceptance: with late-join allowed, a "Late" mark counts as attended in the percentage while remaining separately visible; with attendance set to informational, no percentage penalty is applied anywhere.'));

ch.push(H2('3.8 Assessments & Grading (GRD)'));
ch.push(P('Grading is a configurable engine, not a fixed schema. Nothing about "homework out of 100" may be assumed.'));
ch.push(B('**FR-GRD-1 [P1]** Assessment types are tenant data (seeded per preset, e.g., Homework, Classwork, Project, Quiz, Lab), each with a display color, a flag for inclusion in submission-rate metrics, and an optional default weight.'));
ch.push(B('**FR-GRD-2 [P1]** Grading schemes: **points** (configurable maximum), **percentage**, **pass/fail**, **letter** (tenant-defined bands), **rubric** (named criteria with individual maxima, total computed), and **level** (e.g., CEFR A1–C2) for language schools. Each assessment selects a scheme.'));
ch.push(B('**FR-GRD-3 [P1]** Every graded result is normalized to a comparable 0–100 value for analytics; the normalization rule belongs to the scheme, so adding a scheme never changes reporting code. Exempt results are excluded from denominators, not scored as zero.'));
ch.push(B('**FR-GRD-4 [P1]** Assessment record: type, scheme, title, description, assigned date, due date in batch timezone, published flag, and identifier from sequence.'));
ch.push(B('**FR-GRD-5 [P1]** Grade grid (learners × assessments) supports score entry, submission status (seeded Submitted, Late, Missing, Exempt — configurable), and per-cell feedback, saved transactionally with clear conflict handling.'));
ch.push(B('**FR-GRD-6 [P1]** Period aggregation: weighted by assessment type where weights are defined, simple mean otherwise. The rule in force must be visible to the user, not implicit.'));
ch.push(B('**FR-GRD-7 [P2]** Grade change history surfaced on the record: previous value, who changed it, when.'));
ch.push(B('**FR-GRD-8 [P3]** Excel round-trip: export the grade grid, edit offline, re-import with validation preview.'));
ch.push(B('**FR-GRD-9 [P1]** Acceptance: a batch mixing a rubric project (weight 40%), points homework (40%) and a pass/fail quiz (20%) produces a period average matching an independent hand calculation, with Exempt rows excluded.'));

ch.push(H2('3.9 Notes & Observations (NTE)'));
ch.push(B('**FR-NTE-1 [P1]** Notes attach to an enrollment for a reporting period, with a configurable category and a flag controlling whether they appear on reports. Internal-only notes are never rendered to recipients.'));
ch.push(B('**FR-NTE-2 [P1]** Bulk note entry for a whole batch on one screen.'));
ch.push(B('**FR-NTE-3 [P2]** Reusable note templates with placeholders (e.g., learner preferred name), tenant-managed.'));

ch.push(H2('3.10 Reporting & Delivery (RPT)'));
ch.push(B('**FR-RPT-1 [P1]** Generate a progress report per enrollment per reporting period as a PDF, containing brand identity, period identification, attendance summary honoring the batch policy, assessment summary by type, period average under the active scheme, auto-generated highlights, report-visible notes, and a configurable closing and footer.'));
ch.push(B('**FR-RPT-2 [P1]** Report templates are configurable per course/brand: which blocks appear, their order, wording tone, and language. Previews always render neutral sample data ("Sample Learner").'));
ch.push(B('**FR-RPT-3 [P1]** Bulk generation for a batch runs in the background with visible progress; a single failure never aborts the run, and failures are individually retryable.'));
ch.push(B('**FR-RPT-4 [P1]** Delivery to all flagged recipients (guardians, or the learner, or a sponsor) by email with the PDF attached. Each delivery is a tracked record with status (Queued, Sent, Failed, Bounced), provider reference and error text.'));
ch.push(B('**FR-RPT-5 [P1]** Generated reports are stored and re-downloadable; the numbers shown are snapshotted at generation time so a report never silently changes after it was sent.'));
ch.push(B('**FR-RPT-6 [P2]** Report archive with filters by brand, branch, batch, period, learner and delivery status; bulk resend.'));
ch.push(B('**FR-RPT-7 [P2]** Scheduled report runs: automatically generate and (optionally) send at period end, with a review window before dispatch.'));
ch.push(B('**FR-RPT-8 [P5]** Secure web view of a report via tokenized link, as an alternative to the PDF attachment.'));

ch.push(H2('3.11 Dashboards & Analytics (DSH)'));
ch.push(B('**FR-DSH-1 [P1]** Role-scoped home dashboard: today\u2019s and upcoming sessions in the viewer\u2019s timezone with the batch timezone labelled, active learner count, attendance for the current period, pending grading, recent activity.'));
ch.push(B('**FR-DSH-2 [P1]** Batch overview: per-learner attendance percentage, submission rate, period average, missing count, with drill-down to a learner detail view showing trend across the last six periods.'));
ch.push(B('**FR-DSH-3 [P2]** Brand and tenant rollups for management roles; every table exportable to `.xlsx`.'));
ch.push(B('**FR-DSH-4 [P3]** At-risk learner list driven by configurable thresholds (attendance, missing assessments, declining average).'));

ch.push(H2('3.12 Audit & History (AUD)'));
ch.push(B('**FR-AUD-1 [P1]** Every create, update and delete of domain data writes an append-only audit entry: actor, UTC timestamp, tenant, module, action, target, and changed field values before and after. Bulk operations and imports are audited per affected record.'));
ch.push(B('**FR-AUD-2 [P1]** Audit views: tenant-wide for admins, and filtered per learner, per batch and per user. "Last edited by" is surfaced on records.'));
ch.push(B('**FR-AUD-3 [P2]** Operator access to tenant data (impersonation, support lookups) is written to the tenant-visible audit log — customers must be able to see when we looked.'));
ch.push(B('**FR-AUD-4 [P3]** Configurable retention and export of audit history.'));

ch.push(H2('3.13 Notifications (NTF)'));
ch.push(B('**FR-NTF-1 [P1]** Transactional email templates (invitation, password reset, report delivery) with editable subject and body, placeholder variables, and per-brand sender identity.'));
ch.push(B('**FR-NTF-2 [P2]** Staff notifications in-app and by optional digest: low attendance, ungraded assessments before a scheduled report run, failed deliveries.'));
ch.push(B('**FR-NTF-3 [P3]** Recipient-facing reminders (session changes, upcoming reports) rendered in the recipient\u2019s timezone and language.'));

ch.push(H2('3.14 Data Portability (DAT)'));
ch.push(B('**FR-DAT-1 [P2]** A tenant may export their full dataset (structured files plus generated PDFs) on demand — this is both a trust feature and a compliance obligation.'));
ch.push(B('**FR-DAT-2 [P2]** Learner and guardian import (FR-PPL-5) is the supported path for bringing existing records in from spreadsheets.'));
ch.push(B('**FR-DAT-3 [P3]** Deletion of a learner or guardian on request, with an audit record of the deletion and configurable handling of dependent academic history.'));
ch.push(BREAK());

// ============================ PART B ============================
ch.push(H1('4. Part B — Platform Control Plane Requirements'));
ch.push(P('Operator-facing capability, invisible to tenants. In self-hosted deployments this plane is absent and its entitlement decisions come from a licence key instead (FR-LIC-1).'));

ch.push(H2('4.1 Tenant Lifecycle (TEN)'));
ch.push(B('**FR-TEN-1 [P2]** Create a tenant with name, preset, plan, region, primary contact and initial owner user; provisioning is a single operation that leaves the tenant usable.'));
ch.push(B('**FR-TEN-2 [P2]** Tenant states: Trial, Active, Past due, Suspended, Cancelled, Purged. Each state defines exactly what tenant users can still do (e.g., Suspended = read-only and export allowed).'));
ch.push(B('**FR-TEN-3 [P2]** Tenant directory with search, health indicators (last activity, learner count, error rate) and quick actions.'));
ch.push(B('**FR-TEN-4 [P3]** Cancellation flow: export offered, retention window before purge, purge is irreversible and audited.'));
ch.push(B('**FR-TEN-5 [P2]** Each tenant carries a region attribute governing where its data and files reside; a single region is deployed initially and additional regions require no schema change.'));

ch.push(H2('4.2 Plans & Entitlements (ENT)'));
ch.push(B('**FR-ENT-1 [P3]** Plans are data: name, price, billing interval, included limits (learners, branches, brands, staff), and feature flags (API access, custom domain, SSO, Microsoft integrations).'));
ch.push(B('**FR-ENT-2 [P3]** Entitlement checks are read through one interface. In cloud mode it resolves from the tenant\u2019s subscription; in self-hosted mode from a signed licence file. No feature check may query the billing tables directly.'));
ch.push(B('**FR-ENT-3 [P3]** Limit behavior is configurable per limit: soft (warn and allow) or hard (block with a clear upgrade path). Blocking must never risk data loss or trap a user mid-task.'));
ch.push(B('**FR-ENT-4 [P3]** Per-tenant overrides for negotiated deals, with a reason and an audit entry.'));

ch.push(H2('4.3 Metering & Usage (MTR)'));
ch.push(B('**FR-MTR-1 [P3]** A daily job snapshots each tenant\u2019s active learner count per the definition in SL-PRD-000 §6.1; snapshots are immutable and retained for audit.'));
ch.push(B('**FR-MTR-2 [P3]** The billable quantity for a period is the peak daily snapshot within that period.'));
ch.push(B('**FR-MTR-3 [P3]** Tenants see a live usage meter: current count, period peak, projected invoice, and a downloadable usage history.'));
ch.push(B('**FR-MTR-4 [P3]** Operators can inspect and, with a recorded reason, correct a snapshot; corrections are audited and surfaced on the invoice.'));

ch.push(H2('4.4 Billing (BIL)'));
ch.push(B('**FR-BIL-1 [P3]** Payment providers are drivers behind one interface. **Stripe** is the launch driver; **manual/offline invoicing** ships alongside it; a merchant-of-record driver is specified but deferred (SL-BIL-006).'));
ch.push(B('**FR-BIL-2 [P3]** Subscription lifecycle: trial with end date, conversion, upgrade and downgrade with proration, cancellation at period end, reactivation.'));
ch.push(B('**FR-BIL-3 [P3]** Dunning: retry schedule on failed payment, escalating notifications, then Past due and Suspended states — never silent data loss.'));
ch.push(B('**FR-BIL-4 [P3]** Invoices carry tenant billing details, tax identifiers and the metered quantity with its calculation basis; tax handling is configurable per jurisdiction.'));
ch.push(B('**FR-BIL-5 [P4]** Self-service billing portal for tenants: payment method, invoices, plan changes.'));

ch.push(H2('4.5 Operator Console & Support (OPS)'));
ch.push(B('**FR-OPS-1 [P2]** Operator authentication is separate from tenant authentication, with mandatory two-factor from the first release.'));
ch.push(B('**FR-OPS-2 [P2]** Impersonation for support requires a stated reason, is time-limited, is written to both operator and tenant audit logs, and is visibly indicated in the interface while active.'));
ch.push(B('**FR-OPS-3 [P2]** Operational visibility: failed jobs, delivery failures, error rates and provisioning status per tenant.'));
ch.push(B('**FR-OPS-4 [P3]** Announcement and maintenance banners targetable to all tenants or a subset.'));
ch.push(B('**FR-OPS-5 [P4]** Self-serve signup with email verification, subdomain allocation, and preset selection; custom domain support with automated certificate issuance.'));

ch.push(H2('4.6 Self-Hosted Licensing (LIC)'));
ch.push(B('**FR-LIC-1 [P5]** A signed licence file conveys tenant name, learner cap, enabled features and expiry; the application validates it offline and degrades to read-only after a grace period on expiry.'));
ch.push(B('**FR-LIC-2 [P5]** Optional, consent-based telemetry reports version and usage counts for support and renewal; the product must function fully with telemetry disabled.'));
ch.push(B('**FR-LIC-3 [P5]** Packaged release artifacts, upgrade procedure and rollback path are documented and versioned (SL-OPS-007).'));
ch.push(BREAK());

// ============================ MICROSOFT ============================
ch.push(H1('5. Microsoft Ecosystem Requirements (MS)'));
ch.push(P('Customers in the target segment overwhelmingly run Microsoft 365. The product is Microsoft-friendly from launch and Microsoft-native where it adds real value. Technical design in SL-OPS-007.'));
ch.push(TBL(['ID', 'Requirement', 'Launch behavior', 'Microsoft-native (P5)'], [
  ['FR-MS-1 [P1]', 'Email delivery for all mail', 'SMTP driver; per-brand sender identity', 'Microsoft Graph sendMail from the tenant\u2019s own mailbox'],
  ['FR-MS-2 [P1]', 'Excel export on every list and grid; Excel import for learners and grades', 'Native — no Microsoft dependency', 'Unchanged'],
  ['FR-MS-3 [P2]', 'File storage for PDFs and uploads', 'S3-compatible object storage', 'Optional SharePoint/OneDrive destination via Graph'],
  ['FR-MS-4 [P5]', 'Online session meetings', 'Manual meeting URL per session', 'Teams meeting created per session; join link stored'],
  ['FR-MS-5 [P5]', 'Staff calendars', 'Read-only iCal feed per teacher (subscribes into Outlook)', 'Outlook calendar events via Graph'],
  ['FR-MS-6 [P5]', 'Parent/learner meeting booking', 'Configurable external booking link on reports', 'Microsoft Bookings integration'],
  ['FR-MS-7 [P5]', 'Staff sign-in', 'Email and password', 'Entra ID SSO per tenant, with admin consent'],
], [1200, 3000, 2900, 2646]));
ch.push(NOTE('Because each customer has their own Microsoft 365 tenant, Graph integration requires a multi-tenant application registration with per-customer admin consent and per-tenant token storage — not a single set of credentials. This is a P5 design constraint, recorded now so nothing built earlier contradicts it.'));
ch.push(BREAK());

// ============================ NFR ============================
ch.push(H1('6. Non-Functional Requirements'));
ch.push(H2('6.1 Performance & scale'));
ch.push(B('**NFR-PERF-1 [P1]** Interactive pages respond within 500 ms server time at the reference load: 200 tenants, 20,000 total learners, 200 concurrent staff.'));
ch.push(B('**NFR-PERF-2 [P1]** Attendance and grade grids for a 40-learner batch load within 1 second and save within 2 seconds.'));
ch.push(B('**NFR-PERF-3 [P1]** Bulk report generation for a 40-learner batch completes within 5 minutes on background workers without blocking the interface.'));
ch.push(B('**NFR-PERF-4 [P2]** No tenant\u2019s workload may degrade another\u2019s: heavy jobs are queued with fair scheduling and per-tenant concurrency limits.'));

ch.push(H2('6.2 Tenancy & isolation'));
ch.push(B('**NFR-ISO-1 [P1]** Every tenant-owned table carries a tenant reference; every query is scoped automatically, with a failing test proving cross-tenant access is impossible for each resource.'));
ch.push(B('**NFR-ISO-2 [P1]** Scoping is enforced at the data layer, not by the presentation layer, and re-checked in authorization policies (defense in depth).'));
ch.push(B('**NFR-ISO-3 [P2]** Uploaded files and generated PDFs are stored under tenant-scoped paths and served only through authorized, expiring links.'));

ch.push(H2('6.3 Time, calendar and localization'));
ch.push(B('**NFR-TZ-1 [P1]** Instants are stored in UTC and converted only at input parsing and output rendering. Every displayed time states its zone where ambiguity is possible.'));
ch.push(B('**NFR-TZ-2 [P1]** Reporting period boundaries are computed in the batch timezone, so a late-evening session cannot fall into the wrong period.'));
ch.push(B('**NFR-LOC-1 [P2]** Calendar system, week start, weekend days, date and number formats, currency and language are configurable per tenant/branch (SL-LOC-005).'));
ch.push(B('**NFR-LOC-2 [P2]** All user-facing strings are externalized; the interface must tolerate translated strings up to twice English length and support right-to-left layout without redesign.'));

ch.push(H2('6.4 Security, privacy & compliance'));
ch.push(B('**NFR-SEC-1 [P1]** OWASP ASVS Level 1 baseline: parameterized queries only, CSRF protection, output encoding, secure session cookies, rate limiting on authentication, dependency scanning in CI.'));
ch.push(B('**NFR-SEC-2 [P1]** Minors\u2019 data is handled with least privilege: no learner personal data in URLs, logs or error reports; encrypted backups; access to a learner requires an explicit scope grant.'));
ch.push(B('**NFR-SEC-3 [P2]** Data subject rights are supported operationally: export, correction and erasure requests can be fulfilled by a tenant admin without operator intervention.'));
ch.push(B('**NFR-SEC-4 [P3]** Contractual and documentary requirements — processing agreement, sub-processor list, retention schedule, breach notification procedure — are maintained as product artifacts (SL-SEC-004).'));
ch.push(B('**NFR-SEC-5 [P1]** Secrets live only in environment configuration; no credential is ever committed.'));

ch.push(H2('6.5 Reliability & operations'));
ch.push(B('**NFR-REL-1 [P1]** Automated daily database backups with a tested restore procedure; file storage retains versions.'));
ch.push(B('**NFR-REL-2 [P2]** Health endpoint, structured logs, error tracking, and queue failures visible and retryable without shell access.'));
ch.push(B('**NFR-REL-3 [P3]** Target availability 99.5% initially; published status page.'));
ch.push(B('**NFR-REL-4 [P1]** All schema migrations are reversible within a release and never require downtime for the deployment sizes in scope.'));

ch.push(H2('6.6 Portability & cost'));
ch.push(B('**NFR-PORT-1 [P1]** No dependency on a proprietary managed service. Storage is S3-compatible, mail is SMTP-capable, queues and cache work with commodity components. This preserves both the self-hosted product and negotiating leverage with hosting vendors.'));
ch.push(B('**NFR-PORT-2 [P5]** A self-hosted installation reaches a running state from documented artifacts within one hour, with no outbound internet dependency at runtime.'));
ch.push(B('**NFR-COST-1 [P1]** The complete stack must run on a single modest server (2 vCPU / 4 GB) for the first tenants: heavyweight components are optional and configuration-selected, with lighter defaults (SL-ARC-002 §11).'));
ch.push(B('**NFR-COST-2 [P3]** Infrastructure cost per active learner per month stays under 2% of list price at 1,000 learners.'));

ch.push(H2('6.7 Maintainability'));
ch.push(B('**NFR-MNT-1 [P1]** The layering rules and extension points in SL-ARC-002 are enforced in review; business logic never lives in controllers or UI components.'));
ch.push(B('**NFR-MNT-2 [P1]** Automated checks gate every change: formatting, static analysis, and tests covering each phase\u2019s requirements plus authorization denials and tenant-isolation cases.'));
ch.push(B('**NFR-MNT-3 [P1]** Architectural decisions are recorded as dated decision records in the repository.'));
ch.push(BREAK());

ch.push(H1('7. Acceptance Criteria by Phase'));
ch.push(H2('7.1 P1 — Academic core'));
ch.push(N('An operator provisions two tenants; a user of tenant A cannot read or write any record of tenant B by any route, proven by automated tests per resource.'));
ch.push(N('A tenant admin creates a brand, a branch in a daylight-saving timezone, a course, and a batch with a timetable; generated sessions hold constant local times across the DST boundary.'));
ch.push(N('A teacher records attendance for a session on a phone-sized screen in under a minute, and the configured late-join policy is reflected in the percentage.'));
ch.push(N('A batch with rubric, points and pass/fail assessments produces a weighted period average matching a hand calculation, with Exempt rows excluded.'));
ch.push(N('Progress reports for a batch generate as branded PDFs, store durably, and deliver by email to every flagged recipient; a forced failure is visible and retryable.'));
ch.push(N('A role built from permissions can manage learners but is refused settings access, and the refusal appears in the audit log.'));
ch.push(N('The entire stack runs on a 2 vCPU / 4 GB server while doing all of the above.'));
ch.push(H2('7.2 P2 — Tenant-ready'));
ch.push(N('A new tenant created from each launch preset reaches "attendance recordable" within 15 minutes with no support contact.'));
ch.push(N('Renaming "Learner" to "Trainee" changes every UI label, email and report, and nothing in the API contract.'));
ch.push(N('An operator impersonates a tenant user with a stated reason; the tenant sees that access in their own audit log.'));
ch.push(N('A tenant exports their complete dataset and the export opens without special tooling.'));
ch.push(H2('7.3 P3 — Commercial'));
ch.push(N('The daily metering snapshot matches a manually counted roster for a tenant with mid-period joiners and withdrawals; the invoice uses the period peak.'));
ch.push(N('A tenant subscribes by card, upgrades mid-period with correct proration, fails a payment, receives dunning notices, and is suspended read-only with export still available.'));
ch.push(N('An institutional customer is invoiced manually and activated without touching the card flow.'));
ch.push(N('A feature gated by plan is unavailable on the lower plan and available immediately after upgrade, with the check reading only the entitlement interface.'));

ch.push(H1('8. Traceability & Change Control'));
ch.push(P('Each requirement traces forward to a design section, a test, and a delivery item in SL-PLN-008. New requirements are added with an ID, a phase tag, and a pointer to any affected data structure in SL-DAT-003. Requirements are never silently reworded once implementation has started; material changes get a new ID and a note on the superseded one.'));

const doc = buildDoc(ch, 'SL-SRS-001 · Software Requirements Specification · snova-labs');
save(doc, '/home/claude/tut-docs/out/01_Software_Requirements_Specification.docx');
