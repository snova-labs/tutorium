// Doc 05 — Localization & Country Readiness ; Doc 06 — Billing, Plans, Entitlements & Metering
const L = require('./tlib');
const { H1, H2, H3, P, B, N, NOTE, CODE, TBL, BREAK, cover, buildDoc, save, TableOfContents, PRODUCT } = L;

// ============================== DOC 05 ==============================
let ch = [];
ch.push(...cover(
  'Localization & Country Readiness',
  'Timezones, calendar systems, reporting periods, grading conventions, names, language and formats',
  'SL-LOC-005',
  [['Parent', 'SL-SRS-001 §6.3 · SL-ARC-002 §12 · presets defined in SL-PRD-000 §7']]
));
ch.push(H1('Table of Contents'));
ch.push(new TableOfContents('TOC', { hyperlink: true, headingStyleRange: '1-2' }));
ch.push(BREAK());

ch.push(H1('1. Principles'));
ch.push(P('Most education software fails outside its home country not because of language, but because of assumptions buried in the schema: that a week starts on Monday, that a report covers a calendar month, that a name has a first and a last part, that grades run 0–100, that the weekend is Saturday and Sunday. Each of those is wrong somewhere in the target market.'));
ch.push(B('**No locale is the default in code.** Behavior comes from configuration seeded by a preset; the code has no "normal" country.'));
ch.push(B('**Store canonical, display local.** Storage is UTC and Gregorian; calendar systems, formats and translations are presentation concerns.'));
ch.push(B('**One place computes each thing.** Period boundaries, week rules and calendar conversion each live in exactly one service — the classic date defects come from three files disagreeing.'));
ch.push(B('**Configuration is discoverable.** Every locale-sensitive setting shows its effective value and origin, because a misconfigured week start silently corrupts a term of attendance data.'));

ch.push(H1('2. Timezones'));
ch.push(TBL(['Level', 'Timezone role'], [
  ['Branch', 'Default operating timezone for everything scheduled there (IANA identifier, e.g. `Asia/Kathmandu`, `Europe/Berlin`, `America/Toronto`).'],
  ['Batch', 'Inherits the branch timezone; overridable for online cohorts whose teacher and learners sit elsewhere. **This is the authoritative timezone for sessions, due dates and period boundaries.**'],
  ['User', 'Display preference for staff; falls back to the branch timezone.'],
  ['Learner / Guardian', 'Optional home timezone used when rendering times in recipient-facing email and portals.'],
], [1600, 8146]));
ch.push(B('Sessions store both the UTC instant and the local date/time; conversion happens per occurrence at generation, so daylight-saving transitions shift the UTC instant while the local clock time stays as the timetable promised.'));
ch.push(B('Any displayed time that could be misread states its zone. Cross-timezone screens (a coordinator in Kathmandu viewing a Toronto batch) show both the batch time and the viewer\u2019s time.'));
ch.push(B('The timezone database is kept current through dependency updates; a timezone rule change is treated as a patch-priority update because it silently alters future schedules.'));

ch.push(H1('3. Calendar Systems'));
ch.push(P('Storage is always Gregorian. A calendar adapter converts for display, for date entry, and for period labelling. Supported adapters:'));
ch.push(TBL(['Calendar', 'Markets', 'Notes'], [
  ['Gregorian', 'Default everywhere', 'Baseline'],
  ['Bikram Sambat', 'Nepal', 'Month lengths vary by year and require a lookup table rather than an algorithm; the local academic year and public communication use it'],
  ['Hijri (Umm al-Qura)', 'Gulf, parts of MENA', 'Lunar; used alongside Gregorian rather than instead of it'],
  ['Jalali (Solar Hijri)', 'Iran, Afghanistan', 'Solar; distinct new year'],
  ['Buddhist Era', 'Thailand, parts of SE Asia', 'Gregorian arithmetic with a year offset — cheapest adapter'],
], [1800, 3200, 4746]));
ch.push(B('An adapter provides: convert to and from Gregorian, format a date, parse user input, name months, and give the first and last day of a local month.'));
ch.push(B('A tenant may display a secondary calendar alongside the primary (common in Nepal and the Gulf), configured per brand or branch.'));
ch.push(NOTE('Design boundary: calendar systems affect **display and period labelling**, never storage or arithmetic. Attendance percentages and averages are computed on stored Gregorian dates, so a calendar bug can never corrupt academic data.'));

