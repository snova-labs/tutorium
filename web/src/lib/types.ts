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
