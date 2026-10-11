"use client";

import { useActionState, useEffect, useRef } from "react";
import { toast } from "sonner";

import { type OrgResult, removeLogo, saveBrand, uploadLogo } from "@/app/(app)/organisation/actions";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { colorOr } from "@/lib/organisation";
import { suggestCode } from "@/lib/setup";
import type { Brand } from "@/lib/types";

const empty: OrgResult = { ok: false };

export function BrandForm({ brand, academyName }: { brand: Brand | null; academyName: string }) {
  const [state, action, pending] = useActionState(saveBrand, empty);
  const error = (field: string) => (state.ok ? undefined : state.errors?.[field]);

  useEffect(() => {
    if (state.ok && state.message) {
      toast.success(state.message);
    }
  }, [state]);

  return (
    <form action={action} className="space-y-4">
      {brand && <input type="hidden" name="id" value={brand.id} />}
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor="brand-name">Name on reports</Label>
          <Input
            id="brand-name"
            name="name"
            required
            maxLength={120}
            defaultValue={brand?.name ?? academyName}
            aria-invalid={error("name") ? true : undefined}
            onChange={(event) => {
              // Only while there is no brand yet: changing an established code breaks nothing in
              // the product, but it is on exports people have already filed.
              if (!brand) {
                const code = event.currentTarget.form?.elements.namedItem("code");

                if (code instanceof HTMLInputElement && code.dataset.touched !== "yes") {
                  code.value = suggestCode(event.currentTarget.value);
                }
              }
            }}
          />
          {error("name") && <p className="text-xs text-bad">{error("name")}</p>}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="brand-code">Short code</Label>
          <Input
            id="brand-code"
            name="code"
            required
            maxLength={32}
            className="font-mono"
            defaultValue={brand?.code ?? ""}
            onChange={(event) => {
              event.currentTarget.dataset.touched = "yes";
            }}
            aria-invalid={error("code") ? true : undefined}
          />
          {error("code") ? (
            <p className="text-xs text-bad">{error("code")}</p>
          ) : (
            <p className="text-xs text-muted-foreground">Appears on report numbers and exports.</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="sender_name">Reports come from</Label>
          <Input id="sender_name" name="sender_name" maxLength={120} defaultValue={brand?.sender.name ?? ""} />
          {error("sender_name") && <p className="text-xs text-bad">{error("sender_name")}</p>}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="sender_email">Reply-to address</Label>
          <Input
            id="sender_email"
            name="sender_email"
            type="email"
            maxLength={190}
            defaultValue={brand?.sender.email ?? ""}
            aria-invalid={error("sender_email") ? true : undefined}
          />
          {error("sender_email") ? (
            <p className="text-xs text-bad">{error("sender_email")}</p>
          ) : (
            <p className="text-xs text-muted-foreground">Where a guardian&apos;s reply goes.</p>
          )}
        </div>
      </div>

      <fieldset className="space-y-2">
        <legend className="text-sm font-medium">Colours</legend>
        <div className="flex flex-wrap gap-6">
          {(["primary", "accent"] as const).map((key) => (
            <div key={key} className="space-y-1.5">
              <Label htmlFor={`colors.${key}`} className="text-xs font-normal text-muted-foreground">
                {key === "primary" ? "Headings and rules on reports" : "Accent"}
              </Label>
              <div className="flex items-center gap-2">
                <Input
                  id={`colors.${key}`}
                  name={`colors.${key}`}
                  type="color"
                  defaultValue={colorOr(brand?.colors, key)}
                  className="h-9 w-16 p-1"
                />
                <span className="font-mono text-xs text-muted-foreground">{colorOr(brand?.colors, key)}</span>
              </div>
              {error(`colors.${key}`) && <p className="text-xs text-bad">{error(`colors.${key}`)}</p>}
            </div>
          ))}
        </div>
      </fieldset>

      {/* Only when it is not already said beside a field, so a refusal is never printed twice. */}
      {!state.ok && state.message && Object.keys(state.errors ?? {}).length === 0 && (
        <p className="text-sm text-bad">{state.message}</p>
      )}

      <Button type="submit" disabled={pending}>
        {pending ? "Saving…" : "Save"}
      </Button>
    </form>
  );
}

export function LogoField({ brand }: { brand: Brand | null }) {
  const [state, action, pending] = useActionState(uploadLogo, empty);
  const [removal, remove, removing] = useActionState(
    async () => (brand ? removeLogo(brand.id) : empty),
    empty,
  );
  const file = useRef<HTMLInputElement>(null);

  useEffect(() => {
    for (const result of [state, removal]) {
      if (result.ok && result.message) {
        toast.success(result.message);
      }
    }

    if (state.ok) {
      file.current?.form?.reset();
    }
  }, [state, removal]);

  if (!brand) {
    return <p className="text-sm text-muted-foreground">Save your details first, then add a logo.</p>;
  }

  const message = (state.ok ? undefined : (state.errors?.logo ?? state.message)) ?? (removal.ok ? undefined : removal.message);

  return (
    <div className="space-y-3">
      {brand.logo_url ? (
        // Served through this app, as the signed-in person: the file itself is not public.
        // eslint-disable-next-line @next/next/no-img-element
        <img
          src={`/organisation/logo/${brand.id}`}
          alt={`${brand.name} logo`}
          className="max-h-16 max-w-48 rounded border bg-white object-contain p-1"
        />
      ) : (
        <p className="text-sm text-muted-foreground">No logo yet. Reports show your name instead.</p>
      )}

      <form action={action} className="flex flex-wrap items-end gap-2">
        <input type="hidden" name="id" value={brand.id} />
        <div className="space-y-1.5">
          <Label htmlFor="logo" className="text-xs font-normal text-muted-foreground">
            PNG, JPG or WebP, up to 512KB
          </Label>
          <Input ref={file} id="logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp" required className="max-w-xs" />
        </div>
        <Button type="submit" variant="outline" disabled={pending}>
          {pending ? "Uploading…" : brand.logo_url ? "Replace" : "Upload"}
        </Button>
      </form>

      {brand.logo_url && (
        <form action={remove}>
          <Button type="submit" variant="ghost" size="sm" disabled={removing}>
            {removing ? "Removing…" : "Remove logo"}
          </Button>
        </form>
      )}

      {message && <p className="text-sm text-bad">{message}</p>}
    </div>
  );
}
