// Doc 00 — Product Vision & Business Requirements
const L = require('./tlib');
const { H1, H2, H3, P, B, N, NOTE, CODE, TBL, BREAK, cover, buildDoc, save, TableOfContents, PRODUCT } = L;

const ch = [];
ch.push(...cover(
  'Product Vision & Business Requirements',
  'What we are building, for whom, why it wins, and how it makes money',
  'SL-PRD-000',
  [['Related', 'SL-SRS-001 (Requirements), SL-ARC-002 (Architecture), SL-DAT-003 (Data Model), SL-SEC-004 (Tenancy, Security & Compliance), SL-LOC-005 (Localization), SL-BIL-006 (Billing & Metering), SL-OPS-007 (Integrations & DevOps), SL-PLN-008 (Roadmap & Delivery)']]
));
ch.push(H1('Table of Contents'));
ch.push(new TableOfContents('TOC', { hyperlink: true, headingStyleRange: '1-2' }));
ch.push(BREAK());

ch.push(H1('1. Product Summary'));
ch.push(P(`**${PRODUCT} is the operating system for cohort-based training businesses.** It manages learners, batches, attendance, assessment and progress reporting for organizations that teach in groups over a fixed period — tutoring centres, language schools, and IT/skills institutes — and it proves to whoever pays the fees that learning is happening.`));
ch.push(P('The product is multi-tenant SaaS, sold per active learner per month, deployable to any country, and offered as a self-hosted licence for institutions that require it. It is built and owned by **snova-labs**.'));

ch.push(H2('1.1 The one-sentence pitch'));
ch.push(NOTE(`Run every branch, batch and teacher from one place — and send parents or sponsors a professional progress report every period without anyone touching a spreadsheet.`));

ch.push(H2('1.2 What it is not'));
ch.push(TBL(['Not this', 'Why we stay out'], [
  ['A K-12 school SIS', 'National curriculum reporting, transcripts, complex timetabling and statutory returns are a different product with a 3-year sales cycle. We would lose on features and on procurement.'],
  ['An LMS (content, video, quizzes)', 'The market is saturated and content hosting is a cost centre. We integrate with whatever content tool the customer already uses.'],
  ['Accounting software', 'We will track fees and invoices eventually, but ledgers, payroll and tax filing belong to the customer\u2019s accountant.'],
  ['A marketing/CRM funnel tool', 'Lead capture and admissions marketing are adjacent; we start after the learner enrolls.'],
], [2600, 7146]));
ch.push(BREAK());

ch.push(H1('2. Problem'));
ch.push(P('Small and mid-size training businesses run on spreadsheets, WhatsApp groups and the memory of one overworked coordinator. Three failures repeat everywhere:'));
ch.push(B('**Nothing is provable.** When a parent or a corporate sponsor asks "is this working?", the centre has attendance ticks in one sheet, marks in another, and an opinion. Reports, if they exist, are hand-assembled the night before.'));
ch.push(B('**Nothing survives growth.** A second branch, a second brand, or a teacher in another timezone breaks the spreadsheet model immediately: duplicate IDs, conflicting "current month" columns, no access control, and no audit trail when a mark changes.'));
ch.push(B('**Existing software fits badly.** School information systems assume a K-12 structure the customer does not have. LMS platforms assume the value is in content, not in the cohort. Both assume one country\u2019s calendar, grading convention and school year.'));
ch.push(P('The result is that the fastest-growing segment of private education runs its operations on tools it has outgrown, and cannot demonstrate outcomes to the people paying the bills.'));

