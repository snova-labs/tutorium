// Doc 07 — Integrations & DevOps ; Doc 08 — Roadmap & Delivery Plan
const L = require('./tlib');
const { H1, H2, H3, P, B, N, NOTE, CODE, TBL, BREAK, cover, buildDoc, save, TableOfContents, PRODUCT } = L;

// ============================== DOC 07 ==============================
let ch = [];
ch.push(...cover(
  'Integrations & DevOps Specification',
  'Provider drivers, Microsoft 365 architecture, email deliverability, environments, CI/CD and operations',
  'SL-OPS-007',
  [['Parent', 'SL-SRS-001 §5 (Microsoft), §6.5–6.6 · SL-ARC-002 §2, §10, §11']]
));
ch.push(H1('Table of Contents'));
ch.push(new TableOfContents('TOC', { hyperlink: true, headingStyleRange: '1-2' }));
ch.push(BREAK());

ch.push(H1('1. Environments'));
ch.push(TBL(['Environment', 'Purpose', 'Composition'], [
  ['Local', 'Development; full stack offline, no cloud credentials required', 'Docker Compose: app, web server, MySQL, mail catcher, object storage, worker, scheduler (Redis and n8n optional)'],
  ['Staging', 'Demos and pre-release verification; production-like configuration with non-production data', 'Same compose file on a small server, production drivers, seeded demo tenant'],
  ['Production (cloud)', 'Live multi-tenant service', 'One modest server initially (SL-ARC-002 §11), scaling out on the documented ladder'],
  ['Self-hosted (P5)', 'Customer-operated single-tenant install', 'Released artifacts, customer-supplied database, storage and SMTP'],
], [1700, 3100, 4946]));
ch.push(H2('1.1 Local stack'));
ch.push(...CODE([
  'services:',
  '  app        # PHP 8.3-FPM + composer, mounts source',
  '  web        # :8080 → app',
  '  mysql      # MySQL 8, persistent volume',
  '  worker     # queue worker (database driver by default)',
  '  scheduler  # runs the scheduler loop (session generation, metering)',
  '  mailpit    # SMTP :1025, UI :8025 — catches ALL outbound mail',
  '  silo       # S3-compatible object storage + console (MinIO fork)',
  '  redis      # optional profile: enabled when testing at load',
  '  n8n        # optional profile: email-dispatch flow parity',
  '',
  '# a developer needs: docker compose up, then migrate + seed.',
  '# No cloud account, no credentials, no external service.',
]));
ch.push(NOTE('Rule: the entire product — including PDF generation and report email — must be exercisable offline on a laptop. Anything that can only be tested against a paid service will end up untested.'));

ch.push(H1('2. Provider Drivers'));
ch.push(TBL(['Interface', 'Local', 'Cloud production', 'Microsoft-native (P5)', 'Self-hosted'], [
  ['MailProvider', 'Mail catcher (SMTP)', 'SMTP relay, or n8n workflow', 'Graph `sendMail` from the tenant mailbox', 'Customer SMTP or their M365'],
  ['FileStorage', 'Silo (MinIO fork) / local disk', 'S3-compatible object storage', 'SharePoint/OneDrive via Graph (optional destination)', 'Local disk or customer storage'],
  ['PdfRenderer', 'PHP-native', 'PHP-native → headless browser when layout demands', 'Unchanged', 'PHP-native (no browser dependency)'],
  ['PaymentProvider', 'Stub', 'Stripe + manual invoicing', 'Unchanged', 'Not used — licence file'],
  ['Entitlements', 'Subscription', 'Subscription', 'Subscription', 'Signed licence file'],
  ['MeetingProvider', 'Manual link', 'Manual link', 'Teams meeting per session', 'Manual or customer Teams'],
  ['CalendarProvider', 'iCal feed', 'iCal feed', 'Outlook events via Graph', 'iCal feed'],
], [1600, 1900, 2400, 2300, 1546]));
ch.push(P('A driver is one class plus configuration. No business logic knows which driver is active — the report service asks for delivery, not for Stripe or Graph.'));
ch.push(BREAK());

