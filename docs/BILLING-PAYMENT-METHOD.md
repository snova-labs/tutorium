# Payment method management

An academy manages how it pays from its own billing screen, without writing to us. Everything
here needs the `billing.manage` permission (Owner and Accountant by default). Support access never
reaches these routes.

## What the billing screen shows

`GET /api/v1/billing` returns, under `payment_method`:

| Field | Meaning |
|---|---|
| `collection` | `card` (charged automatically) or `invoice` (paid by bank transfer) |
| `summary` | One sentence to show as is, e.g. "Visa •••• 4242, expires 04/2027. Charged automatically each month." |
| `card` | Brand, last four, expiry, `expired`, `expires_soon` (within a month), or `null` |
| `card_available` | Whether this installation takes cards at all. `false` with the invoicing-only provider |
| `needs_attention` | On card collection with no card that can be charged: the next invoice will fail |

Only the brand, last four digits and expiry are ever stored, so a customer can recognise their own
card. Card numbers are entered on the payment provider's hosted page and never reach us.

## Adding or replacing a card

`POST /api/v1/billing/payment-method` `{ "return_url": "https://<app host>/billing" }`

Returns `url`: the provider's checkout to add a first card, or its portal once a card exists. The
card summary updates when the provider's webhook arrives. `return_url` is optional and must be on
the application's own host, so the hosted page cannot be used to send someone elsewhere.

## Card or invoice

`PUT /api/v1/billing/collection` `{ "method": "invoice" | "card" }`

- **Invoice** needs a legal name and billing email (send them in the same request if they are not
  saved yet). From then on invoices are emailed and never charged; dunning skips card attempts.
- **Card** needs a card on file that has not expired. Otherwise nothing changes and the response
  says to add a card first. Adding a card while on invoicing does not switch by itself.

## When the account is read-only

An account made read-only for non-payment can still see its billing, add a card and switch to
invoicing: those routes are exempt from the read-only rule (`tenant:billing`). Changing plan and
cancelling are not.

## From trial to paying, in one step

`POST /api/v1/billing/convert`
`{ "plan_code": "growth", "method": "invoice" | "card", "legal_name": "...", "billing_email": "..." }`

Works during a trial and after one has lapsed into read-only. Everything entered during the trial
stays exactly as it is; nothing is copied or rebuilt.

- **Invoice**, or **card** with a card already on file: converts at once (`converted: true`). The
  account becomes active and the subscription starts on the chosen plan.
- **Card** with no card yet: the plan is recorded (subscription `trialing`) and the response carries
  `checkout_url`, the provider's hosted page. The account converts when the provider reports the
  card (`payment_method.attached` webhook). Until then nothing changes.
- Only plans on offer (`is_public`) can be chosen. An account that is not on a trial is refused and
  pointed to the plan and payment method routes instead.

`GET /api/v1/billing` includes `trial` (end date and whether it has ended) while conversion is
possible, and `null` otherwise.
