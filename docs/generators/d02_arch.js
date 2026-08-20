// Doc 02 — Design Principles & System Architecture
const L = require('./tlib');
const { H1, H2, H3, P, B, N, NOTE, CODE, TBL, BREAK, cover, buildDoc, save, TableOfContents, PRODUCT } = L;

const ch = [];
ch.push(...cover(
  'Design Principles & System Architecture',
  'Engineering standards, tenancy model, stack decision, extension points and deployment modes',
  'SL-ARC-002',
  [['Parent', 'SL-SRS-001 (Requirements) — this document explains how those requirements are met'],
   ['Companions', 'SL-DAT-003 (schema), SL-SEC-004 (isolation & compliance), SL-BIL-006 (metering), SL-OPS-007 (integrations & deployment)']]
));
ch.push(H1('Table of Contents'));
ch.push(new TableOfContents('TOC', { hyperlink: true, headingStyleRange: '1-2' }));
ch.push(BREAK());

ch.push(H1('1. Engineering Principles'));
ch.push(P('These are binding. They are chosen to protect against the three ways a small SaaS product usually dies: a tenancy leak, configuration that only the founder understands, and an architecture that cannot be run cheaply while it has no customers.'));
ch.push(TBL(['Principle', 'What it means here'], [
  ['Tenancy is a data-layer guarantee', 'Isolation is enforced automatically on every query by the persistence layer, never by remembering to add a filter. A missing filter must be impossible, not merely discouraged.'],
  ['Configuration over code', 'If a customer could plausibly want it different — vocabulary, ID format, statuses, policies, grading, periods, report blocks — it is data with an admin screen and a preset default.'],
  ['Separation of concerns', 'HTTP layer validates and authorizes and delegates. Business rules live in services. Persistence lives in models and query objects. Presentation only presents.'],
  ['Depend on abstractions', 'Mail, storage, payments, meetings, calendar, PDF rendering and entitlements are interfaces with drivers. Swapping a vendor is configuration plus one class.'],
  ['API-first', 'Every capability is reachable through a versioned API used by the product itself where practical, so future portals and mobile clients need no backend rewrite.'],
  ['Portable and cheap by default', 'The system runs on one small server with commodity components. Heavier infrastructure is an upgrade path, never a prerequisite.'],
  ['Everything auditable', 'Domain writes emit audit entries automatically at the persistence layer, including bulk operations, imports and operator access.'],
  ['UTC core, local edges', 'Conversion happens exactly twice: parsing input and rendering output. No intermediate layer reasons in local time.'],
  ['Reversible decisions stay cheap', 'The UI framework, hosting provider and payment vendor are all replaceable. The domain model and tenancy boundary are not — so those get the design attention.'],
  ['The product has no name in code', 'Namespaces, database names and repository names are neutral (`snova-labs/platform`). The product name lives in one configuration value and the language files.'],
], [2500, 7246]));
ch.push(BREAK());

ch.push(H1('2. System Overview'));
ch.push(P('A **modular monolith** with two logical planes in one deployable application. A monolith is the correct choice for a small team; the module boundaries are the seam if anything ever needs extracting.'));
ch.push(...CODE([
  '                     Staff browser            (P5: learner / guardian portal, mobile)',
  '                          |                                    |',
  '                          v                                    v',
  '  +--------------------------------------------------------------------------+',
  '  |  Web server  ->  Application                                             |',
  '  |                                                                          |',
  '  |  HTTP layer   : auth . tenant-resolution middleware . rate limiting      |',
  '  |                 controllers / admin-panel resources / API controllers    |',
  '  |                 form requests (validation) . policies (authorization)    |',
  '  |--------------------------------------------------------------------------|',
  '  |  TENANT PLANE                        |  CONTROL PLANE                    |',
  '  |  scheduling . people . attendance    |  tenant lifecycle . plans         |',
  '  |  grading . notes . reporting         |  entitlements . metering          |',
  '  |  dashboards . settings . audit       |  billing . operator console       |',
  '  |--------------------------------------------------------------------------|',
  '  |  Domain services (transactions, business rules, audit context)            |',
  '  |    SettingsResolver  IdSequenceService  AttendanceService                 |',
  '  |    GradeBookService  ReportService      EnrollmentService                 |',
  '  |    PeriodService     EntitlementService MeteringService                   |',
  '  |--------------------------------------------------------------------------|',
  '  |  Persistence: models + query objects  ->  MySQL 8   (TenantScope global)  |',
  '  |  Cross-cutting: AuditObserver . domain events . cache                     |',
  '  |--------------------------------------------------------------------------|',
  '  |  Background workers: session generation . report runs . delivery retries  |',
  '  |                      metering snapshots . imports/exports                 |',
  '  |--------------------------------------------------------------------------|',
  '  |  Driver interfaces:                                                       |',
  '  |    MailProvider    smtp | n8n | ms-graph                                  |',
  '  |    FileStorage     local | s3-compatible | azure | sharepoint             |',
  '  |    PdfRenderer     php-native | headless-chrome                           |',
  '  |    PaymentProvider stripe | merchant-of-record | manual                   |',
  '  |    Entitlements    subscription | licence-file                            |',
  '  |    Meetings        manual-link | ms-teams        Calendar  ical | graph   |',
  '  +--------------------------------------------------------------------------+',
]));
ch.push(NOTE('The two planes share one codebase and one database but never share authorization. Control-plane routes require operator identity with mandatory two-factor (FR-OPS-1); tenant routes can never reach control-plane services.'));
ch.push(BREAK());