ch.push(H1('3. Target Customer'));
ch.push(H2('3.1 Ideal customer profile'));
ch.push(TBL(['Attribute', 'Profile'], [
  ['Type', 'Private training business teaching in cohorts over a fixed period'],
  ['Verticals', 'Tutoring / after-school centres · Language schools · IT, tech and professional skills institutes'],
  ['Size', '30–500 active learners; 2–30 staff; 1–5 locations (physical, online, or both)'],
  ['Buyer', 'Owner-director or academic head — non-technical, hands-on, pays from operating budget'],
  ['Current tooling', 'Spreadsheets + messaging apps, or an SIS/LMS they only use 20% of'],
  ['Trigger to buy', 'Opening a second branch · a parent complaint they could not answer with evidence · a corporate client demanding attendance and completion reports'],
], [2200, 7546]));

ch.push(H2('3.2 Why these three verticals are one product'));
ch.push(P('All three enroll learners into a scheduled group, run sessions on a timetable, assess work, and must report progress to a third party. The differences are vocabulary and policy, not structure — which is exactly what a configuration layer solves:'));
ch.push(TBL(['Dimension', 'Tutoring centre', 'Language school', 'IT / skills institute'], [
  ['Learner is called', 'Student', 'Learner / participant', 'Trainee / participant'],
  ['Report goes to', 'Parent or guardian', 'Learner (adult) or sponsor', 'Learner and/or employer'],
  ['Guardians module', 'Essential', 'Off for adult classes', 'Usually off'],
  ['Assessment style', 'Homework, classwork, projects', 'CEFR level tests, speaking rubrics', 'Labs, projects, certifications'],
  ['Attendance stakes', 'Parents care per session', 'Often flexible / make-up classes', 'Employer-mandated minimums'],
  ['Period', 'Monthly', 'Term / course block', 'Cohort / module'],
], [1900, 2650, 2600, 2596]));
ch.push(NOTE('Product consequence: terminology, module toggles, attendance policy, period structure and report layout must all be tenant configuration. A vertical is a preset, not a code branch.'));

ch.push(H2('3.3 Jobs to be done'));
ch.push(B('"Let my teachers take attendance in under a minute per session, from a phone if needed."'));
ch.push(B('"Give me one screen that tells me which learners are slipping, before the parent calls."'));
ch.push(B('"Send every parent a professional progress report at the end of the period, in our branding, without me proofreading 40 documents."'));
ch.push(B('"Let me open a second branch without cloning a spreadsheet."'));
ch.push(B('"Stop teachers from seeing or editing data outside their own batches."'));
ch.push(B('"Show me proof, with a date and a name, of who changed a mark."'));
ch.push(BREAK());

ch.push(H1('4. Product Pillars'));
ch.push(P('Five commitments define the product. Every roadmap decision is tested against them.'));
ch.push(TBL(['Pillar', 'Commitment', 'What it rules out'], [
  ['Configurable, not custom', 'Terminology, ID formats, statuses, session types, attendance rules, assessment types, grading schemes, periods and report blocks are data with an admin UI.', 'Per-customer code branches; hard-coded "Homework/Classwork"; a fixed 0–100 score'],
  ['Proof of progress', 'The report is the product\u2019s output, not an afterthought: branded, evidence-backed, delivered on schedule, and logged.', 'Reporting as a CSV dump'],
  ['Built for many places', 'Multi-brand, multi-branch, multi-timezone, multi-calendar, multi-currency from the schema up.', 'One "current month" setting; a single national calendar'],
  ['Fits the customer\u2019s ecosystem', 'Microsoft-friendly by default (Outlook, Teams, Excel, Bookings, Entra ID), with every external service behind a swappable driver.', 'Lock-in to one mail or storage vendor'],
  ['Ownable', 'Cloud SaaS for most; a self-hosted licence for institutions that require data on their own infrastructure.', 'Cloud-only assumptions anywhere in the architecture'],
], [1900, 4600, 3246]));