ch.push(H1('4. Week Structure'));
ch.push(B('Week start is configurable per branch: Monday (Europe, most of the world), Sunday (US, Canada, Japan, Nepal, India), Saturday (parts of MENA).'));
ch.push(B('Weekend days are a configurable set — Saturday–Sunday, Friday–Saturday (Gulf), or Sunday only (Nepal). This drives calendar shading, default timetable suggestions and "next working day" logic.'));
ch.push(B('Nothing in the product may assume that a "Saturday class" is a weekend class, or that weekday numbering starts at Monday.'));

ch.push(H1('5. Reporting Periods'));
ch.push(P('The reporting period is the unit a progress report covers. Making it a first-class configurable entity — rather than a month string — is the single most important internationalization decision in the schema (SL-DAT-003 §5).'));
ch.push(TBL(['Period type', 'Definition', 'Typical vertical'], [
  ['Monthly', 'Calendar month in the batch timezone and calendar system', 'Tutoring centres'],
  ['Term', 'Named ranges defined per course (e.g., Autumn, Spring, Summer)', 'Language schools, schools-adjacent'],
  ['Quarter', 'Three-month blocks anchored to a configurable start month', 'Corporate training'],
  ['Cohort block', 'Fixed-length blocks from the batch start (e.g., every 4 weeks or every N sessions)', 'IT/skills institutes, bootcamps'],
  ['Custom', 'Explicit start and end dates entered by the tenant', 'Anything irregular'],
], [1700, 4800, 3246]));
ch.push(H2('5.1 Boundary rules'));
ch.push(B('`PeriodService` is the only code that computes a period boundary. It takes the period type, the batch timezone and the calendar system, and returns an inclusive local date range plus the corresponding UTC instants.'));
ch.push(B('A session or assessment belongs to the period containing its **local** date in the batch timezone. A 9 p.m. Toronto session on the last day of a month must not drift into the next period because UTC has already rolled over.'));
ch.push(B('Periods have a status: open, closed, reported. Closing prevents late edits from silently changing a report that has already been sent; amendments after closing are possible, audited, and flagged on any regenerated report.'));

ch.push(H1('6. Grading Conventions'));
ch.push(P('The grading engine is scheme-driven (SL-SRS-001 §3.8); localization supplies the presets a customer expects to see on day one.'));
ch.push(TBL(['Convention', 'Kind', 'Notes'], [
  ['Percentage 0–100', 'percentage', 'Widely understood; common default'],
  ['Points out of N', 'points', 'N configurable per assessment — never fixed at 100'],
  ['Letter A–F (US)', 'letter', 'Bands configurable; +/- variants optional'],
  ['Letter A*–U (UK)', 'letter', 'Different ladder, same mechanism'],
  ['German 1–6', 'letter', '**1 is best** — the scheme declares its direction, so analytics never assume higher is better'],
  ['GPA 4.0', 'letter', 'Band-to-point mapping in scheme config'],
  ['CEFR A1–C2', 'level', 'Language schools; ordered ladder rather than a score'],
  ['Pass / Fail / Distinction', 'pass_fail', 'Corporate and certification contexts'],
  ['Rubric', 'rubric', 'Criteria with individual maxima; used everywhere for projects and speaking assessments'],
], [2100, 1500, 6146]));
ch.push(NOTE('Direction matters: a scheme declares whether a higher raw value is better. Normalization to 0–100 respects that flag, so a German 1 normalizes to 100 and dashboards, trends and at-risk lists remain correct without special cases.'));