ch.push(H1('3. Tenancy Model'));
ch.push(H2('3.1 Decision: shared database, row-level isolation'));
ch.push(TBL(['Option', 'Pros', 'Cons', 'Verdict'], [
  ['Shared DB, `tenant_id` on every row', 'One migration run; cheapest to operate; trivial cross-tenant analytics; scales to thousands of small tenants', 'A missing scope is a data breach; noisy-neighbour risk at extreme scale', '**Chosen** — with automatic scoping and mandatory isolation tests'],
  ['Database per tenant', 'Strong isolation; simple per-tenant export and restore', 'Migrations across N databases; connection overhead; heavy for small tenants; painful at 200+ tenants for one operator', 'Rejected for cloud; effectively what self-hosted mode is'],
  ['Schema per tenant', 'Middle ground', 'MySQL handles this poorly; worst of both operationally', 'Rejected'],
], [2400, 2600, 2600, 2146]));
ch.push(H2('3.2 How isolation is guaranteed'));
ch.push(B('**Resolution:** middleware resolves the current tenant from the authenticated user (and, later, from subdomain or custom domain) and binds it to the request context before any query runs.'));
ch.push(B('**Automatic scoping:** every tenant-owned model applies a global scope filtering by the bound tenant, and stamps `tenant_id` on create. Models are tenant-owned by default; being global is the exception and must be declared explicitly.'));
ch.push(B('**Defense in depth:** authorization policies re-verify ownership on every write; requests carrying an identifier from another tenant fail as not-found, never as forbidden (which would leak existence).'));
ch.push(B('**Proof:** every resource has an automated test asserting that a user of tenant A receives not-found for tenant B\u2019s records via UI route, API route and export. A resource without that test does not ship (NFR-ISO-1).'));
ch.push(B('**Background work:** queued jobs carry the tenant identifier and re-bind context on execution; a job that resolves no tenant fails loudly rather than running unscoped.'));
ch.push(H2('3.3 Hierarchy'));
ch.push(...CODE([
  'Tenant            one paying account — the isolation boundary',
  '  └─ Brand        trading identity: logo, colors, report template, sender',
  '       └─ Branch  location/unit: timezone, week start, staff, calendar',
  '            └─ Course      programme + default policies',
  '                 └─ Batch  scheduled run: timetable, timezone, teachers',
  '                      └─ Session       one occurrence',
  '                      └─ Enrollment    learner ↔ batch (anchor for all',
  '                                       attendance, grades, notes, reports)',
]));
ch.push(P('Most tenants have exactly one brand, so brand selection is created automatically and hidden until multi-brand is enabled (FR-CFG-5). The hierarchy exists in the schema from the first migration regardless — retrofitting a level above existing data is the single most expensive mistake available here.'));
ch.push(BREAK());

