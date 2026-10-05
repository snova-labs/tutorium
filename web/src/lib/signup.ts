/** What GET /signup/options returns: everything the public form needs to draw itself. */
export interface SignupOptions {
  open: boolean;
  verticals: { code: string; name: string }[];
  presets: { code: string; vertical: string; name: string; summary: string }[];
  countries: { code: string; name: string; timezones: string[] }[];
  trial_days: number;
  terms: string[];
}

export type SignupField = "name" | "owner_name" | "owner_email" | "password" | "country" | "timezone" | "preset_code";

export interface SignupState {
  status: "editing" | "sent";
  /** The address the link went to, once sent. */
  email?: string;
  error?: string;
  fields?: Partial<Record<SignupField, string>>;
  /** What was typed, so a rejected form comes back filled in (the password excepted). */
  values?: Partial<Record<Exclude<SignupField, "password">, string>>;
}

/**
 * Old names browsers still report (ICU keeps them), mapped to the names the server lists. Chrome
 * in Nepal says "Asia/Katmandu"; without this, Nepal and India would never be preselected.
 */
const ZONE_ALIASES: Record<string, string> = {
  "Asia/Katmandu": "Asia/Kathmandu",
  "Asia/Calcutta": "Asia/Kolkata",
  "Asia/Dacca": "Asia/Dhaka",
  "Asia/Thimbu": "Asia/Thimphu",
  "Asia/Rangoon": "Asia/Yangon",
  "Asia/Saigon": "Asia/Ho_Chi_Minh",
  "Asia/Ulan_Bator": "Asia/Ulaanbaatar",
  "Asia/Ujung_Pandang": "Asia/Makassar",
  "Asia/Chongqing": "Asia/Shanghai",
  "Asia/Harbin": "Asia/Shanghai",
  "Asia/Istanbul": "Europe/Istanbul",
  "Europe/Kiev": "Europe/Kyiv",
  "Europe/Uzhgorod": "Europe/Kyiv",
  "Europe/Zaporozhye": "Europe/Kyiv",
  "Africa/Asmera": "Africa/Asmara",
  "America/Godthab": "America/Nuuk",
  "America/Buenos_Aires": "America/Argentina/Buenos_Aires",
  "America/Indianapolis": "America/Indiana/Indianapolis",
  "Atlantic/Faeroe": "Atlantic/Faroe",
  "Pacific/Truk": "Pacific/Chuuk",
  "Pacific/Ponape": "Pacific/Pohnpei",
  "Pacific/Enderbury": "Pacific/Kanton",
};

export function canonicalZone(zone: string | undefined): string | undefined {
  return zone === undefined ? undefined : (ZONE_ALIASES[zone] ?? zone);
}

/** The starting points for one kind of academy. */
export function presetsFor(vertical: string, presets: SignupOptions["presets"]): SignupOptions["presets"] {
  return presets.filter((preset) => preset.vertical === vertical);
}

export function timezonesFor(country: string, countries: SignupOptions["countries"]): string[] {
  return countries.find((c) => c.code === country)?.timezones ?? [];
}

/**
 * The timezone to preselect for a country: the browser's own when it is one of that country's,
 * else the country's first. A wrong timezone mis-schedules every session, so the choice stays
 * visible and editable.
 */
export function defaultTimezone(country: string, countries: SignupOptions["countries"], browserZone?: string): string {
  const zones = timezonesFor(country, countries);
  const zone = canonicalZone(browserZone);

  if (zone && zones.includes(zone)) {
    return zone;
  }

  return zones[0] ?? "";
}

/** The country whose timezones include the browser's, to preselect. */
export function countryForZone(browserZone: string | undefined, countries: SignupOptions["countries"]): string {
  const zone = canonicalZone(browserZone);

  if (!zone) {
    return "";
  }

  return countries.find((c) => c.timezones.includes(zone))?.code ?? "";
}

/** "Asia/Kathmandu" → "Kathmandu", "America/Indiana/Knox" → "Indiana / Knox". */
export function zoneLabel(zone: string): string {
  return zone.split("/").slice(1).join(" / ").replaceAll("_", " ") || zone;
}
