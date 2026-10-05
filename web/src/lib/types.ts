/** Shapes of the Laravel API's resources, as the client uses them. */

export interface Me {
  id: number;
  name: string;
  email: string;
  timezone: string | null;
  roles: string[];
  permissions: string[];
  tenant: { id: number; name: string; status: string };
}

export interface Learner {
  id: number;
  number: string;
  name: { legal: string; preferred: string | null; display: string };
  date_of_birth: string | null;
  country: string | null;
  contact: { email: string | null; phone: string | null };
  status: { id: number; name?: string; reason: string | null; changed_on: string | null };
  guardians?: { id: number; name: string; email: string | null; phone: string | null }[];
}

export interface Branch {
  id: number;
  name: string;
  locale_rules: { timezone: string };
}

export interface Batch {
  id: number;
  name: string;
  code: string | null;
  timezone: string;
  timezone_source: "inherited from branch" | "set on this batch";
  runs: { starts_on: string; ends_on: string | null };
  status: string;
  delivery_mode: string;
  course?: { id: number; name: string };
  branch?: Branch;
}

export interface ClassSession {
  id: number;
  batch_id: number;
  session_type: { id: number; name?: string };
  /** What was agreed, in the batch's clock. Authoritative. */
  local: { date: string; time: string; timezone?: string };
  /** What everything computes on. */
  utc: { starts_at: string; ends_at: string };
  /** A courtesy for someone in another country; null when the viewer has no zone set. */
  viewer: { starts_at: string; timezone: string } | null;
  status: string;
  cancel_reason: string | null;
  meeting_url: string | null;
}

export interface ImportPreview {
  id: number;
  status: "previewed" | "committed" | "discarded" | "failed";
  file: string;
  totals: {
    rows: number;
    ready: number;
    blocked: number;
    possible_duplicates: number;
    created?: number;
    skipped_duplicates?: number;
  } | null;
  blocked: { row: number; reasons: string[]; values: Record<string, string | null> }[];
  possible_duplicates: { row: number; name: string; reason: string; matches: string[] }[];
  ignored_columns: string[];
  sample: Record<string, string | null>[];
  rejects_url: string | null;
  message?: string;
}

export interface Billing {
  plan: { current: string | null; status: string | null; changing_to: string | null; changing_on: string | null };
  payment_method: {
    collection: "card" | "invoice";
    summary: string;
    card: {
      brand: string | null;
      last_four: string;
      exp_month: number;
      exp_year: number;
      expired: boolean;
      expires_soon: boolean;
    } | null;
    card_available: boolean;
    needs_attention: boolean;
  };
  billing_details: { legal_name: string | null; billing_email: string | null; tax_id: string | null; country: string | null };
  trial: { ends_on: string | null; ended: boolean } | null;
  collection: Record<string, unknown> | null;
  available_plans: { code: string; name: string; unit_price: string; minimum: string; current: boolean }[];
}

export interface AttendanceStatus {
  id: number;
  name: string;
  code: string;
  counts_as_attended: boolean;
  counts_in_rate: boolean;
  is_late: boolean;
  color: string | null;
}

export interface RosterRow {
  enrollment_id: number;
  learner_id: number;
  number: string;
  name: string;
  status_id: number | null;
  minutes_late: number | null;
  note: string | null;
  marked: boolean;
}

/** Where each attendance rule came from: the batch, its course, or the academy. */
export type PolicyOrigin = "batch" | "course" | "tenant" | "system";

export interface Register {
  session: {
    id: number;
    batch: { id: number; name: string };
    type: string;
    local: { date: string; time: string; timezone: string };
    status: string;
  };
  policy: {
    is_compulsory: boolean;
    allow_late_join: boolean;
    late_grace_min: number;
    low_threshold_pct: number;
    origins: Record<string, PolicyOrigin>;
  };
  counts_in_rate: boolean;
  statuses: AttendanceStatus[];
  roster: RosterRow[];
}

export type SchemeKind = "points" | "percentage" | "pass_fail" | "letter" | "level" | "rubric";

export interface GradeCell {
  grade_id: number;
  submission_status_id: number;
  raw_score: string | number | null;
  letter: string | null;
  level_code: string | null;
  passed: boolean | null;
  normalized_pct: string | number | null;
  feedback: string | null;
}

export interface GradeBook {
  assessments: {
    id: number;
    title: string;
    type: string;
    scheme: {
      kind: SchemeKind;
      label: string;
      max_points: string | number | null;
      config: { bands?: { label: string }[]; ladder?: string[] } | null;
    };
    due_local_date: string | null;
    criteria: { id: number; name: string; max_points: string | number }[];
  }[];
  rows: { enrollment_id: number; number: string; name: string; cells: Record<string, GradeCell | null> }[];
  period: { label: string; starts_local_date: string; ends_local_date: string; timezone: string };
  submission_statuses: {
    id: number;
    name: string;
    code: string;
    counts_as_submitted: boolean;
    excluded_from_average: boolean;
    color: string | null;
  }[];
}

export interface Period {
  type: string;
  label: string;
  starts_local_date: string;
  ends_local_date: string;
  timezone: string;
}

export interface ReportReadiness {
  period: Period;
  learners: {
    enrollment_id: number;
    learner: string;
    recipients: number;
    readiness: { is_ready: boolean; gaps: string[] };
    /** No guardian receives reports and the learner has no email: nobody to send it to. */
    blocked: boolean;
  }[];
  ready: number;
  with_gaps: number;
  without_recipients: number;
}

export type ReportRunStatus = "queued" | "running" | "completed" | "completed_with_failures" | "failed";

export interface ReportRun {
  id: number;
  status: ReportRunStatus;
  total: number;
  succeeded: number;
  failed: number;
  progress: number;
  /** Failures, and notes about reports that were generated ("Generated but not sent: …"). */
  failures: { learner: string; reason: string }[];
  finished_at_utc: string | null;
}

export type DeliveryStatus = "queued" | "sent" | "failed" | "bounced";

export interface ReportDelivery {
  id: number;
  to: string;
  name: string | null;
  status: DeliveryStatus;
  error: string | null;
  sent_at_utc: string | null;
}

export interface ArchivedReport {
  id: number;
  number: string;
  learner: string;
  period: string;
  generated_at_utc: string;
  deliveries: ReportDelivery[];
}

export interface NoteCategory {
  id: number;
  name: string;
  report_visible_default: boolean;
}

export interface Enrollment {
  id: number;
  number: string;
  learner_id: number;
  status: { id: number; name?: string };
  ended_on: string | null;
  learner?: Learner;
}