ch.push(H1('4. Technology Stack'));
ch.push(H2('4.1 Choices'));
ch.push(TBL(['Layer', 'Choice', 'Reasoning'], [
  ['Language / framework', 'PHP 8.3+ with Laravel 13', 'Current stable major; the team\u2019s strongest stack; first-class queues, policies, migrations, filesystem and mail abstractions — several architectural requirements are framework features rather than custom code'],
  ['Staff UI', 'Filament v5 admin panel plus purpose-built pages for the attendance and grade grids', 'CRUD-heavy modules (settings, courses, people, roles) cost hours instead of weeks; the two genuinely custom interfaces are built by hand'],
  ['Database', 'MySQL 8 (InnoDB, utf8mb4)', 'Transactions, foreign keys, JSON columns for configuration payloads, ubiquitous and cheap to host anywhere including on-premises'],
  ['Queue / cache', 'Database driver by default; Redis when load justifies it', 'A 4 GB server should not spend memory on Redis to serve twenty tenants (NFR-COST-1)'],
  ['PDF rendering', 'Pure-PHP renderer by default; headless browser as an upgrade driver', 'Chrome costs roughly 700 MB and complicates on-premises packaging; the interface makes the upgrade a one-line change'],
  ['File storage', 'Local disk → S3-compatible object storage', 'Same driver either way; required for the self-hosted product (NFR-PORT-1)'],
  ['Future clients', 'Next.js against the versioned API', 'Learner and guardian portals (P5) are client work, not a backend rewrite'],
], [1800, 2500, 5446]));
ch.push(H2('4.2 Why not a separate SPA frontend now'));
ch.push(P('A headless API with a bespoke single-page application produces a better-looking product and roughly triples the build time, because every screen is implemented twice. With one developer and no customers yet, that trade buys polish at the cost of validation. The mitigation is structural rather than aspirational: **all business logic lives in services, and the API exists from day one**, so the admin panel is a replaceable presentation layer. Rebuilding the UI later touches no domain code.'));
ch.push(NOTE('Recorded as decision record ADR-001. Revisit when either (a) the admin-panel aesthetic demonstrably costs deals, or (b) portal work makes a shared component library worthwhile.'));
ch.push(BREAK());

ch.push(H1('5. Layering Rules'));
ch.push(B('**HTTP layer** (controllers, panel resources, API controllers) may call form requests, policies, services and response transformers. It must contain no queries and no business rules.'));
ch.push(B('**Services** own transactions, cross-entity rules, identifier generation, event dispatch and audit context. They never render or read request state directly.'));
ch.push(B('**Models** hold relations, casts (all datetimes immutable and UTC), the tenant scope, and observers. Complex reads go to dedicated query objects (e.g., `BatchPerformanceQuery`) rather than growing on the model.'));
ch.push(B('**Jobs** are thin wrappers over services, carry identifiers rather than serialized models, re-bind tenant context, and are idempotent so retries are safe.'));
ch.push(B('**Policies** are the single authorization mechanism, registered for every resource on both UI and API paths.'));
ch.push(H2('5.1 Core service contracts'));
ch.push(...CODE([
  'SettingsResolver::get(key, scope): mixed',
  '  // resolves tenant -> brand -> branch -> course -> batch, cached per',
  '  // request and invalidated on write; also reports which level answered',
  '',
  'IdSequenceService::next(entity, scope): string',
  '  // atomic increment + format from configurable prefix/pad (SL-DAT-003)',
  '',
  'PeriodService::current(batch): Period',
  'PeriodService::boundaries(batch, period): [UTC start, UTC end]',
  '  // period type + calendar system + batch timezone all respected here,',
  '  // so no other code computes date ranges',
  '',
  'AttendanceService::record(session, marks[]): AttendanceResult',
  'AttendanceService::rate(enrollment, period): AttendanceRate',
  '  // applies the resolved attendance policy (compulsory, late-join, types)',
  '',
  'GradeBookService::saveGrid(batch, cells[]): SaveResult',
  'GradeBookService::periodAverage(enrollment, period): Average',
  '  // grading-scheme strategy normalizes; type weights aggregate',
  '',
  'ReportService::generate(enrollment, period, options): Report',
  'ReportService::queueBatchRun(batch, period, options): BatchRun',
  '',
  'EntitlementService::allows(tenant, feature): bool',
  'EntitlementService::limit(tenant, key): Limit',
  '  // backed by subscription (cloud) or licence file (self-hosted)',
]));
ch.push(BREAK());

ch.push(H1('6. Extension Points'));
ch.push(P('These are the seams that keep "configurable" from becoming "forked per customer".'));
ch.push(TBL(['Seam', 'Mechanism', 'Adding a new one requires'], [
  ['Vertical / country defaults', 'Preset definitions (versioned data)', 'A data file — no deploy'],
  ['Vocabularies', 'Lookup tables per tenant (statuses, types, categories)', 'Admin UI entry'],
  ['Terminology', 'Label map per tenant, applied in the presentation layer', 'Admin UI entry'],
  ['Grading schemes', 'Strategy class implementing normalize + validate + display', 'One class, registered — no analytics changes'],
  ['Reporting periods', 'Period-type strategy (monthly, term, quarter, block, custom)', 'One class'],
  ['Calendar systems', 'Calendar adapter for display and period boundaries', 'One adapter (SL-LOC-005)'],
  ['Report layout', 'Template with toggleable blocks per brand/course', 'Template data'],
  ['External services', 'Driver implementing the provider interface', 'One class + configuration'],
  ['Identifier formats', 'Sequence configuration per entity and scope', 'Admin UI entry'],
], [2300, 4200, 3246]));

