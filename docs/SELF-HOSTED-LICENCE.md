# Self-hosted licensing

A self-hosted install is told what it may do by one signed file. Nothing reaches the internet to
find out: the signature is checked locally against a public key shipped with the release, so an
install behind an air gap, on a school's own server, with no route out at all, behaves exactly like
one sitting next to us (SL-BIL-006 §9, NFR-PORT-2).

Cloud academies are unaffected by everything on this page. There, entitlements come from a
subscription, `platform:licence` says so, and no licence is read.

## Switching an install to self-hosted mode

| Variable | Default | What it does |
|---|---|---|
| `PLATFORM_MODE` | `cloud` | Set to `self_hosted` to resolve entitlements from a licence file |
| `LICENCE_FILE` | `storage/app/licence.json` | Where the licence lives |
| `LICENCE_PUBLIC_KEY` | *(empty)* | The signing key's public half, 64 hex characters, published with the release |
| `LICENCE_GRACE_DAYS` | `30` | How long an expired licence keeps working |
| `LICENCE_WARN_AT_PERCENT` | `90` | How full the learner cap has to be before the product starts saying so |

An empty `LICENCE_PUBLIC_KEY` means the build trusts no licence at all. That is deliberate: a build
that was never given a key should accept nothing rather than anything.

## What a licence says

```json
{
  "id": "LIC-2026-0001",
  "licensee": "Sunrise Academy",
  "issued_on": "2026-01-01",
  "expires_on": "2027-01-01",
  "max_learners": 500,
  "features": { "sso": true, "api_access": true },
  "notes": "Annual, 500 learners"
}
```

`max_learners` absent or null means unlimited, which is a different thing from a cap of zero. The
features are the same keys a cloud plan uses, which is the point: nothing in the product asks which
source answered a question.

That object is the **payload**. The file as installed is an envelope around it:

```json
{
  "format": 1,
  "payload": "<base64 of the payload JSON>",
  "signature": "<base64 of the Ed25519 signature over those exact bytes>"
}
```

The payload travels base64-encoded so that the bytes signed and the bytes verified are the same
bytes, down to the whitespace — there is no canonical-JSON question to get wrong two releases from
now. Signatures are Ed25519, 64 bytes, verified with `ext-sodium`.

## Checking a licence

```bash
php artisan platform:licence                 # the installed licence, verified here and now
php artisan platform:licence --json          # the same, for a monitor
php artisan platform:licence --file=new.json # check a renewal before it goes near production
php artisan platform:verify                  # includes a Licence row on a self-hosted install
```

`platform:verify` fails on a licence it cannot read or one that has lapsed, and warns while a
licence is close to expiry or a cap is close to full. A deploy therefore finds out while somebody
is still watching the output.

## What a cap, an expiry and a lapse actually do

Only one thing is ever refused: **enrolling somebody new**.

| Situation | New enrollments | Everything else |
|---|---|---|
| Cap not full | Allowed | Allowed |
| Cap 90% full (configurable) | Allowed, with a warning on the billing screen and in `platform:verify` | Allowed |
| Cap full | Refused, naming the cap | Allowed |
| Expired, inside the grace period | Allowed, with a warning saying when it stops | Allowed |
| Expired beyond the grace period | Refused, naming the expiry and the grace end | Allowed |
| No licence, or one that fails its signature | Refused | Allowed |

"Everything else" is not a figure of speech: attendance, grades, notes, schedules, reports, exports
and sign-in all keep working in every row of that table. A school cannot stop teaching the learners
it already has because a renewal is late, and taking the register off a teacher mid-lesson is not a
collections strategy (FR-ENT-3).

Two further details, both deliberate:

- The cap counts **learners**, exactly as the invoice counts them: one person in three batches is
  one learner. Somebody already counted can always be enrolled into another batch, however full the
  cap is — they were paid for.
- A learner already enrolled is never removed, reassigned or hidden when the cap is exceeded, which
  can happen after a licence is replaced with a smaller one. The figure simply reads over, and the
  warning says so.

## Issuing a licence (vendor side)

The secret key never goes near an install. Generate the pair once, publish the public half with the
release, and keep the secret half wherever release signing keys live.

```php
[$public, $secret] = \App\Support\Licensing\LicenceFile::keypair();

file_put_contents('licence.json', \App\Support\Licensing\LicenceFile::sign([
    'id' => 'LIC-2026-0001',
    'licensee' => 'Sunrise Academy',
    'issued_on' => '2026-01-01',
    'expires_on' => '2027-01-01',
    'max_learners' => 500,
    'features' => ['sso' => true],
], $secret));
```

Then verify it the way the customer's install will, before sending it:

```bash
LICENCE_PUBLIC_KEY=$public php artisan platform:licence --file=licence.json
```

## Installing a licence or a renewal

1. Copy the file to `LICENCE_FILE` on the server (the default is `storage/app/licence.json`).
2. Run `php artisan platform:licence` to confirm what the install now believes.

No restart, no activation step, and nothing to register. Replacing the file is the whole operation;
the licence is read once per request.