ch.push(H1('3. Email Architecture & Deliverability'));
ch.push(P('Progress reports are the product\u2019s output; if they land in spam the product has failed. Deliverability is therefore an architectural concern, not an operational afterthought.'));
ch.push(H2('3.1 Sending model'));
ch.push(TBL(['Model', 'How it works', 'When to use'], [
  ['Platform sending (default)', 'Mail sent from a platform subdomain with the brand as display name and the brand address as reply-to. SPF, DKIM and DMARC are ours to maintain.', 'Every tenant at signup — zero setup, works immediately'],
  ['Branded domain', 'Tenant adds DNS records for a sending subdomain of their own domain; mail is then authenticated as them.', 'Growth-plan tenants who want reports to come "from" their school'],
  ['Tenant mailbox (P5)', 'Graph sends from the tenant\u2019s own Microsoft 365 mailbox after admin consent; the message appears in their Sent Items.', 'Microsoft-committed customers; best possible deliverability and auditability'],
], [1700, 4600, 3446]));
ch.push(H2('3.2 Protecting the shared reputation'));
ch.push(B('Separate sending streams for transactional platform mail (invitations, resets) and tenant bulk mail (report batches), so one tenant\u2019s bounce rate cannot poison password resets.'));
ch.push(B('Per-tenant sending limits and rate control; report runs are queued and paced rather than dispatched in a burst.'));
ch.push(B('Bounce and complaint handling is mandatory: hard bounces mark the recipient address invalid and surface it to the tenant for correction; repeated complaints suspend that tenant\u2019s sending pending review.'));
ch.push(B('Trial tenants have tighter limits — this is the standard abuse vector for a free tier.'));
ch.push(B('Every delivery attempt is a tracked record with provider reference and error (SL-DAT-003 §10), so "the parent says they never got it" is answerable with evidence.'));
ch.push(H2('3.3 The n8n option'));
ch.push(B('An n8n workflow can sit between the application and the mail provider: the app posts a signed payload, n8n fetches attachments, sends, and calls back a status webhook. It buys retry logic and provider switching without deploys.'));
ch.push(B('Assessment: useful when experimenting with providers or adding non-email channels, and an unnecessary moving part otherwise. Recommendation is to ship with direct SMTP, keep the n8n driver available, and adopt it only when a workflow need appears.'));

ch.push(H1('4. File Storage'));
ch.push(B('Path layout: `tenants/{tenant}/brands/{brand}/reports/{period}/{report_number}.pdf`, with uploads under a parallel prefix. Tenant is always the first path segment, which makes per-tenant export, lifecycle rules and deletion trivial.'));
ch.push(B('All buckets private; downloads only through short-lived signed links issued after authorization.'));
ch.push(B('Lifecycle: current period hot, older periods transitioned to cheaper storage, retention per SL-SEC-004 §9. Versioning enabled to survive accidental overwrites.'));
ch.push(B('The same filesystem abstraction serves local disk, S3-compatible storage and Azure — required for the self-hosted product and for keeping hosting negotiable.'));

ch.push(H1('5. Microsoft 365 Integration'));
ch.push(H2('5.1 The critical constraint'));
ch.push(NOTE('Every customer has their **own** Microsoft 365 tenant. Integration therefore requires a **multi-tenant application registration** with per-customer admin consent and per-customer token storage — not one set of credentials. Anything designed around a single app-only credential would have to be rebuilt, which is why this is recorded before any Microsoft work starts.'));
ch.push(H2('5.2 Consent and token flow'));
ch.push(...CODE([
  '1. Tenant admin clicks "Connect Microsoft 365" in settings',
  '2. Redirect to Microsoft admin consent for our multi-tenant app,',
  '   requesting only the scopes for the features they enabled',
  '3. Microsoft returns their directory (tenant) id → stored against',
  '   our tenant record with the granted scope set',
  '4. Tokens acquired per customer directory, cached encrypted with',
  '   early refresh; refresh failures raise a reconnect prompt',
  '5. Disconnect revokes locally and instructs how to revoke consent',
]));
ch.push(H2('5.3 Capability map'));
ch.push(TBL(['Capability', 'Graph surface', 'Scope shape', 'Product behavior'], [
  ['Send reports and notifications', '`sendMail`', 'Application, restricted to a chosen mailbox where possible', 'Mail appears in the school\u2019s own Sent Items; replies reach them directly'],
  ['Teams meetings per session', '`onlineMeetings` / calendar event with online meeting', 'Delegated or application with policy', 'Join link stored on the session; cancellation cancels the meeting'],
  ['Teams attendance import', 'meeting attendance reports', 'Application', 'Pre-fills the attendance grid for online sessions; teacher confirms'],
  ['Staff calendars', 'calendar events', 'Delegated per teacher', 'Timetable appears in Outlook; iCal feed remains the no-consent fallback'],
  ['Bookings for parent meetings', 'Bookings business + appointments', 'Application', 'Link surfaced on reports; later, appointments visible in-app'],
  ['Staff sign-in', 'Entra ID SSO (OIDC)', 'Standard sign-in', 'Per-tenant SSO; local passwords disableable'],
  ['Excel', '— (native export/import)', 'None', 'Available from day one, no Microsoft dependency'],
], [1900, 2000, 2400, 3446]));
ch.push(B('Scope discipline: request the minimum scopes for the features the tenant actually enabled. A consent screen listing broad permissions is the single most common reason an IT administrator refuses an integration.'));
ch.push(B('Every Microsoft-backed capability keeps its non-Microsoft fallback permanently — customers on Google or on nothing must remain first-class.'));
ch.push(BREAK());