ch.push(H1('5. Positioning'));
ch.push(P(`For **owners of cohort-based training businesses** who have outgrown spreadsheets but do not need a school information system, **${PRODUCT}** is the operations platform that runs branches, batches, attendance and grading, and turns them into progress reports parents and sponsors trust. Unlike an SIS (built for K-12 statutory reporting) or an LMS (built to host content), ${PRODUCT} is built around the cohort and configures itself to the customer\u2019s vocabulary, calendar and grading conventions rather than imposing another country\u2019s.`));
ch.push(H2('5.1 Competitive stance'));
ch.push(TBL(['Category', 'They win when', 'We win when'], [
  ['K-12 SIS', 'Customer is an accredited school with statutory reporting', 'Customer is a private centre that finds an SIS 80% irrelevant and too expensive'],
  ['LMS platforms', 'The value is content delivery and self-paced learning', 'The value is live cohorts, attendance and human assessment'],
  ['Class/booking software', 'The business is drop-in classes billed per session', 'The business is enrolled cohorts assessed over a period'],
  ['Spreadsheets', 'One branch, one teacher, no reporting expectations', 'Second branch, staff turnover, or anyone asking for evidence'],
], [1900, 3900, 3946]));
ch.push(BREAK());

ch.push(H1('6. Packaging & Pricing'));
ch.push(H2('6.1 Metric: per active learner per month'));
ch.push(P('The price scales with the value delivered and with the customer\u2019s own revenue, which makes it defensible in a negotiation. It requires one precise definition, published in the contract and visible in the app:'));
ch.push(NOTE('**Active learner** = a learner with at least one enrollment in Active status for one or more days during the billing period. Counted from a daily snapshot; the invoice uses the **peak** daily count in the period. Learners with Withdrawn, Completed or Graduated enrollments are never billed, and historical records are always free to keep.'));
ch.push(B('**Peak, not average** — simpler to explain and to audit; a customer can verify it from their own roster.'));
ch.push(B('**Live usage meter in-app** — current count, peak so far, and the projected invoice. An invoice must never be the first time a customer sees the number.'));
ch.push(B('**Never charge for history** — if archiving costs money, customers delete data, and the audit trail dies with it.'));

ch.push(H2('6.2 Indicative tiers (to be validated with real customers)'));
ch.push(TBL(['Plan', 'For', 'Shape', 'Key limits'], [
  ['Starter', 'Single-branch centre', 'Low per-learner rate, minimum monthly fee', '1 brand, 1 branch, core modules, email support'],
  ['Growth', 'Multi-branch / multi-brand', 'Same metric, higher ceiling features', 'Unlimited branches, custom domain, API access, Microsoft integrations'],
  ['Institution', 'Large or compliance-driven', 'Annual contract', 'SSO (Entra ID), data residency choice, DPA, priority support'],
  ['Self-hosted licence', 'Institutions requiring on-premises', 'Annual term licence, learner-cap tier', 'Signed licence key enforces cap and expiry; support contract separate'],
], [1500, 2100, 2500, 3646]));
ch.push(B('**Free trial:** 14–30 days, full features, no card required, capped learner count. Trial data converts to a paid tenant untouched.'));
ch.push(B('**Not gated:** audit logging, backups, and security features. Selling safety as an upsell is how a product loses trust.'));

ch.push(H2('6.3 Payments'));
ch.push(TBL(['Driver', 'Role', 'Status'], [
  ['Stripe (direct)', 'Default for card-paying cloud tenants; Stripe Tax handles VAT/GST', 'Build first'],
  ['Merchant of record (Paddle / Lemon Squeezy)', 'Fallback if the operating entity cannot use Stripe, or to outsource worldwide tax handling', 'Interface only, driver deferred'],
  ['Manual / offline invoice', 'Bank transfer for institutional buyers, on-premises licences, and markets without card payment', 'Build with Stripe — required from day one'],
  ['Local gateways (eSewa, Khalti, Razorpay, ...)', 'Regional expansion', 'Later phase'],
], [2600, 4600, 2546]));
ch.push(NOTE('Open commercial decision: the operating entity is undecided. Stripe requires an entity in a supported country — Nepal is not supported. If the entity ends up in Nepal, the default driver becomes a merchant of record. This decision is required before the first paid customer, not before the first line of code.'));
ch.push(BREAK());