ch.push(H1('7. Names & Personal Data Conventions'));
ch.push(B('Names are stored as `legal_name` and `preferred_name` — not first/last. This accommodates Spanish double surnames, patronymics, family-name-first ordering, and single-name cultures without lossy parsing.'));
ch.push(B('Optional `sort_name` supports culturally appropriate list ordering; display order is a locale setting, not a hardcoded template.'));
ch.push(B('Optional transliteration field for tenants whose records are in a non-Latin script but whose reports go to sponsors in another script.'));
ch.push(B('Honorifics, gender values and relation types are tenant-editable lookups, not fixed lists.'));
ch.push(B('Addresses are stored as structured JSON with a locale-driven display template; no assumption of state/ZIP.'));
ch.push(B('Phone numbers are stored in international format with country context; validation is permissive by design — a rejected valid number is worse than a stored imperfect one.'));

ch.push(H1('8. Language & Translation'));
ch.push(B('All user-facing strings live in language files, including validation messages, email templates and report block labels. No string is concatenated from fragments, because word order differs across languages.'));
ch.push(B('Pluralization uses the framework\u2019s plural rules rather than "if count == 1", which is wrong in most languages.'));
ch.push(B('Layout tolerates strings up to twice English length; right-to-left support is a stylesheet direction and a layout audit, planned from the start rather than retrofitted.'));
ch.push(B('Locale resolution order: user preference → brand default → branch default → tenant default → English. Recipient-facing output resolves against the recipient\u2019s locale first.'));
ch.push(B('Tenant-authored content — report templates, email templates, note templates — can exist per locale, so one brand can report to families in two languages.'));
ch.push(B('Translation workflow: English is the source; translations are contributed as files and reviewed; missing keys fall back visibly in development and silently to English in production.'));

ch.push(H1('9. Formats, Currency & Numbers'));
ch.push(TBL(['Element', 'Rule'], [
  ['Dates', 'Formatted per locale and calendar; never hand-formatted with a hardcoded pattern'],
  ['Times', '12- or 24-hour per locale; zone shown where ambiguous'],
  ['Numbers', 'Locale decimal and grouping separators — a score of 8,5 in Germany is 8.5 elsewhere'],
  ['Currency', 'Per tenant for billing; per tenant for any fee fields; stored in minor units with an explicit currency code'],
  ['File exports', 'Excel exports carry typed cells so dates and numbers survive the customer\u2019s locale settings — the most common complaint about CSV exports'],
], [1600, 8146]));

ch.push(H1('10. Testing Matrix'));
ch.push(P('Localization defects are invisible in a single-locale test suite. These fixtures are permanent:'));
ch.push(TBL(['Scenario', 'What it proves'], [
  ['Batch in `America/Toronto` spanning a DST change', 'Local class times stay constant; UTC instants shift'],
  ['Batch in `Australia/Sydney` (southern DST) reporting monthly', 'Period boundaries computed in batch timezone'],
  ['Branch with Friday–Saturday weekend', 'Calendars, timetable defaults and working-day logic'],
  ['Tenant with Bikram Sambat display and Gregorian storage', 'Conversion, month lengths, period labels'],
  ['German 1–6 grading with weighted types', 'Direction-aware normalization'],
  ['CEFR level scheme with rubric speaking assessment', 'Mixed non-numeric schemes aggregate correctly'],
  ['Learner with a single-word name and a guardian with a double surname', 'No parsing assumptions'],
  ['Report rendered in a second language for one brand', 'Template and email locale resolution'],
], [3400, 6346]));

let doc = buildDoc(ch, 'SL-LOC-005 · Localization & Country Readiness · snova-labs');
save(doc, '/home/claude/tut-docs/out/05_Localization_and_Country_Readiness.docx');

// ============================== DOC 06 ==============================
ch = [];
ch.push(...cover(
  'Billing, Plans, Entitlements & Metering',
  'How usage is measured, what customers are charged, and how entitlements work in cloud and self-hosted modes',
  'SL-BIL-006',
  [['Parent', 'SL-PRD-000 §6 (pricing model) · SL-SRS-001 §4.2–4.6']]
));
ch.push(H1('Table of Contents'));
ch.push(new TableOfContents('TOC', { hyperlink: true, headingStyleRange: '1-2' }));
ch.push(BREAK());