ch.push(H1('6. API'));
ch.push(B('Versioned under `/api/v1`; authentication by token for machine clients and cookie-based session for first-party clients.'));
ch.push(B('Conventions: consistent envelope (`data`, `meta`, `errors`), cursor pagination, ISO-8601 UTC timestamps with `*_local` companions and explicit timezone names, and an error taxonomy of stable machine codes with human messages.'));
ch.push(B('Tenant context derives from the authenticated credential, never from a request parameter.'));
ch.push(B('Rate limits per token and per tenant; limits and remaining quota returned in headers.'));
ch.push(B('Breaking changes require a new version; additive changes ship within a version. The API is a contract from the moment the first portal or customer integration exists.'));

ch.push(H1('7. CI/CD'));
ch.push(...CODE([
  'on push / pull request:',
  '  1. install (cached)          4. tests (unit + feature + isolation)',
  '  2. code formatting check     5. migration up → down → up',
  '  3. static analysis           6. dependency + secret scanning',
  '',
  'on merge to main:',
  '  build image → push registry → deploy staging → smoke tests',
  '',
  'on tag:',
  '  deploy production: pull image, run migrations, swap container,',
  '  health check, automatic rollback on failure',
]));
ch.push(B('Migrations run as a separate step before the swap, and are written to be compatible with the outgoing release so a rollback never faces a schema it cannot read.'));
ch.push(B('The isolation test suite (SL-SEC-004 §3.2) is a required check — a red isolation test blocks deployment unconditionally.'));

ch.push(H1('8. Scaling Ladder'));
ch.push(TBL(['Stage', 'Trigger', 'Change', 'Rough cost'], [
  ['S0 — Single server', 'Building; first tenants', 'App, database, worker, storage on one small machine', 'A few dollars a month'],
  ['S1 — Split storage', 'Backups or file volume grow', 'Object storage moves to a bucket', '+ small'],
  ['S2 — Split database', 'CPU contention or backup windows', 'Managed or separate database host; Redis introduced', '+ moderate'],
  ['S3 — Split workers', 'Report runs delay interactive work', 'Dedicated worker host; queue prioritization', '+ moderate'],
  ['S4 — Horizontal app', 'Concurrency or availability targets', 'Multiple app containers behind a load balancer; sessions and cache in Redis', '+ meaningful'],
  ['S5 — Second region', 'Data residency or latency demands it', 'Region record activated; tenants pinned; storage and database per region', 'Duplicated stack'],
], [1800, 2300, 3600, 2046]));
ch.push(P('Each step is triggered by a measurement, not by anticipation. The architecture supports S5 from the first migration; the infrastructure spend follows revenue.'));

ch.push(H1('9. Observability & Operations'));
ch.push(B('Health endpoint covering database, queue and storage reachability, monitored externally.'));
ch.push(B('Structured JSON logs with request and tenant identifiers — never personal data (SL-SEC-004 §7.2).'));
ch.push(B('Error tracking with scrubbing rules; alerts on error-rate spikes, queue depth, failed report deliveries and failed metering runs.'));
ch.push(B('Operator console surfaces failed jobs and delivery failures with one-click retry, so routine recovery needs no shell access.'));
ch.push(B('Backups nightly, encrypted, retained 30 daily and 12 monthly, with a **scheduled restore rehearsal** — the restore is the control, not the backup.'));
ch.push(B('Runbooks maintained in the repository: restore from backup, rotate credentials, replay webhooks, re-run metering for a date, resend a failed report batch, suspend or restore a tenant, respond to a suspected breach.'));

