// Doc 03 — Data Model & Database Design ; Doc 04 — Multi-Tenancy, Security & Compliance
const L = require('./tlib');
const { H1, H2, H3, P, B, N, NOTE, CODE, TBL, BREAK, cover, buildDoc, save, TableOfContents, PRODUCT } = L;

// ============================== DOC 03 ==============================
let ch = [];
ch.push(...cover(
  'Data Model & Database Design',
  'MySQL 8 schema, tenancy columns, dynamic grading model, periods, metering and audit',
  'SL-DAT-003',
  [['Parent', 'SL-SRS-001 (Requirements) · SL-ARC-002 (Architecture §3 tenancy, §6 extension points)']]
));
ch.push(H1('Table of Contents'));
ch.push(new TableOfContents('TOC', { hyperlink: true, headingStyleRange: '1-2' }));
ch.push(BREAK());

ch.push(H1('1. Conventions'));
ch.push(B('Engine **InnoDB**; charset **utf8mb4**, collation `utf8mb4_unicode_ci` — required for names, scripts and emoji across all target markets.'));
ch.push(B('Primary keys `BIGINT UNSIGNED AUTO_INCREMENT`. Business identifiers live in separate columns (`number`, `code`) produced by the sequence engine (§9) and are **never** derived from the auto-increment.'));
ch.push(B('**`tenant_id` is mandatory** on every tenant-owned table, indexed first in most composite indexes. A table without it must be explicitly declared global (lookup defaults, plans, regions, operator tables).'));
ch.push(B('Timestamps `created_at`, `updated_at` in UTC on every table; `deleted_at` on soft-deletable domain tables; `created_by` / `updated_by` referencing `users` on tables users write.'));
ch.push(B('Instants are UTC (`*_at_utc` where the local companion also exists). Local calendar values are stored alongside as `*_local_date` / `*_local_time` with the owning entity\u2019s timezone recorded (SL-ARC-002 §12).'));
ch.push(B('Foreign keys: `RESTRICT` on academic history; `CASCADE` only for pure child rows (rubric criteria under an assessment, delivery rows under a report).'));
ch.push(B('Controlled vocabularies are **lookup tables scoped to the tenant**, never MySQL `ENUM` — every status set is customer-editable.'));
ch.push(B('Flexible payloads use JSON columns (`settings.value`, `report_templates.blocks`, `grading_schemes.config`, `learners.custom`) — structured where it must be queried, JSON where it must be extensible.'));
ch.push(B('Naming: snake_case, plural tables, singular FK columns, pivot tables named alphabetically (`batch_teacher`).'));

ch.push(H1('2. Entity Overview'));
ch.push(...CODE([
  'CONTROL PLANE (global)',
  '  regions   plans   plan_features   operators   operator_audit_logs',
  '  tenants ─1:n─ subscriptions ─1:n─ invoices',
  '  tenants ─1:n─ usage_snapshots            tenants ─1:1─ licences (on-prem)',
  '',
  'TENANT PLANE (all rows carry tenant_id)',
  '  brands ─1:n─ branches ─1:n─ batches ─n:1─ courses',
  '  branches ─1:n─ holidays          batches ─1:n─ timetable_slots',
  '  batches  ─1:n─ sessions ─n:1─ session_types',
  '  batches  ─n:n─ users (batch_teacher)',
  '  learners ─n:n─ guardians (guardian_learner)',
  '  learners ─1:n─ enrollments ─n:1─ batches',
  '  enrollments ─1:n─ attendance_records ─n:1─ sessions',
  '  batches  ─1:n─ assessments ─1:n─ rubric_criteria',
  '  enrollments ─1:n─ grades ─n:1─ assessments',
  '  grades   ─1:n─ grade_rubric_scores',
  '  enrollments ─1:n─ teacher_notes',
  '  enrollments ─1:n─ reports ─1:n─ report_deliveries',
  '  settings  id_sequences  terminology_overrides  lookup tables',
  '  audit_logs (append-only)   imports   exports',
]));
ch.push(P('**The enrollment is the anchor of all academic data**, never the learner. This is what makes transfers, repeat cohorts, and learners taking several courses at once behave correctly: a learner\u2019s history is the union of their enrollments, each frozen to the batch context in which it happened.'));
ch.push(BREAK());