ch.push(H1('1. Model Overview'));
ch.push(...CODE([
  'plan ──< plan_features            (limits + feature flags, data-defined)',
  '  │',
  'subscription ── tenant ──< usage_snapshots  (immutable daily counts)',
  '  │                              │',
  '  └──< invoices ─────────────────┘  quantity = peak snapshot in period',
  '',
  'EntitlementService  ← the ONLY way the app asks "can this tenant do X?"',
  '   cloud      : resolves from subscription + plan_features + overrides',
  '   self-hosted: resolves from a signed licence file',
]));
ch.push(P('Two rules keep this from leaking into the product: **no feature check ever queries billing tables directly**, and **no invoice is ever computed from live data** — only from immutable snapshots.'));

ch.push(H1('2. The Metric: Active Learners'));
ch.push(H2('2.1 Definition'));
ch.push(NOTE('An **active learner** is a learner holding at least one enrollment whose status is flagged `is_active_for_billing` for one or more days within the billing period. The billable quantity is the **peak** daily count in that period. Learners are counted once regardless of how many batches they are enrolled in.'));
ch.push(H2('2.2 Why these choices'));
ch.push(B('**Peak rather than average** — a customer can verify it against their own roster without arithmetic, and it cannot be gamed by shuffling enrollment dates.'));
ch.push(B('**Learner-level, not enrollment-level** — a centre that upsells a second course to the same child is not punished for it, which keeps the metric aligned with their growth rather than their packaging.'));
ch.push(B('**Status-flag driven** — the lookup flag (SL-DAT-003 §4) means the tenant can see exactly which statuses cost money, and can add "On hold" without billing code changing.'));
ch.push(B('**History is free forever** — withdrawn, completed and graduated learners cost nothing. Charging for archives teaches customers to delete evidence, which destroys the product\u2019s core value.'));
ch.push(H2('2.3 Edge cases (resolved, not left to interpretation)'));
ch.push(TBL(['Case', 'Treatment'], [
  ['Learner joins mid-period', 'Counted from their first active day; may raise the peak'],
  ['Learner withdraws mid-period', 'Still counted for the period — they consumed the service; not counted next period'],
  ['Learner transferred between batches', 'One learner, counted once'],
  ['Learner re-enrolls after a gap', 'Counted again in the period they return'],
  ['Enrollment marked "On hold"', 'Depends on the tenant\u2019s flag for that status — visible in settings'],
  ['Trial tenant', 'Metered normally, invoiced at zero; conversion carries the history'],
  ['Suspended tenant', 'Metering pauses; read-only access remains'],
  ['Tenant below the plan minimum', 'Minimum monthly charge applies; the invoice shows both figures'],
], [2800, 6946]));

ch.push(H1('3. Metering Implementation'));
ch.push(B('A scheduled job runs once daily per region at a fixed UTC hour, writing one immutable `usage_snapshots` row per tenant with the count and a breakdown by brand and branch.'));
ch.push(B('The job is idempotent: re-running for a date replaces nothing and logs a skip, so a retry after a failure cannot double-count.'));
ch.push(B('A missed day is backfilled from enrollment status history, which is why that history table exists (SL-DAT-003 §6).'));
ch.push(B('Snapshots are never edited. A correction writes a new row flagged `is_corrected` with a reason and the operator\u2019s identity, and any affected invoice shows the correction (FR-MTR-4).'));
ch.push(B('Tenants see a live meter — current count, period peak to date, projected invoice — plus a downloadable usage history. The invoice must never be the first time they see the number (FR-MTR-3).'));