ch.push(H1('7. Settings Resolution'));
ch.push(P('Five levels deep, read on nearly every request, so correctness and cost both matter:'));
ch.push(...CODE([
  'effective(key, batch) =',
  '     batch override                                  (most specific)',
  '  ?? course override',
  '  ?? branch override',
  '  ?? brand override',
  '  ?? tenant default',
  '  ?? preset seed  ?? system default                  (least specific)',
]));
ch.push(B('The resolved map for a tenant is cached and invalidated on any settings write; a request resolves at most once per key.'));
ch.push(B('The UI always shows the effective value **and the level that supplied it**, with a one-click "override here" and "revert to inherited" — inherited configuration that cannot be traced is worse than no configuration.'));
ch.push(B('Policy objects (attendance policy, grading weights, report blocks) resolve as whole units rather than key-by-key, so a partial override cannot produce an incoherent combination.'));

ch.push(H1('8. Identity Model'));
ch.push(P('Authentication identity is deliberately separate from person records (FR-IAM-8):'));
ch.push(...CODE([
  'users        login identity: email, password, 2FA, locale, timezone',
  '  ├─ staff_profile     → roles, permissions, branch scope, batch assignments',
  '  ├─ guardian_link     → guardian record        (P5 portal)',
  '  └─ learner_link      → learner record         (P5 portal)',
  '',
  'guardians / learners exist with no login by default — they are people,',
  'not accounts. Granting access later creates a users row and links it.',
]));
ch.push(P('This is the difference between adding portals as a feature and adding them as a migration. It costs one table now.'));
ch.push(BREAK());

ch.push(H1('9. Entitlements & Metering'));
ch.push(B('Feature checks and limits are read exclusively through `EntitlementService`. Nothing in the tenant application knows whether the answer came from a subscription record or a signed licence file — this is what makes the self-hosted product possible without a fork (FR-ENT-2, FR-LIC-1).'));
ch.push(B('Metering is a scheduled job writing immutable daily snapshots of the active learner count; billing reads snapshots, never live counts, so an invoice can always be reconstructed and defended (FR-MTR-1..3).'));
ch.push(B('Limits declare their own enforcement style. Hard limits block the action with an upgrade path; they never block a save that would lose work already entered.'));

ch.push(H1('10. Deployment Modes'));
ch.push(TBL(['Aspect', 'Cloud (multi-tenant)', 'Self-hosted (single tenant, P5)'], [
  ['Tenancy', 'Many tenants, row-level scoping', 'Same code, one tenant bound at boot'],
  ['Control plane', 'Operator console active', 'Absent; entitlements from licence file'],
  ['Storage', 'S3-compatible object storage', 'Local disk or the customer\u2019s own object storage'],
  ['Mail', 'Platform SMTP or per-brand sender', 'Customer\u2019s SMTP or Microsoft 365'],
  ['Updates', 'Continuous deployment by operator', 'Versioned release artifacts, documented upgrade and rollback'],
  ['Billing', 'Metered subscription', 'Annual licence with learner cap and expiry'],
], [1700, 4000, 4046]));
ch.push(NOTE('Four constraints hold from the first commit so that P5 is packaging work rather than re-architecture: (1) no proprietary managed-service dependency; (2) entitlements read through one interface; (3) the application runs fully without the control plane; (4) no runtime outbound internet requirement.'));

ch.push(H1('11. Cost-Aware Defaults'));
ch.push(P('The product must run on one small server while it has no revenue, without compromising the architecture that lets it scale later. Each of these is a configuration default, not a design limitation:'));
ch.push(TBL(['Component', 'Default (pre-revenue)', 'Upgrade trigger', 'Upgrade path'], [
  ['PDF rendering', 'Pure-PHP renderer', 'Report layout needs modern CSS', 'Switch `PdfRenderer` driver to headless browser'],
  ['Queue', 'Database driver', 'Sustained job backlog or > ~50 tenants', 'Redis driver, same job classes'],
  ['Cache / sessions', 'Database or file', 'Response times degrade under concurrency', 'Redis'],
  ['Object storage', 'Local disk', 'Multiple app servers, or backup policy demands it', 'S3-compatible bucket, same filesystem abstraction'],
  ['Database', 'Same host as the application', 'CPU contention or backup windows', 'Separate database host'],
  ['Workers', 'One worker process on the app host', 'Report runs delay interactive work', 'Dedicated worker host'],
], [1700, 2600, 2700, 2746]));
ch.push(P('Expected shape: roughly one small server while building, the same server through the first customers, and a horizontal split only when tenant count or report volume forces it. Infrastructure is not the constraint on this business — developer time is (SL-PRD-000 §9).'));
ch.push(BREAK());