ch.push(H1('10. Self-Hosted Packaging (P5)'));
ch.push(B('Deliverables: a versioned compose bundle, a container image, an install guide, an upgrade guide with rollback, and a licence file.'));
ch.push(B('Customer supplies database, storage location and SMTP; no outbound internet requirement at runtime.'));
ch.push(B('Upgrades are version-sequenced with migrations tested against the previous release; the install guide states the supported upgrade path explicitly.'));
ch.push(B('Support boundary published: we support the application, the customer supports their infrastructure.'));

let doc = buildDoc(ch, 'SL-OPS-007 · Integrations & DevOps Specification · snova-labs');
save(doc, '/home/claude/tut-docs/out/07_Integrations_and_DevOps_Specification.docx');

// ============================== DOC 08 ==============================
ch = [];
ch.push(...cover(
  'Roadmap & Delivery Plan',
  'Phased delivery, effort estimates, milestones, scope control, budget and risk',
  'SL-PLN-008',
  [['Parent', 'SL-PRD-000 §11 (phases) · SL-SRS-001 (phase-tagged requirements)']]
));
ch.push(H1('Table of Contents'));
ch.push(new TableOfContents('TOC', { hyperlink: true, headingStyleRange: '1-2' }));
ch.push(BREAK());

ch.push(H1('1. Approach'));
ch.push(B('**Vertical slices.** Each work item delivers something demonstrable end to end rather than a layer that cannot be shown.'));
ch.push(B('**Phase gates, not deadlines.** A phase ends when its acceptance criteria in SL-SRS-001 §7 pass, not when a date arrives. Dates below are estimates for planning, not commitments.'));
ch.push(B('**Tenancy from commit one.** Every table and query is tenant-scoped from the beginning; retrofitting multi-tenancy is the most expensive avoidable mistake available.'));
ch.push(B('**Sell before automating.** Manual onboarding through P2–P3 teaches what self-serve must do; building signup first would automate guesses.'));
ch.push(B('**Cheap until it must not be.** One small server carries the product through the first customers (SL-ARC-002 §11); infrastructure spend follows revenue.'));

ch.push(H1('2. Capability Mapping'));
ch.push(P('Single developer (**snova**), working from a background in production PHP/Laravel systems, multi-tenant SaaS, relational modeling, cloud and container operations, and workflow automation.'));
ch.push(TBL(['Capability', 'Level', 'Where it carries the plan'], [
  ['PHP / Laravel — services, queues, policies, migrations', 'Primary strength', 'The entire domain layer; P1 and P2 rest on this'],
  ['Relational modeling / MySQL', 'Strong', 'Schema, indexing, metering correctness'],
  ['Admin panel tooling (Filament)', 'Strong', 'CRUD-heavy modules delivered in hours rather than days'],
  ['React / Next.js', 'Strong', 'Not required until P5 portals — deliberately unused earlier'],
  ['Docker, CI/CD, cloud operations', 'Working', 'Confined to P1 week 1 and P5 packaging, both with fallbacks'],
  ['Payments and billing systems', 'Working', 'P3; the highest-unknown area, so estimated with the widest range'],
  ['Workflow automation (n8n)', 'Working', 'Optional email driver only — never on the critical path'],
], [3200, 1600, 4946]));
ch.push(NOTE('Honest read: the academic core sits squarely in the strongest zone and is low-risk. The genuine unknowns are billing correctness (P3) and Microsoft consent flows (P5). Both are isolated behind interfaces so that difficulty there cannot contaminate the working product.'));