ch.push(H1('4. Plans & Entitlements'));
ch.push(H2('4.1 Structure'));
ch.push(TBL(['Element', 'Examples', 'Behavior'], [
  ['Price', 'unit_price per active learner, interval, minimum charge, currency', 'Data; multiple currencies supported per plan family'],
  ['Limits', 'max_brands, max_branches, max_staff, storage_gb', 'Each declares soft (warn) or hard (block) enforcement'],
  ['Features', 'api_access, custom_domain, sso, ms_integrations, scheduled_reports, priority_support', 'Boolean or valued flags'],
  ['Overrides', 'Per-tenant grants with reason and expiry', 'For negotiated deals; audited'],
], [1500, 4300, 3946]));
ch.push(H2('4.2 Enforcement rules'));
ch.push(B('Hard limits block the *initiating* action with a clear message and an upgrade path. They never block a save that would discard work already entered — a teacher must always be able to finish the grid in front of them.'));
ch.push(B('Exceeding a soft limit warns the tenant admin and notifies the operator; it never degrades service silently.'));
ch.push(B('Downgrades that would breach a limit are accepted but flagged: the tenant is told what must be reduced and by when, rather than having data locked away.'));
ch.push(B('Feature flags are checked at the capability boundary, not scattered through the UI — one check, one place, testable.'));

ch.push(H1('5. Payment Providers'));
ch.push(TBL(['Driver', 'Role', 'Handles', 'Phase'], [
  ['Stripe', 'Default for card-paying cloud tenants', 'Customers, subscriptions, metered usage reporting, invoices, tax calculation, webhooks, payment method updates', 'P3'],
  ['Manual / offline', 'Institutional buyers, bank transfer markets, on-premises licences', 'Invoice generation, manual payment marking, activation without a card', 'P3 — ships with Stripe'],
  ['Merchant of record', 'Fallback if the operating entity cannot use Stripe, or to outsource worldwide tax', 'Provider owns the sale, tax and remittance; we receive payouts and webhooks', 'Specified; built when needed'],
  ['Local gateways', 'Regional expansion (South Asia and similar)', 'Card and wallet methods Stripe does not cover locally', 'Later'],
], [1500, 2400, 4100, 1746]));
ch.push(NOTE('Open dependency: Stripe requires an operating entity in a supported country. If the entity ends up somewhere Stripe does not support, the merchant-of-record driver becomes the launch driver instead. The interface is identical, so this decision changes one class and the onboarding copy — nothing else (SL-PRD-000 §12 D2/D3).'));
ch.push(H2('5.1 Integration discipline'));
ch.push(B('All provider communication is one-way idempotent: webhooks are verified by signature, recorded before processing, and safe to replay. The provider is the source of truth for payment state; our records mirror it and reconcile nightly.'));
ch.push(B('Card data never reaches our systems — checkout and payment method management are hosted by the provider.'));
ch.push(B('Every provider action stores its reference (`provider_ref`) so any invoice or subscription can be traced end to end during a dispute.'));

ch.push(H1('6. Subscription Lifecycle'));
ch.push(...CODE([
  '   signup ──► TRIAL ──(convert)──► ACTIVE ◄──────────┐',
  '               │                    │  │             │',
  '        (expire, no card)     (payment fails)   (payment succeeds)',
  '               │                    ▼             │',
  '               └────────────►   PAST_DUE ─────────┘',
  '                                    │ (dunning exhausted)',
  '                                    ▼',
  '                               SUSPENDED  (read-only + export)',
  '                                    │ (no resolution / request)',
  '                                    ▼',
  '                               CANCELLED ──(retention window)──► PURGED',
]));
ch.push(TBL(['State', 'Tenant can', 'Tenant cannot'], [
  ['Trial', 'Everything, within trial limits', 'Exceed trial learner cap'],
  ['Active', 'Everything in plan', '—'],
  ['Past due', 'Everything; sees payment banner', '—'],
  ['Suspended', 'Log in, read data, export everything', 'Create or modify records; send reports'],
  ['Cancelled', 'Export during the retention window', 'Use the application'],
  ['Purged', '—', 'Data is irreversibly removed'],
], [1700, 4200, 3846]));
ch.push(P('The principle behind this table: **non-payment restricts service, never access to one\u2019s own data.** Holding data hostage generates chargebacks, reviews and regulatory complaints — and in this segment it would be data about children.'));