ch.push(H1('3. Control-Plane Tables'));
ch.push(TBL(['Table', 'Key columns', 'Notes'], [
  ['regions', 'code, name, storage_disk, db_connection, is_active', 'One row initially; per-tenant region assignment requires no schema change (FR-TEN-5).'],
  ['tenants', 'name, slug UNIQUE, region_id, preset_code, status (trial/active/past_due/suspended/cancelled/purged), trial_ends_at, contact fields, deployment_mode (cloud/self_hosted), purge_after', 'The isolation boundary. `status` drives what tenant users may still do.'],
  ['plans', 'code UNIQUE, name, currency, unit_price, interval, min_charge, is_public, sort', 'Data, not code (FR-ENT-1).'],
  ['plan_features', 'plan_id, feature_key, value (bool/int/json), enforcement (soft/hard)', 'Limits and feature flags; enforcement style declared per limit (FR-ENT-3).'],
  ['tenant_entitlement_overrides', 'tenant_id, feature_key, value, reason, granted_by, expires_at', 'Negotiated deals, audited (FR-ENT-4).'],
  ['subscriptions', 'tenant_id, plan_id, provider (stripe/mor/manual), provider_ref, status, current_period_start/end, cancel_at_period_end', 'Provider-agnostic; see SL-BIL-006.'],
  ['usage_snapshots', 'tenant_id, snapshot_date, active_learners, breakdown JSON, is_corrected, corrected_by, correction_reason', 'Immutable daily row; UNIQUE(tenant_id, snapshot_date). Basis of every invoice (FR-MTR-1).'],
  ['invoices', 'tenant_id, subscription_id, period_start/end, quantity, unit_price, subtotal, tax, total, currency, provider_ref, status, issued_at, paid_at', 'Quantity always traceable to snapshots.'],
  ['licences', 'tenant_id, key_fingerprint, learner_cap, features JSON, issued_at, expires_at, last_validated_at', 'Self-hosted only (FR-LIC-1).'],
  ['operators', 'name, email, password, two_factor_secret (required), is_active', 'Separate identity from tenant users (FR-OPS-1).'],
  ['operator_sessions / impersonations', 'operator_id, tenant_id, user_id, reason, started_at, ended_at, ip', 'Written to both operator and tenant audit logs (FR-OPS-2, FR-AUD-3).'],
], [2200, 4400, 3146]));

ch.push(H1('4. Organization & Configuration'));
ch.push(TBL(['Table', 'Key columns', 'Notes'], [
  ['brands', 'tenant_id, name, code, logo_path, colors JSON, report_template_id, sender_name, sender_email, domain, locale, is_default', 'Branding lives here, not on the tenant (FR-ORG-1).'],
  ['branches', 'tenant_id, brand_id, name, code, timezone (IANA), week_start, weekend_days JSON, address JSON, phone, email, is_active', 'Timezone and week rules originate here (SL-LOC-005).'],
  ['holidays', 'tenant_id, branch_id, date, name, blocks_sessions BOOL', 'Session generation skips these (FR-ORG-3).'],
  ['settings', 'tenant_id, key, value JSON, scope_type (tenant/brand/branch/course/batch), scope_id, updated_by', 'UNIQUE(tenant_id, key, scope_type, scope_id). Resolver in SL-ARC-002 §7.'],
  ['id_sequences', 'tenant_id, entity, scope_type, scope_id, prefix, separator, pad_width, next_number, is_active', 'UNIQUE(tenant_id, entity, scope_type, scope_id). Algorithm in §9.'],
  ['terminology_overrides', 'tenant_id, term_key (learner/guardian/batch/...), singular, plural, locale', 'Presentation-layer only; API keeps canonical names (FR-CFG-4).'],
  ['presets / preset_applications', 'code, version, payload JSON / tenant_id, preset_code, version, applied_at, applied_by, components JSON', 'Versioned seeds; re-application is tracked so conflicts can be shown (FR-ONB-5).'],
], [2200, 4400, 3146]));
ch.push(P('**Tenant-scoped lookup tables** (all with `tenant_id`, `name`, `code`, `color`, `sort`, `is_active`, plus the behavior flags noted): `session_types` (counts_in_attendance), `attendance_statuses` (counts_as_attended, is_negative), `submission_statuses` (counts_as_submitted, excluded_from_average), `enrollment_statuses` (is_active_for_billing, is_terminal), `learner_statuses`, `note_categories` (report_visible_default), `assessment_types` (counts_in_submission_rate, default_weight), `relation_types`, `custom_field_definitions`.'));
ch.push(NOTE('`enrollment_statuses.is_active_for_billing` is the single flag that decides what the metering job counts. Putting it on the lookup row means a tenant can add "On hold — unpaid" without anyone touching billing code, and the definition stays auditable.'));
ch.push(BREAK());