ch.push(H1('12. Time, Calendar & Period Handling'));
ch.push(B('Instants are UTC in storage and in every computation. Local calendar values (session date, due date, period label) are stored alongside the instant in the owning batch\u2019s timezone, because those are what humans agreed to and must not drift.'));
ch.push(B('Session generation converts each occurrence individually from local timetable time to UTC, so daylight-saving transitions are handled per date rather than by a fixed offset.'));
ch.push(B('`PeriodService` is the only place that computes period boundaries. It combines period type, calendar system and batch timezone — no other code may derive a date range, which is what prevents the classic "report missed the last session" defect.'));
ch.push(B('Recipient-facing output renders in the recipient\u2019s timezone and calendar where known, otherwise the branch\u2019s, always labelled.'));

ch.push(H1('13. Interface Direction'));
ch.push(P('The interface is used daily by non-technical staff, often in a hurry, sometimes on a phone. Clarity beats decoration.'));
ch.push(H2('13.1 Palette options'));
ch.push(P('Three directions, each meeting WCAG AA on white. The choice is a theme (CSS custom properties) and is reversible.'));
ch.push(TBL(['Token', 'A — Deep Teal', 'B — Graphite & Amber', 'C — Indigo'], [
  ['Primary', '#0F766E', '#334155', '#4F46E5'],
  ['Primary dark', '#115E59', '#1E293B', '#3730A3'],
  ['Accent', '#F59E0B', '#D97706', '#EC4899'],
  ['Success', '#059669', '#16A34A', '#10B981'],
  ['Warning / Danger', '#D97706 / #DC2626', '#CA8A04 / #DC2626', '#F59E0B / #EF4444'],
  ['Surface / Page', '#FFFFFF / #F8FAFC', '#FFFFFF / #F7F7F6', '#FFFFFF / #F5F5FF'],
  ['Text / Muted', '#0F172A / #64748B', '#111827 / #6B7280', '#111827 / #6B7280'],
  ['Character', 'Calm, education-adjacent, low fatigue for all-day use', 'Neutral and serious; brand color comes from the tenant, not from us', 'Modern product feel; strongest for marketing screenshots'],
], [1600, 2750, 2700, 2696]));
ch.push(NOTE('Recommendation: **B — Graphite & Amber** for the application chrome. Tenants apply their own brand colors to reports and recipient-facing output, and a neutral chrome lets a customer\u2019s brand look correct next to it rather than fighting it. Reserve a stronger palette for the marketing site.'));
ch.push(H2('13.2 Interaction standards'));
ch.push(B('Typography: one sans-serif family for the interface and one monospace family for identifiers, scores and timestamps. No per-module font variation.'));
ch.push(B('Layout: persistent navigation, page header with breadcrumb and primary action, consistent table density control, meaningful empty states that offer the next action.'));
ch.push(B('Data entry: grids save in one action with optimistic feedback and explicit error rows; destructive actions confirm with the record name typed or clearly displayed; nothing is deleted without an audit trail.'));
ch.push(B('Responsiveness: the attendance grid and session views are designed mobile-first (FR-ATT-5); the rest degrades gracefully.'));
ch.push(B('Every displayed time and date states its zone or calendar when ambiguity is possible.'));

ch.push(H1('14. Quality Gates'));
ch.push(B('Continuous integration on every push: formatting, static analysis, full test suite, and a migration up-and-down check.'));
ch.push(B('Required test classes per feature: happy path, authorization denial, and **tenant isolation** (NFR-ISO-1). Calculation-heavy services — attendance rate, weighted average, period boundaries, metering snapshot — carry unit tests with fixtures including daylight-saving and mid-period enrollment cases.'));
ch.push(B('Definition of done: requirement ID referenced, tests included, audit entries verified, preset/seed data updated, and the screen checked against §13.'));
ch.push(B('Architectural decisions recorded as dated decision records in the repository; ADR-001 is the admin-panel-first UI decision in §4.2.'));

const doc = buildDoc(ch, 'SL-ARC-002 · Design Principles & System Architecture · snova-labs');
save(doc, '/home/claude/tut-docs/out/02_Design_Principles_and_Architecture.docx');
