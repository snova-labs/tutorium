# Operator sign-in and two-factor

Operator accounts reach every customer's data, so the console at `operator/v1` is closed to
anyone who has not proved a second factor. There is no way to switch this off.

## Signing in

```http
POST /operator/v1/auth/login
{ "email": "...", "password": "...", "code": "123456" }
```

- `code` is the six-digit code from an authenticator app, or one of the recovery codes.
- Success returns a console token (ability `console`, valid for 12 hours). Send it as
  `Authorization: Bearer <token>`.
- Every failure (unknown email, wrong password, wrong code, disabled account) returns the same
  message, so the response never says which part was wrong. Ten attempts a minute per client.
- A code is accepted once. Replaying it inside its thirty seconds is refused.
- When a recovery code was used, the response includes `recovery_codes_remaining`.

## First sign-in: enrolment

An operator with a password but no confirmed second factor gets a token with the ability
`two-factor:enrol` instead. It lasts 15 minutes and opens nothing except:

| Request | What it does |
|---|---|
| `POST /operator/v1/auth/two-factor/enrol` | Returns a new `secret` and an `otpauth_uri` to scan into an authenticator app. |
| `POST /operator/v1/auth/two-factor/confirm` `{ "code": "123456" }` | Checks a code from the app. Returns ten single-use `recovery_codes` (shown once) and a console token. The enrolment token is revoked. |

An abandoned enrolment changes nothing: the operator still cannot sign in. An operator who is
already enrolled cannot start again; another operator resets them.

## Recovery codes

- Ten per operator, each usable once, stored only as hashes.
- `POST /operator/v1/auth/two-factor/recovery-codes` `{ "code": "123456" }` replaces all of them.
  It needs a console token and a current authenticator code; a recovery code is not accepted here,
  so a stolen token plus one recovery code cannot mint more.

## Signing out

`POST /operator/v1/auth/logout` revokes the token in use.

## Storage

- The TOTP secret is encrypted with the application key (`two_factor_secret`).
- Recovery codes are SHA-256 hashes (`two_factor_recovery_codes`).
- `two_factor_last_step` records the last time step accepted, which is what refuses replays.
- TOTP follows RFC 6238 (SHA-1, six digits, 30-second steps, one step of clock drift either side)
  and is checked against the RFC's own test vectors in `tests/Unit/TotpTest.php`.