ch.push(H1('5. Academic Structure'));
ch.push(TBL(['Table', 'Key columns', 'Notes'], [
  ['courses', 'tenant_id, brand_id, name, code, audience, description, default_attendance_policy_id, default_grading_scheme_id, period_type, is_active', 'Adding a programme is data entry (FR-SCH-1).'],
  ['batches', 'tenant_id, course_id, branch_id, name, code, timezone, starts_on, ends_on, capacity, delivery_mode, status', 'Timezone defaults from branch, overridable for online cohorts (FR-SCH-2).'],
  ['timetable_slots', 'tenant_id, batch_id, session_type_id, weekday, start_time_local, duration_min, effective_from, effective_to', 'Template for generation; effective dates allow a mid-term schedule change.'],
  ['sessions', 'tenant_id, batch_id, session_type_id, session_local_date, starts_at_utc, ends_at_utc, status (scheduled/held/cancelled), cancel_reason, meeting_url, meeting_provider_ref, generated_from_slot_id', 'Each occurrence converted individually so DST is handled per date (FR-SCH-7).'],
  ['batch_teacher', 'tenant_id, batch_id, user_id, role (lead/assistant)', 'Teacher visibility scope.'],
  ['reporting_periods', 'tenant_id, course_id or batch_id, type (monthly/term/quarter/block/custom), label, starts_local_date, ends_local_date, status (open/closed/reported)', 'Replaces any month-string assumption; boundaries computed by PeriodService (SL-LOC-005 §5).'],
], [2200, 4400, 3146]));

ch.push(H1('6. People & Enrollment'));
ch.push(TBL(['Table', 'Key columns', 'Notes'], [
  ['learners', 'tenant_id, number, legal_name, preferred_name, dob, gender_id, country, home_timezone, email, phone, photo_path, status_id, status_reason, status_changed_on, custom JSON', 'Single `legal_name` + `preferred_name` avoids first/last assumptions (SL-LOC-005 §7).'],
  ['guardians', 'tenant_id, name, relation_id, email, secondary_email, phone, preferred_channel, locale, timezone', 'First-class; module disableable for adult tenants.'],
  ['guardian_learner', 'tenant_id, guardian_id, learner_id, is_primary, receives_reports', 'Report fan-out reads `receives_reports`.'],
  ['sponsors / sponsor_enrollment', 'tenant_id, name, contact JSON / enrollment_id, sponsor_id, receives_reports', 'Corporate training recipients (FR-PPL-7).'],
  ['enrollments', 'tenant_id, learner_id, batch_id, number, enrolled_on, status_id, status_reason, ended_on, transferred_to_enrollment_id', 'UNIQUE(tenant_id, learner_id, batch_id). Anchor of academic data.'],
  ['enrollment_status_history', 'enrollment_id, from_status_id, to_status_id, reason, changed_by, changed_at', 'Needed for correct mid-period metering and for disputes.'],
  ['users / staff_profiles / invitations', 'see SL-SEC-004 §4', 'Login identity separate from person records (FR-IAM-8).'],
], [2200, 4400, 3146]));
ch.push(BREAK());

ch.push(H1('7. Attendance'));
ch.push(TBL(['Table', 'Key columns', 'Notes'], [
  ['attendance_policies', 'tenant_id, scope_type (course/batch), scope_id, is_compulsory, allow_late_join, late_grace_min, counted_session_type_ids JSON, low_threshold_pct', 'Resolved batch → course → tenant default (FR-ATT-2).'],
  ['attendance_records', 'tenant_id, session_id, enrollment_id, status_id, minutes_late, note, recorded_by, recorded_at', 'UNIQUE(session_id, enrollment_id) — resubmission updates, never duplicates (FR-ATT-3).'],
  ['makeup_links', 'tenant_id, missed_session_id, makeup_session_id, enrollment_id', 'Language schools especially need this (FR-ATT-6).'],
], [2200, 4400, 3146]));
ch.push(P('**Attendance rate** = records whose status `counts_as_attended` ÷ sessions with status `held` of counted types, within the period boundaries computed in the batch timezone. Where `is_compulsory = false`, the rate is still displayed but marked informational and excluded from any engagement or risk calculation.'));

