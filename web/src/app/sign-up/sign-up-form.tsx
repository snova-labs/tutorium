"use client";

import { startTransition, useActionState, useState, useSyncExternalStore } from "react";

import { signUp } from "@/app/sign-up/actions";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect } from "@/components/ui/native-select";
import {
  countryForZone,
  defaultTimezone,
  presetsFor,
  timezonesFor,
  zoneLabel,
  type SignupField,
  type SignupOptions,
  type SignupState,
} from "@/lib/signup";

const initial: SignupState = { status: "editing" };

const browserZone = () => Intl.DateTimeFormat().resolvedOptions().timeZone;

export function SignUpForm({ options }: { options: SignupOptions }) {
  const [state, action, pending] = useActionState(signUp, initial);
  // The browser's timezone only exists on the client; on the server there is none to guess from.
  const zone = useSyncExternalStore(() => () => {}, browserZone, () => undefined);

  if (state.status === "sent") {
    return (
      <div className="space-y-3 text-sm">
        <p className="font-medium">Check your email.</p>
        <p className="text-muted-foreground">
          We sent a link to <span className="font-medium text-foreground">{state.email}</span>. Use it to create your
          account. Nothing is set up until you do, and the link works once.
        </p>
        <p className="text-muted-foreground">Not there after a few minutes? Check spam, or fill in the form again for a new link.</p>
      </div>
    );
  }

  return <Fields key={zone ?? "server"} options={options} state={state} action={action} pending={pending} zone={zone} />;
}

function Fields({
  options,
  state,
  action,
  pending,
  zone,
}: {
  options: SignupOptions;
  state: SignupState;
  action: (formData: FormData) => void;
  pending: boolean;
  zone: string | undefined;
}) {
  const values = state.values ?? {};
  const [vertical, setVertical] = useState(
    () => options.presets.find((p) => p.code === values.preset_code)?.vertical ?? options.verticals[0]?.code ?? "",
  );
  const [presetCode, setPresetCode] = useState(
    () => values.preset_code ?? presetsFor(vertical, options.presets)[0]?.code ?? "",
  );
  const [country, setCountry] = useState(() => values.country ?? countryForZone(zone, options.countries));
  const [timezone, setTimezone] = useState(() => values.timezone ?? defaultTimezone(country, options.countries, zone));

  const presets = presetsFor(vertical, options.presets);
  const zones = timezonesFor(country, options.countries);
  const summary = presets.find((p) => p.code === presetCode)?.summary;
  const error = (field: SignupField) => state.fields?.[field];

  return (
    <form
      className="space-y-4"
      noValidate
      // Submitted by hand rather than through the form's action, because React resets a form after
      // its action runs: the selects would jump back to their first option (Afghanistan) while the
      // component still believed another country was chosen.
      onSubmit={(event) => {
        event.preventDefault();
        const formData = new FormData(event.currentTarget);
        startTransition(() => action(formData));
      }}
    >
      <Field label="Your name" name="owner_name" error={error("owner_name")}>
        <Input id="owner_name" name="owner_name" autoComplete="name" defaultValue={values.owner_name} required />
      </Field>

      <Field label="Work email" name="owner_email" error={error("owner_email")}>
        <Input id="owner_email" name="owner_email" type="email" autoComplete="email" defaultValue={values.owner_email} required />
      </Field>

      <Field label="Password" name="password" error={error("password")} hint="At least 12 characters. A short phrase works well.">
        <Input id="password" name="password" type="password" autoComplete="new-password" minLength={12} required />
      </Field>

      <Field label="Academy name" name="name" error={error("name")}>
        <Input id="name" name="name" autoComplete="organization" defaultValue={values.name} required />
      </Field>

      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Kind of academy" name="vertical">
          <NativeSelect
            id="vertical"
            value={vertical}
            onChange={(e) => {
              setVertical(e.target.value);
              setPresetCode(presetsFor(e.target.value, options.presets)[0]?.code ?? "");
            }}
          >
            {options.verticals.map((v) => (
              <option key={v.code} value={v.code}>
                {v.name}
              </option>
            ))}
          </NativeSelect>
        </Field>

        <Field label="Starting point" name="preset_code" error={error("preset_code")}>
          <NativeSelect
            id="preset_code"
            name="preset_code"
            value={presetCode}
            onChange={(e) => setPresetCode(e.target.value)}
            required
          >
            {presets.map((p) => (
              <option key={p.code} value={p.code}>
                {p.name}
              </option>
            ))}
          </NativeSelect>
        </Field>
      </div>

      {summary && <p className="-mt-2 text-xs text-muted-foreground">{summary}</p>}

      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Country" name="country" error={error("country")}>
          <NativeSelect
            id="country"
            name="country"
            value={country}
            onChange={(e) => {
              setCountry(e.target.value);
              setTimezone(defaultTimezone(e.target.value, options.countries, zone));
            }}
            required
          >
            <option value="" disabled>
              Choose…
            </option>
            {options.countries.map((c) => (
              <option key={c.code} value={c.code}>
                {c.name}
              </option>
            ))}
          </NativeSelect>
        </Field>

        <Field label="Timezone" name="timezone" error={error("timezone")}>
          <NativeSelect
            id="timezone"
            name="timezone"
            value={timezone}
            onChange={(e) => setTimezone(e.target.value)}
            disabled={zones.length === 0}
            required
          >
            {zones.length === 0 && <option value="">Choose a country first</option>}
            {zones.map((z) => (
              <option key={z} value={z}>
                {zoneLabel(z)}
              </option>
            ))}
          </NativeSelect>
        </Field>
      </div>


      {state.error && (
        <Alert variant="destructive">
          <AlertDescription>{state.error}</AlertDescription>
        </Alert>
      )}

      <Button type="submit" className="w-full" disabled={pending}>
        {pending ? "Sending…" : "Create my account"}
      </Button>
      <p className="text-center text-xs text-muted-foreground">We will email a link to confirm your address first.</p>
    </form>
  );
}

function Field({
  label,
  name,
  error,
  hint,
  children,
}: {
  label: string;
  name: string;
  error?: string;
  hint?: string;
  children: React.ReactNode;
}) {
  return (
    <div className="space-y-1.5" data-invalid={error ? true : undefined}>
      <Label htmlFor={name}>{label}</Label>
      {children}
      {error ? (
        <p className="text-xs text-bad">{error}</p>
      ) : (
        hint && <p className="text-xs text-muted-foreground">{hint}</p>
      )}
    </div>
  );
}