ch.push(H1('7. Presets — the anti-configuration-fatigue mechanism'));
ch.push(P('A fully configurable product with empty defaults is unusable on day one. Presets are opinionated bundles chosen during signup that pre-populate everything; the customer adjusts later. **Target: a working tenant in under 15 minutes.**'));
ch.push(H2('7.1 What a preset sets'));
ch.push(...CODE([
  'preset = {',
  '  terminology     : learner|student|trainee, guardian on/off, batch|class|cohort',
  '  modules         : guardians, hangout-style sessions, rubrics, ...',
  '  session_types   : e.g. Class, Lab, Workshop, Make-up',
  '  attendance      : compulsory?, late-join?, grace minutes, counted types',
  '  assessment_types: e.g. Homework, Project, Quiz  (+ weights)',
  '  grading_scheme  : points | percentage | letter | pass-fail | rubric | CEFR',
  '  period_type     : monthly | term | quarter | cohort-block',
  '  calendar        : gregorian | bikram-sambat | hijri | jalali | buddhist',
  '  week_start      : Sun | Mon | Sat        weekend_days: [...]',
  '  id_sequences    : prefix / padding per entity',
  '  report_template : blocks, tone, language',
  '  locale/currency : en-GB, EUR, ...        retention defaults',
  '}',
]));
ch.push(H2('7.2 Launch preset catalogue'));
ch.push(TBL(['Preset', 'Vertical', 'Notable defaults'], [
  ['Kids tutoring centre — South Asia', 'Tutoring', 'Guardians on, monthly periods, points grading, Bikram Sambat option, Sunday week start'],
  ['After-school programme — North America', 'Tutoring', 'Guardians on, monthly periods, letter grades, Sunday week start, COPPA-aware retention'],
  ['Language school — Europe', 'Language', 'Guardians off by default, term periods, CEFR levels, Monday week start, GDPR retention'],
  ['Language school — Gulf', 'Language', 'Fri–Sat weekend, Hijri calendar option, term periods'],
  ['IT / skills institute', 'Tech', 'Guardians off, cohort-block periods, project + lab assessment types, employer as report recipient'],
  ['Corporate training provider', 'Tech', 'Participants, completion-focused reporting, employer sponsor records, compulsory attendance'],
  ['Blank / custom', 'Any', 'Minimal defaults for customers who want to configure from scratch'],
], [3000, 1500, 5246]));
ch.push(P('Presets are versioned data, editable without a deploy, and a tenant may re-apply parts of a preset later (e.g., adopt a new report template) without losing customizations.'));
ch.push(BREAK());

ch.push(H1('8. Go-to-Market'));
ch.push(H2('8.1 Sequence'));
ch.push(N('**Design partners (3–5 centres).** Manually provisioned, free or heavily discounted, in exchange for weekly feedback and a reference quote. These shape onboarding before it is automated.'));
ch.push(N('**Manual paid onboarding.** Real invoices, sales-assisted setup, presets refined per vertical. This is where pricing gets validated — not in a spreadsheet.'));
ch.push(N('**Self-serve signup.** Public trial, subdomain provisioning, card checkout, in-product onboarding. Only worth building once step 2 proves people pay.'));
ch.push(N('**Channel and vertical expansion.** Partnerships with franchise networks and teacher associations; localized presets per new market; self-hosted licence for institutions.'));
ch.push(H2('8.2 First-market logic'));
ch.push(P('Start where the founder has network and can visit customers in person — the first five customers are won by trust, not by a landing page. Expand to English-speaking and European markets once presets and reports are proven, because those markets pay more per learner and are better served by the multi-timezone and compliance work already in the product.'));