ch.push(H1('8. Assessment & Grading'));
ch.push(TBL(['Table', 'Key columns', 'Notes'], [
  ['grading_schemes', 'tenant_id, name, kind (points/percentage/pass_fail/letter/rubric/level), config JSON', 'config holds max_points, letter bands, or level ladder (e.g., CEFR A1–C2).'],
  ['assessments', 'tenant_id, batch_id, assessment_type_id, grading_scheme_id, number, title, description, assigned_local_date, due_at_utc, due_local_date, max_points, is_published', 'Due dates in batch timezone (FR-GRD-4).'],
  ['rubric_criteria', 'tenant_id, assessment_id, name, max_points, sort', 'CASCADE with assessment.'],
  ['grades', 'tenant_id, assessment_id, enrollment_id, submission_status_id, raw_score, normalized_pct, letter, level_code, feedback, graded_by, graded_at', 'UNIQUE(assessment_id, enrollment_id). `normalized_pct` is the only column analytics read.'],
  ['grade_rubric_scores', 'tenant_id, grade_id, rubric_criterion_id, points', 'Sums to raw_score.'],
  ['type_weights', 'tenant_id, course_id, assessment_type_id, weight_pct', 'Weighted period average; simple mean when absent (FR-GRD-6).'],
], [2200, 4400, 3146]));
ch.push(NOTE('Normalization rule: each scheme strategy writes a 0–100 value. Pass/fail → 100/0; letter and level → the configured band midpoint unless a value is specified; rubric → total ÷ criteria maxima. Statuses flagged `excluded_from_average` (e.g., Exempt) store NULL and are removed from denominators, never scored as zero. Adding a scheme therefore never touches dashboard, report or trend code.'));

ch.push(H1('9. Identifier Sequences'));
ch.push(...CODE([
  '-- issued inside the surrounding transaction',
  'UPDATE id_sequences',
  '   SET next_number = LAST_INSERT_ID(next_number + 1)',
  ' WHERE tenant_id = ? AND entity = ?',
  '   AND scope_type = ? AND (scope_id <=> ?) AND is_active = 1;',
  'SELECT LAST_INSERT_ID();',
  '',
  '-- format: {prefix}{separator}{number left-padded to pad_width}',
  '-- prefix may embed brand/branch code, e.g. "ACME-KTM-STU"',
  '-- → ACME-KTM-STU-0007',
]));
ch.push(B('Configurable per entity and optionally per brand or branch (FR-CFG-3). Changing a format affects only future records; existing identifiers are immutable.'));
ch.push(B('A tenant migrating from spreadsheets can set `next_number` to continue their previous numbering, which removes the usual objection to switching systems.'));
ch.push(BREAK());

ch.push(H1('10. Notes, Reports & Communication'));
ch.push(TBL(['Table', 'Key columns', 'Notes'], [
  ['teacher_notes', 'tenant_id, enrollment_id, reporting_period_id, category_id, body, is_report_visible, author_id', 'Internal notes never render to recipients (FR-NTE-1).'],
  ['report_templates', 'tenant_id, brand_id, course_id, name, blocks JSON, locale, is_default', 'Blocks toggle and order sections (FR-RPT-2).'],
  ['reports', 'tenant_id, enrollment_id, reporting_period_id, template_id, number, file_path, file_disk, stats_snapshot JSON, generated_by, generated_at', 'Snapshot freezes the numbers as sent (FR-RPT-5).'],
  ['report_runs', 'tenant_id, batch_id, reporting_period_id, options JSON, status, total, succeeded, failed, started_at, finished_at', 'Bulk run progress and partial-failure reporting (FR-RPT-3).'],
  ['report_deliveries', 'tenant_id, report_id, recipient_type (guardian/learner/sponsor), recipient_id, channel, to_address, status, provider, provider_ref, error, sent_at, retry_count', 'One row per recipient; retryable (FR-RPT-4).'],
  ['email_templates', 'tenant_id, brand_id, code, subject, body_html, variables JSON, locale', 'Per-brand transactional templates (FR-NTF-1).'],
], [2200, 4400, 3146]));

ch.push(H1('11. Audit, Imports & Exports'));
ch.push(TBL(['Table', 'Key columns', 'Notes'], [
  ['audit_logs', 'tenant_id, actor_type (user/operator/system), actor_id, actor_name, module, action, auditable_type, auditable_id, target_label, before JSON, after JSON, ip, occurred_at', 'Append-only: the application database user holds no UPDATE or DELETE grant on this table.'],
  ['imports', 'tenant_id, type, file_path, status, totals JSON, rejects_path, run_by, finished_at', 'Validation preview then transactional commit (FR-PPL-5).'],
  ['exports', 'tenant_id, type, scope JSON, file_path, expires_at, requested_by', 'Includes the full-tenant export used for portability and data-subject requests (FR-DAT-1).'],
], [2200, 4400, 3146]));

