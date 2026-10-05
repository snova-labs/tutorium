# Self-serve signup and the trial

How a stranger becomes a customer without anyone at our end being involved (SL-401, SL-402,
SL-403). The form is at `/sign-up` in the staff client (`web/`); the API is under `/api/v1/signup`.

## 1. The form (SL-401)

Asks for: your name, work email, a password (12 characters or more), the academy's name, the kind
of academy (tutoring, language, skills) and a starting point within it (a preset), the country, and
the timezone of the first location. The country preselects from the browser's timezone, and the
timezone list shows only that country's zones.

`GET /api/v1/signup/options` returns everything the form needs: whether signup is open, the kinds
of academy, the presets, every country with its timezones, the trial length and the terms.

`POST /api/v1/signup` accepts the form and returns **202**: nothing is created yet.

### Throttling

Every submission counts, per rolling hour (`config/signup.php`, `limits`):

| Limit | Default | Message names |
|---|---|---|
| Per address | 3 | the address |
| Per network (IP) | 10 | "from here" |
| Per email domain | 5 | the domain, and suggests asking a colleague for an invitation |

Shared mailbox providers (gmail.com, outlook.com and so on, `shared_mail_domains`) are exempt from
the domain limit, since a limit on gmail.com would be a limit on everyone; the address and network
limits still apply. A throttled request returns **429**. Confirming a link does not count again.

There is no list of banned providers: those reject real teachers at small academies more often than
they stop anyone determined.

`SIGNUP_OPEN=false` closes the form without a deploy (503 from the API; the page says signups are
paused).

## 2. Confirming the address (SL-402)

Nothing is provisioned until the owner uses the emailed link. Until then the answers wait in
`pending_signups`, encrypted, with the password already hashed (the plain password is never
stored). There is no account, no owner and no trial clock.

- The link opens `WEB_URL/sign-up/confirm/<token>`. Opening it changes nothing (mail scanners open
  links too); the account is created when the person presses **Confirm and create my account**.
- It works **once**, and expires after **48 hours** (`SIGNUP_CONFIRMATION_HOURS`).
- Signing up again with the same address replaces the earlier answers, and the earlier link stops
  working.
- Only a hash of the token is stored.
- Confirming provisions the account from the preset, marks the owner's address as verified, starts
  the trial, and deletes the stored answers.
- Expired links are purged daily, with the answers they held.

API: `GET /api/v1/signup/confirm/{token}` describes the link without using it;
`POST /api/v1/signup/confirm/{token}` uses it (201).

## 3. The trial (SL-403)

- 14 days (`TRIAL_DAYS`), starting when the address is confirmed.
- Reminders to the owner on **day 7, day 12 and the last day** (`trial_reminder_days`), at most once
  each, from `platform:trials` (daily at 08:00). An owner who has not reached taking a register is
  offered help finishing setup rather than a countdown. If the scheduler missed some days, only the
  most urgent reminder goes out.
- At the end, the account becomes **read-only**: everything entered stays, and can still be read,
  downloaded and exported. Choosing a plan and a way to pay restores full access immediately
  (see `BILLING-PAYMENT-METHOD.md`).

## Configuration

| Variable | Default | |
|---|---|---|
| `WEB_URL` | `APP_URL` | Where links in emails open (the staff client) |
| `SIGNUP_OPEN` | `true` | Close the form without a deploy |
| `SIGNUP_CONFIRMATION_HOURS` | `48` | How long a confirmation link works |
| `SIGNUP_LIMIT_PER_EMAIL` / `_PER_IP` / `_PER_DOMAIN` | 3 / 10 / 5 | Per rolling hour |
| `TRIAL_DAYS` | `14` | Trial length |