ch.push(H1('9. Success Metrics'));
ch.push(TBL(['Stage', 'Metric', 'Target'], [
  ['Activation', 'Tenant reaches "first report sent" within 7 days of signup', '> 60% of trials'],
  ['Time to value', 'Signup → first attendance recorded', '< 60 minutes'],
  ['Engagement', 'Batches with attendance recorded in the last 7 days', '> 80% of active batches'],
  ['Retention', 'Logo retention after 12 months', '> 85% (annual cycles make churn seasonal)'],
  ['Revenue quality', 'Net revenue retention (learner growth within accounts)', '> 100%'],
  ['Efficiency', 'Infrastructure cost per active learner per month', '< 2% of price'],
  ['Support load', 'Tickets per tenant per month', '< 1 after month two'],
], [1800, 5100, 2846]));

ch.push(H1('10. Business Risks'));
ch.push(TBL(['Risk', 'Impact', 'Response'], [
  ['Configurability becomes complexity; customers stall in setup', 'High — kills activation', 'Presets, progressive disclosure, "effective value" display, in-product checklist. Activation metric is the canary.'],
  ['Single-founder bandwidth vs. support load', 'High', 'Ship fewer modules, better; self-serve docs; deliberate cap on design partners'],
  ['Undefined operating entity blocks payment collection', 'Blocking at first sale', 'Decide before P3; manual invoicing works in the interim'],
  ['Compliance burden (minors\u2019 data across GDPR/COPPA)', 'Blocks EU/US customers', 'SL-SEC-004 covers DPA, DSAR, retention and residency; ship the paperwork with the product'],
  ['Price metric disputes ("what is active?")', 'Medium — trust damage', 'Published definition, in-app meter, exportable usage history'],
  ['Provisional product name proves unusable', 'Low — cosmetic', 'No product name in code, namespaces, or document IDs'],
], [3000, 1900, 4846]));

ch.push(H1('11. Delivery Phases (summary)'));
ch.push(P('Detailed plan in SL-PLN-008. Requirements in SL-SRS-001 are tagged with these phases.'));
ch.push(TBL(['Phase', 'Outcome', 'Sellable?'], [
  ['P1 — Academic core', 'Tenant-scoped platform: org hierarchy, courses, batches, sessions, people, attendance, dynamic grading, notes, reports, audit', 'No — internal / design partners'],
  ['P2 — Tenant-ready', 'Presets, first-run onboarding, terminology, per-brand branding and email, platform-admin console with impersonation', 'Yes — manually onboarded'],
  ['P3 — Commercial', 'Plans, entitlements, metering, Stripe + manual invoicing, trials, dunning, usage meter', 'Yes — real money, sales-assisted'],
  ['P4 — Self-serve', 'Public signup, subdomains, custom domains, billing portal, in-product onboarding', 'Yes — scalable acquisition'],
  ['P5 — Distribution', 'On-premises packaging and licensing, Microsoft Teams/Outlook/Entra drivers, learner & guardian portals', 'Yes — enterprise and channel'],
], [1700, 5400, 2646]));

ch.push(H1('12. Open Decisions'));
ch.push(TBL(['#', 'Decision', 'Needed by', 'Current default'], [
  ['D1', 'Final product name and trademark clearance', 'Before public launch (P4)', `${PRODUCT} (provisional)`],
  ['D2', 'Operating entity and jurisdiction', 'Before first paid invoice (P3)', 'Assume EU entity; revisit'],
  ['D3', 'Stripe direct vs merchant of record', 'Follows D2', 'Stripe direct + manual invoicing'],
  ['D4', 'Launch pricing points per tier', 'Before P3, from design-partner conversations', 'Placeholder tiers in §6.2'],
  ['D5', 'First hosting region', 'Before first production tenant', 'Single region; per-tenant region field exists from P1'],
  ['D6', 'Vertical to lead with in marketing', 'Before P4', 'Tutoring centres (fastest to demo)'],
], [500, 3900, 2500, 2846]));

const doc = buildDoc(ch, 'SL-PRD-000 · Product Vision & Business Requirements · snova-labs');
save(doc, '/home/claude/tut-docs/out/00_Product_Vision_and_Business_Requirements.docx');