ch.push(H1('12. Indexing & Performance'));
ch.push(B('Composite indexes lead with `tenant_id`, since every query is tenant-scoped: e.g. `(tenant_id, batch_id, session_local_date)` on sessions, `(tenant_id, enrollment_id, graded_at)` on grades.'));
ch.push(B('Uniqueness that must hold per tenant includes `tenant_id` in the key: learner `number`, branch `code`, batch `code`, sequence scope.'));
ch.push(B('Hot paths: `attendance_records (session_id, enrollment_id)` UNIQUE plus `(tenant_id, enrollment_id)`; `grades (assessment_id, enrollment_id)` UNIQUE; `enrollments (tenant_id, batch_id, status_id)`; `audit_logs (tenant_id, auditable_type, auditable_id, occurred_at)`; `usage_snapshots (tenant_id, snapshot_date)`.'));
ch.push(B('Period rollups are computed live for a batch (typically ≤ 40 learners) and cached by (enrollment, period), invalidated on write. An optional `period_stats` materialization table is reserved for scale and is not built until measurements justify it.'));

ch.push(H1('13. Integrity & Deletion Policy'));
ch.push(TBL(['Action', 'Behavior'], [
  ['Delete a learner', 'Soft delete; enrollments and academic history retained; hard erasure only via the data-subject request workflow (SL-SEC-004 §8), which is audited.'],
  ['Delete a batch/course with history', 'Blocked. Archive instead — status change preserves everything and stops new activity.'],
  ['Delete an assessment with grades', 'Blocked unless grades are empty; otherwise unpublish.'],
  ['Cancel a session', 'Status change with reason; attendance rows retained and excluded from rate denominators.'],
  ['Purge a tenant', 'Control-plane action after the retention window, irreversible, preceded by an offered export and recorded in operator audit.'],
], [2400, 7346]));

ch.push(H1('14. Schema Evolution'));
ch.push(B('Every change ships as a reversible migration; destructive changes are split across releases (add column → backfill → switch reads → drop later).'));
ch.push(B('Lookup and preset seed data ships as idempotent seeders keyed by code, so existing tenants receive new defaults without overwriting customizations.'));
ch.push(B('Any new tenant-owned table is created with `tenant_id` and its isolation test in the same commit (NFR-ISO-1) — a table without its test does not merge.'));

let doc = buildDoc(ch, 'SL-DAT-003 · Data Model & Database Design · snova-labs');
save(doc, '/home/claude/tut-docs/out/03_Data_Model_and_Database_Design.docx');

// ============================== DOC 04 ==============================
ch = [];
ch.push(...cover(
  'Multi-Tenancy, Security & Compliance',
  'Isolation guarantees, access control, minors\u2019 data protection, and the paperwork required to sell internationally',
  'SL-SEC-004',
  [['Parent', 'SL-SRS-001 §6.2 and §6.4 · SL-ARC-002 §3']]
));
ch.push(H1('Table of Contents'));
ch.push(new TableOfContents('TOC', { hyperlink: true, headingStyleRange: '1-2' }));
ch.push(BREAK());

ch.push(H1('1. Why This Document Exists'));
ch.push(P(`${PRODUCT} processes personal data about **children**, on behalf of customers, across jurisdictions with strict and differing rules. Two failure modes would end the business rather than merely damage it: one tenant seeing another\u2019s data, and a breach of minors\u2019 data handled badly. Everything here is written to make those two outcomes structurally unlikely — and to make the second survivable if it ever happens.`));
ch.push(NOTE('This document describes engineering and operational controls. It is not legal advice; the contractual artifacts in §11 must be reviewed by a qualified adviser in the operating jurisdiction before the first EU or US customer signs.'));

ch.push(H1('2. Threat Model'));
ch.push(TBL(['Threat', 'Likelihood', 'Impact', 'Primary control'], [
  ['Cross-tenant data exposure via a missing query filter', 'Medium — the classic multi-tenant bug', 'Catastrophic', 'Automatic scoping at the data layer + per-resource isolation tests (§3)'],
  ['Privilege escalation inside a tenant (teacher reads other branches)', 'Medium', 'High', 'Permission checks plus scope enforcement on every route (§5)'],
  ['Credential stuffing / weak staff passwords', 'High', 'High', 'Throttling, breach-password rejection, optional and enforceable 2FA (§4)'],
  ['Operator misuse of impersonation', 'Low', 'High — trust destroying', 'Reason required, time-limited, visible to the tenant, audited both sides (§6)'],
  ['Learner PII leaking through logs, URLs or error reports', 'Medium', 'High', 'PII policy: no identifiers in paths or logs; scrubbed error reporting (§7)'],
  ['Unauthorized access to generated report PDFs', 'Medium', 'High', 'Tenant-scoped storage paths, no public buckets, expiring signed links (§7)'],
  ['Backup exposure', 'Low', 'Catastrophic', 'Encrypted backups, restricted access, tested restores (§12)'],
  ['Dependency vulnerability', 'Medium', 'Medium–High', 'Automated scanning in CI, patch SLA (§13)'],
  ['Insider access by the operator to customer data without cause', 'Low', 'High', 'Least privilege, audited access, no routine production data browsing (§6)'],
], [3100, 1300, 1400, 3946]));