ch.push(H1('3. Phase Plan'));
ch.push(P('Estimates assume focused full-time work by one developer and include testing and documentation. Ranges are honest, not padded — the wider the range, the less certain the work.'));
ch.push(TBL(['Phase', 'Scope', 'Estimate', 'Outcome'], [
  ['P1 — Academic core', 'Tenancy foundation, identity and RBAC, org hierarchy, settings resolver, ID sequences, courses/batches/timetable/sessions, people and enrollment, attendance, dynamic grading, notes, periods, reports and delivery, dashboards, audit', '3–4 weeks', 'A real training centre could run on it — with manual setup'],
  ['P2 — Tenant-ready', 'Presets, guided onboarding, terminology overrides, per-brand branding and email, Excel import/export, operator console with impersonation, tenant export', '1.5–2 weeks', 'A customer can be onboarded in 15 minutes and supported properly'],
  ['P3 — Commercial', 'Plans and entitlements, metering, Stripe plus manual invoicing, trials, dunning, usage meter, legal and security artifacts', '2–3 weeks', 'Money can be taken from a stranger safely'],
  ['P4 — Self-serve', 'Public signup, subdomains, custom domains with certificates, billing portal, in-product onboarding, marketing site', '2–3 weeks', 'Acquisition without a conversation'],
  ['P5 — Distribution', 'On-premises packaging and licensing, Microsoft drivers (mail, Teams, calendar, SSO), learner and guardian portals', '4–6 weeks', 'Enterprise and channel reach'],
], [1500, 4300, 1300, 2646]));
ch.push(P('**Cumulative to first revenue (P1–P3): roughly 7–9 weeks.** The earlier one-week figure belonged to a single-institute internal tool; a multi-tenant commercial product is a different undertaking, and saying so now is cheaper than discovering it in week two.'));
ch.push(BREAK());

ch.push(H1('4. P1 Breakdown'));
ch.push(TBL(['Week', 'Focus', 'Deliverables', 'Gate'], [
  ['W1', 'Foundation', 'Repository, local stack, framework and panel setup, tenancy scaffolding with the isolation test harness, identity and RBAC, brands and branches, settings resolver, ID sequences', 'Two tenants exist; the isolation suite is green; a composed role is denied settings'],
  ['W2', 'Academic structure', 'Courses, batches, timetable, session generation with timezone handling, holidays, period service and reporting periods, learners, guardians, enrollments with status history', 'A DST-crossing batch generates correct sessions; a learner is enrolled and audited'],
  ['W3', 'Teaching workflow', 'Attendance policies and mobile-first grid, assessment types and grading schemes, assessments, grade grid, weighted aggregation with unit tests, teacher notes', 'Mixed-scheme weighted average matches a hand calculation; late-join policy verified'],
  ['W4', 'Output', 'Report templates and PDF rendering, storage, bulk runs with partial-failure handling, delivery tracking and retries, dashboards, audit views, hardening', 'Batch reports generate and deliver; a forced failure is visible and retryable'],
], [700, 1500, 5300, 2246]));

ch.push(H1('5. Milestones'));
ch.push(TBL(['Milestone', 'When', 'Go / no-go question'], [
  ['M1 — Isolation proven', 'End W1', 'Can a user of one tenant reach another\u2019s data by any route? Must be no, with tests.'],
  ['M2 — Structure usable', 'End W2', 'Can a real batch and roster be entered for a real month?'],
  ['M3 — Teachers could use it', 'End W3', 'Would a teacher run this week\u2019s class on it?'],
  ['M4 — Reports out', 'End P1', 'Did every intended recipient get a correct, branded report?'],
  ['M5 — Onboarding works', 'End P2', 'Can a new customer reach "attendance recorded" in 15 minutes unaided?'],
  ['M6 — Revenue safe', 'End P3', 'Does the invoice match a hand-counted roster, and is the paperwork in place?'],
  ['M7 — Self-serve', 'End P4', 'Can a stranger sign up, trial, pay and succeed with no human involvement?'],
], [2000, 1100, 6646]));

ch.push(H1('6. Design Partner Programme'));
ch.push(B('Recruit 3–5 centres across the three verticals during P1, ideally within travelling distance — the first customers are won by trust, not by a landing page.'));
ch.push(B('Offer: free through P2 and heavily discounted for the first year, in exchange for weekly feedback, a reference quote, and permission to use anonymized screenshots.'));
ch.push(B('Ask precisely: watch a teacher take attendance and a manager read a report, rather than collecting feature requests. Feature lists from customers describe their current workaround, not their problem.'));
ch.push(B('Cap the number deliberately. Each partner consumes support time that comes directly out of build time.'));