ch.push(H1('7. Trials, Changes & Dunning'));
ch.push(B('**Trial:** 14–30 days, full features, no card required, capped learner count. All data carries into the paid tenant untouched — no re-entry, ever.'));
ch.push(B('**Upgrade:** immediate, prorated for the remainder of the period.'));
ch.push(B('**Downgrade:** effective at period end, with a clear statement of what will be limited.'));
ch.push(B('**Dunning:** on a failed payment, retry on a defined schedule (roughly day 1, 3, 5, 7) with escalating emails to the billing contact; after the schedule, move to Suspended. Every step is announced in advance — a customer should never be surprised by suspension.'));
ch.push(B('**Recovery:** paying while Suspended restores full service immediately, with no data loss and no re-onboarding.'));

ch.push(H1('8. Invoicing & Tax'));
ch.push(B('Every invoice shows: period, metered quantity with its basis (peak date), unit price, minimum-charge adjustment if applied, subtotal, tax, total, currency, and both parties\u2019 details including tax identifiers.'));
ch.push(B('Tax handling is jurisdiction-driven: sales tax or VAT/GST where required, EU reverse charge with a validated VAT number, and correct treatment for cross-border digital services. Under a merchant-of-record driver, this is the provider\u2019s responsibility.'));
ch.push(B('Invoices are immutable once issued; corrections are credit notes, never edits.'));
ch.push(B('Tenants download invoices themselves from the billing portal (P4), and before that receive them by email.'));

ch.push(H1('9. Self-Hosted Licensing'));
ch.push(B('A licence is a signed file conveying tenant name, learner cap, enabled features, issue date and expiry. Validation is offline using a public key shipped with the release — no internet dependency at runtime (NFR-PORT-2).'));
ch.push(B('Cap behavior: exceeding the learner cap warns prominently and blocks new enrollments; it never blocks attendance, grading or reporting for existing learners.'));
ch.push(B('Expiry behavior: warnings begin 30 days out; after expiry a grace period continues normal operation, after which the system degrades to read-only with export available. It never deletes and never locks a customer out of their own records.'));
ch.push(B('Optional consent-based telemetry reports version and counts for support and renewal; the product functions identically with telemetry off.'));

ch.push(H1('10. Revenue Reporting'));
ch.push(TBL(['Metric', 'Definition', 'Use'], [
  ['MRR', 'Sum of active subscription values normalized to a month', 'Headline health'],
  ['Net revenue retention', 'Revenue from existing tenants this period ÷ last period', 'Whether the per-learner metric is compounding as intended'],
  ['ARPT', 'MRR ÷ active tenants', 'Segment and pricing validation'],
  ['Logo churn', 'Tenants cancelled ÷ tenants at period start', 'Seasonal in education — measure annually, not monthly'],
  ['Trial conversion', 'Paid conversions ÷ trials started', 'Onboarding quality signal (SL-PRD-000 §9)'],
  ['Gross margin per tenant', 'Revenue − infrastructure and provider fees', 'Confirms the < 2% infrastructure target'],
], [1500, 4600, 3646]));

ch.push(H1('11. Test Scenarios'));
ch.push(N('A tenant with joiners and withdrawals mid-period produces a peak matching a hand-counted roster; the invoice quantity equals that peak.'));
ch.push(N('A duplicated metering run for the same date creates no second snapshot and no double charge.'));
ch.push(N('A learner enrolled in three batches simultaneously is counted once.'));
ch.push(N('A failed payment triggers the full dunning sequence, reaches Suspended, and export remains available throughout.'));
ch.push(N('Paying while Suspended restores full access with no data change.'));
ch.push(N('An upgrade mid-period prorates correctly, and a feature gated by the new plan becomes available immediately through the entitlement interface only.'));
ch.push(N('A replayed provider webhook produces no duplicate invoice or subscription change.'));
ch.push(N('A self-hosted instance with an expired licence enters read-only after the grace period and still exports fully.'));

doc = buildDoc(ch, 'SL-BIL-006 · Billing, Plans, Entitlements & Metering · snova-labs');
save(doc, '/home/claude/tut-docs/out/06_Billing_Plans_Entitlements_and_Metering.docx');