ch.push(H1('3. Tenant Isolation'));
ch.push(H2('3.1 Layered controls'));
ch.push(TBL(['Layer', 'Control', 'Failure mode it stops'], [
  ['Request', 'Tenant resolved once from the authenticated identity (later: domain) and bound to the request context before any query runs', 'Code guessing the tenant from user input'],
  ['Data', 'Global scope on every tenant-owned model filters by the bound tenant and stamps `tenant_id` on create', 'A developer forgetting a `where` clause'],
  ['Authorization', 'Policies re-verify ownership on read and write; foreign identifiers resolve as not-found rather than forbidden', 'Existence leakage through error differences'],
  ['Background work', 'Jobs carry the tenant identifier and re-bind context; a job with no resolvable tenant fails loudly', 'Unscoped queries in queued work — the most common real-world leak'],
  ['Files', 'Storage paths include the tenant; access only through authorized, expiring links', 'Guessable file URLs'],
  ['Tests', 'Every resource has an isolation test across UI, API and export routes; required before merge', 'Regression after a refactor'],
], [1500, 4600, 3646]));
ch.push(H2('3.2 Isolation test pattern'));
ch.push(...CODE([
  'for each resource R:',
  '  given tenant A with record X, tenant B with user U',
  '  assert U cannot read X            (UI route)   -> 404',
  '  assert U cannot read X            (API route)  -> 404',
  '  assert U cannot update/delete X                -> 404',
  '  assert X never appears in U\'s list/search/export',
  '  assert a queued job for tenant A never touches tenant B rows',
]));
ch.push(B('These tests are generated from the resource registry so that adding a resource without its test is a build failure, not a review oversight.'));
ch.push(BREAK());

ch.push(H1('4. Authentication'));
ch.push(B('Passwords hashed with a modern adaptive algorithm; minimum length enforced with a check against known-breached password lists; no forced rotation (which harms security in practice).'));
ch.push(B('Login throttling per account and per address; generic failure messages; alert email on new-device sign-in.'));
ch.push(B('Sessions: secure, HTTP-only, same-site cookies; absolute and idle expiry; all sessions invalidated on password change; visible session list with remote revoke (P3).'));
ch.push(B('Password reset tokens single-use, short-lived, and invalidated on use or on password change.'));
ch.push(B('Staff invitations use expiring single-use tokens; a pending invitation grants nothing until accepted.'));
ch.push(B('Two-factor (TOTP) optional per user from P3, and requirable by role — mandatory for any role holding `settings.manage` or `billing.manage` is the recommended tenant default.'));
ch.push(B('Operator accounts require two-factor unconditionally from the first release.'));
ch.push(B('Entra ID SSO (P5) is configured per tenant; local passwords can then be disabled for that tenant.'));

ch.push(H1('5. Authorization'));
ch.push(H2('5.1 Permission catalogue (initial)'));
ch.push(TBL(['Group', 'Permissions'], [
  ['People', '`learners.view` `learners.create` `learners.update` `learners.archive` `learners.delete` `guardians.*`'],
  ['Academic', '`courses.*` `batches.*` `sessions.manage` `enrollments.manage`'],
  ['Attendance', '`attendance.view` `attendance.record` `attendance.amend`'],
  ['Grading', '`assessments.manage` `grades.view` `grades.enter` `grades.amend`'],
  ['Notes', '`notes.view` `notes.write` `notes.view_internal`'],
  ['Reports', '`reports.generate` `reports.send` `reports.view_archive`'],
  ['Analytics', '`dashboard.branch` `dashboard.tenant`'],
  ['Administration', '`users.manage` `roles.manage` `settings.manage` `branding.manage` `audit.view` `data.export` `billing.manage`'],
], [1500, 8246]));
ch.push(H2('5.2 Rules'));
ch.push(B('No code may branch on a user "type". Every decision reads a named permission (FR-IAM-2), which is what allows the Tenant Owner to invent roles without a deployment.'));
ch.push(B('Scope is orthogonal to permission: a user with `attendance.record` still only reaches their assigned branches and, for teachers, their assigned batches. Both are checked server-side (FR-IAM-3).'));
ch.push(B('The interface hides what a user cannot do, but hiding is never the control — every action re-authorizes on the server.'));
ch.push(B('Sensitive reads have their own permissions (`notes.view_internal`, `audit.view`) rather than riding on a general admin flag.'));