ch.push(H1('7. Scope Control'));
ch.push(B('The phase-tagged requirements in SL-SRS-001 are the scope. Anything untagged is not in the plan.'));
ch.push(B('A new requirement admitted mid-phase displaces something of equal size, in writing. There is no hidden slack.'));
ch.push(B('Pre-agreed de-scope order under pressure in P1: (1) trend charts on dashboards (tables remain), (2) make-up session linking, (3) note templates, (4) holiday calendars, (5) batch cloning. Attendance, grading, reporting and audit are never de-scoped — they are the product.'));
ch.push(B('Explicitly not attempted before their phase: payments, custom domains, Microsoft drivers, portals, mobile applications, fee and invoice management for tenants, AI-assisted report writing.'));

ch.push(H1('8. Risk Register'));
ch.push(TBL(['Risk', 'L', 'I', 'Response'], [
  ['A tenancy leak reaches production', 'Low', 'Catastrophic', 'Automatic scoping, generated isolation tests as a blocking check, per-resource review note'],
  ['Configurability produces an unusable setup experience', 'Medium', 'High', 'Presets, guided onboarding, effective-value display; activation metric watched from the first design partner'],
  ['Metering disputes with customers', 'Medium', 'Medium', 'Published definition, live meter, immutable snapshots, exportable history'],
  ['Custom grids cost more than estimated', 'Medium', 'Medium', 'Budgeted as bespoke pages in W3; fallback is simplified per-learner entry on the same data model'],
  ['Compliance paperwork blocks the first EU customer', 'Medium', 'High', 'Artifacts listed in SL-SEC-004 §11 prepared during P3, not after a deal appears'],
  ['Operating entity undecided at first sale', 'Medium', 'High', 'Manual invoicing works without card processing; decision forced at M6'],
  ['Solo-founder outage', 'Low', 'High', 'Daily deploys, documented decisions, tests as executable specification'],
  ['Scope creep from design partners', 'High', 'Medium', 'Written trade rule (§7); partners see the roadmap and where their request landed'],
], [3400, 500, 900, 4946]));

ch.push(H1('9. Budget'));
ch.push(TBL(['Stage', 'Infrastructure', 'Services', 'Indicative monthly'], [
  ['Build (P1–P2)', 'Local development; one small staging server', 'Domain, repository, error tracking free tiers', 'Single-digit'],
  ['First customers (P3)', 'One production server plus backups', 'Payment processing fees; transactional email', 'Low double-digit'],
  ['~25 tenants', 'Larger server or split database; object storage', 'Email volume; monitoring', 'Tens'],
  ['~100 tenants', 'Split app/database/worker; managed backups', 'Support tooling; legal review', 'Low hundreds'],
], [1700, 3100, 2900, 2046]));
ch.push(P('Infrastructure is not the constraint on this business at any realistic stage — developer time is. The correct optimization is shipping fewer, better modules, not saving money on servers.'));

ch.push(H1('10. Definition of Done'));
ch.push(B('The requirement ID is referenced in the change, and its acceptance criterion is exercised by a test.'));
ch.push(B('Authorization denial and tenant isolation are both covered for any new resource.'));
ch.push(B('Audit entries verified for new write paths; preset and seed data updated; documentation updated where behavior changed.'));
ch.push(B('Screens checked against the interface standards in SL-ARC-002 §13, including the mobile case for anything a teacher uses in class.'));
ch.push(B('Deployed to staging and demonstrated — a feature nobody has seen working is not done.'));

ch.push(H1('11. Operating Cadence After Launch'));
ch.push(B('Weekly: review activation and usage metrics, failed deliveries and error trends; one improvement shipped from customer feedback.'));
ch.push(B('Monthly: restore rehearsal, dependency updates, roadmap review against the metrics in SL-PRD-000 §9.'));
ch.push(B('Quarterly: pricing and packaging review against actual usage; security review; decision-record backlog cleared.'));
ch.push(B('Continuously: every support conversation ends with the question of what product change would have prevented it.'));

doc = buildDoc(ch, 'SL-PLN-008 · Roadmap & Delivery Plan · snova-labs');
save(doc, '/home/claude/tut-docs/out/08_Roadmap_and_Delivery_Plan.docx');