ch.push(H1('6. Operator Access'));
ch.push(B('Operators hold no standing access to tenant application data. Support access happens through impersonation, which requires a stated reason, is time-limited, and ends automatically.'));
ch.push(B('An active impersonation is visually obvious in the interface, cannot perform billing changes, and is recorded in **both** the operator log and the tenant\u2019s own audit log so customers can see when we looked (FR-AUD-3).'));
ch.push(B('Production database access for engineers is exceptional, logged, and never the routine debugging path; application-level observability is built well enough that it does not need to be.'));
ch.push(B('Operator accounts are individually named — no shared credentials — and are deprovisioned as part of an offboarding checklist.'));
ch.push(BREAK());

ch.push(H1('7. Data Protection by Design'));
ch.push(H2('7.1 Personal data inventory'));
ch.push(TBL(['Category', 'Examples', 'Class', 'Handling'], [
  ['Learner identity', 'Name, date of birth, photo, country', 'Sensitive (minors)', 'Access requires scope grant; never in logs or URLs; export/erase supported'],
  ['Guardian contact', 'Name, email, phone', 'Personal', 'Same as above; used only for delivery and notification'],
  ['Academic records', 'Attendance, grades, notes', 'Sensitive (opinion about a child)', 'Internal notes never leave the tenant; report-visible flag is explicit'],
  ['Staff data', 'Name, email, role, activity', 'Personal', 'Audit entries retained per policy'],
  ['Operational', 'Logs, error reports, metrics', 'Pseudonymous', 'Identifiers only; personal data scrubbed at source'],
  ['Billing', 'Company details, tax IDs; card data', 'Financial', 'Card data never touches our systems — held by the payment provider'],
], [1700, 2700, 1900, 3446]));
ch.push(H2('7.2 Standing rules'));
ch.push(B('No learner or guardian personal data in URLs, query strings, log lines, error tracking payloads, or support tickets. Identifiers only.'));
ch.push(B('Error reporting is configured with scrubbing rules and reviewed whenever a new field is added.'));
ch.push(B('Generated PDFs and uploads live under tenant-scoped paths in private storage and are served only through short-lived signed links.'));
ch.push(B('Test and demo environments never contain production personal data; presets and seeded fixtures use neutral names ("Sample Learner").'));
ch.push(B('Data minimization: optional fields stay optional, and no field is collected because it might be useful later.'));

ch.push(H1('8. Data Subject Rights'));
ch.push(P('In the usual arrangement the **tenant is the data controller** and **snova-labs is the processor**. Requests therefore arrive at the customer, who must be able to satisfy them without contacting us — so the capability is a product feature, not a support process (NFR-SEC-3).'));
ch.push(TBL(['Right', 'Product capability', 'Phase'], [
  ['Access / portability', 'Per-learner data package and full-tenant export in open formats', 'P2'],
  ['Rectification', 'Standard editing with audit trail', 'P1'],
  ['Erasure', 'Learner/guardian deletion workflow with configurable handling of dependent academic records; deletion itself is audited', 'P3'],
  ['Restriction / objection', 'Status change to suspend processing while retaining the record', 'P2'],
  ['Information', 'Retention settings and a privacy summary the tenant can share with families', 'P3'],
], [1800, 6300, 1646]));

ch.push(H1('9. Retention & Deletion'));
ch.push(TBL(['Data', 'Default retention', 'Configurable?'], [
  ['Active academic records', 'Life of the tenant account', 'No — the customer\u2019s data'],
  ['Withdrawn learner records', 'Tenant-defined; default retain', 'Yes — per tenant, expressed in years'],
  ['Audit logs', '24 months online, then archive', 'Yes (P3)'],
  ['Report PDFs', 'Retained; storage lifecycle may move old periods to cold storage', 'Yes'],
  ['Operational logs', '30–90 days', 'No'],
  ['Backups', '30 daily, 12 monthly', 'No'],
  ['Cancelled tenant data', 'Retention window (default 30 days) then irreversible purge', 'Yes, by agreement'],
], [3000, 4300, 2446]));
ch.push(NOTE('Deletion must be genuine: a purge removes rows, files and backup inclusion going forward, and is recorded. "Soft deleted forever" is not a retention policy and will not satisfy a regulator.'));

ch.push(H1('10. Jurisdictional Requirements'));
ch.push(TBL(['Regime', 'Applies when', 'Key obligations for us', 'Product implication'], [
  ['GDPR (EU) / UK GDPR', 'Any learner or guardian in the EU/UK', 'Act only on documented instructions; processing agreement; sub-processor transparency; assist with subject rights; breach notification to the controller without undue delay', 'DPA template, sub-processor page, export/erase features, breach runbook'],
  ['UK Age Appropriate Design Code', 'Services processing children\u2019s data in the UK', 'Data minimization, high-privacy defaults, no nudging toward weaker settings', 'Conservative defaults; no behavioral profiling; no third-party trackers in the app'],
  ['COPPA (US)', 'Under-13 learners in the US', 'Verifiable parental consent obtained by the operator of the service to the child — normally the school/centre', 'We support the tenant\u2019s consent record-keeping; we do not market to children or collect beyond need'],
  ['FERPA-adjacent expectations (US)', 'Education records', 'Act as a "school official" under the customer\u2019s direction', 'Contractual language; no secondary use of customer data'],
  ['PIPEDA (Canada) / Privacy Act (Australia)', 'Learners in those countries', 'Consent, access, correction, breach reporting', 'Same feature set satisfies these'],
  ['Local data-residency requests', 'Institutional or public-sector buyers', 'Store and process in a stated region', 'Per-tenant region attribute exists from P1 (FR-TEN-5)'],
], [1900, 1900, 3200, 2746]));
ch.push(NOTE('Standing commitment: customer data is never used to train models, sold, or shared for advertising. This is stated in the agreement and is a competitive advantage in this segment — say it plainly on the website.'));

ch.push(H1('11. Contractual & Documentary Artifacts'));
ch.push(P('These must exist before the first EU or institutional customer signs. They are product deliverables, not paperwork to improvise later:'));
ch.push(TBL(['Artifact', 'Purpose', 'Needed by'], [
  ['Terms of Service', 'Commercial relationship', 'First paid customer (P3)'],
  ['Privacy Policy', 'Our own processing (staff accounts, marketing site)', 'P3'],
  ['Data Processing Agreement', 'Controller–processor terms; required by EU customers', 'First EU customer'],
  ['Sub-processor list', 'Hosting, storage, email, payments — with notice-of-change commitment', 'First EU customer'],
  ['Record of processing activities', 'Article 30 obligation', 'First EU customer'],
  ['Security overview (one page)', 'Answers 80% of buyer security questions before they ask', 'P3 — sales accelerator'],
  ['Breach response runbook', 'Who does what, in what order, with what timings', 'Before production data exists'],
  ['Retention & deletion schedule', 'Published operational commitment', 'P3'],
], [2600, 4700, 2446]));

ch.push(H1('12. Infrastructure Security'));
ch.push(B('TLS everywhere with modern configuration; HSTS; the application is reachable only through the proxy layer.'));
ch.push(B('Encryption at rest for database volumes, object storage and backups; keys held by the platform provider or a managed key service, never in the repository.'));
ch.push(B('Backups encrypted and access-restricted; **restore is rehearsed on a schedule** — an untested backup is a belief, not a control.'));
ch.push(B('Least-privilege service accounts: the application database user holds no UPDATE or DELETE grant on `audit_logs`.'));
ch.push(B('Administrative access to servers by key only, no password authentication, with an offboarding checklist.'));

ch.push(H1('13. Secure Development'));
ch.push(B('Automated in CI: dependency vulnerability scanning, static analysis, secret scanning, and the full test suite including isolation and authorization-denial cases.'));
ch.push(B('Patch policy: critical dependency advisories addressed within 7 days, high within 30.'));
ch.push(B('Security-relevant changes (authentication, authorization, tenancy, file access) require an explicit review note in the pull request describing what was verified.'));
ch.push(B('A published security contact and disclosure policy, with a commitment to acknowledge reports within 72 hours, from public launch (P4).'));

ch.push(H1('14. Incident Response'));
ch.push(N('**Detect** — alerting on error-rate spikes, authentication anomalies, and failed job surges; any report from a customer or researcher enters the same process.'));
ch.push(N('**Contain** — revoke credentials, disable affected paths, or suspend the impacted tenant; preserve logs before remediation.'));
ch.push(N('**Assess** — determine what data was involved, whose, and over what window, using the audit log as the primary evidence source.'));
ch.push(N('**Notify** — inform affected tenants (as controllers) without undue delay, with facts, scope and remediation; support their regulator notification with the detail they need.'));
ch.push(N('**Remediate and record** — fix, add the regression test, and publish a post-incident summary to affected customers.'));
ch.push(NOTE('The audit log is the reason this process can work at all. It is append-only, tenant-visible, and covers operator access — which is precisely why it is not a feature to be trimmed under schedule pressure.'));

doc = buildDoc(ch, 'SL-SEC-004 · Multi-Tenancy, Security & Compliance · snova-labs');
save(doc, '/home/claude/tut-docs/out/04_Multi_Tenancy_Security_and_Compliance.docx');
