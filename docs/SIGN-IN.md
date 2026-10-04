# Sign-in and two-factor

Two-factor is by emailed code: a six-digit code sent at sign-in that proves the person signing in
can also read the account's inbox. Operators can additionally set up an authenticator app, which
then replaces email for them. The same email codes protect academy staff in chosen roles.

## Emailed sign-in codes

- Six digits, valid for 10 minutes, accepted once.
- Five wrong guesses kill a code; signing in again sends a new one.
- Asking again within a minute does not send another, and at most five codes go out per account
  per hour, so the endpoint cannot flood an inbox or be used to walk the million possibilities.
- Only an HMAC of the code (keyed with the application key) is stored, in `sign_in_codes`.
- The email tells the reader what to do if it was not them signing in.

## Operators

Operator accounts reach every customer's data, so the console at `operator/v1` is closed to any
token that was not issued after a second factor. There is no way to switch this off.

### Signing in

```http
POST /operator/v1/auth/login
{ "email": "...", "password": "...", "code": "123456" }
```

1. Send email and password without `code`. If they are right, a code is emailed and the response
   is a 422 with an error on `code`.
2. Send the same request again with the emailed `code`.

- An operator who has set up an authenticator app gets no email: `code` is the code from the app
  or one of their recovery codes, and the emailed route is closed to them.
- Success returns a console token (ability `console`, valid for 12 hours). Send it as
  `Authorization: Bearer <token>`.
- Every failure (unknown email, wrong password, wrong code, disabled account) returns the same
  message, so the response never says which part was wrong. Ten attempts a minute per client.
- When a recovery code was used, the response includes `recovery_codes_remaining`.

### Optional: authenticator app

Done while signed in, with a console token:

| Request | What it does |
|---|---|
| `POST /operator/v1/auth/two-factor/enrol` | Returns a new `secret` and an `otpauth_uri` to scan into an authenticator app. |
| `POST /operator/v1/auth/two-factor/confirm` `{ "code": "123456" }` | Checks a code from the app and returns ten single-use `recovery_codes` (shown once). From then on, sign-in needs the app. |

An abandoned enrolment changes nothing: the operator stays on emailed codes. An operator who is
already enrolled cannot start again; another operator resets them.

### Recovery codes

- Ten per operator, each usable once, stored only as hashes.
- `POST /operator/v1/auth/two-factor/recovery-codes` `{ "code": "123456" }` replaces all of them.
  It needs a console token and a current authenticator code; a recovery code is not accepted here,
  so a stolen token plus one recovery code cannot mint more.

### Signing out

`POST /operator/v1/auth/logout` revokes the token in use.

### Storage

- The TOTP secret is encrypted with the application key (`two_factor_secret`).
- Recovery codes are SHA-256 hashes (`two_factor_recovery_codes`).
- `two_factor_last_step` records the last time step accepted, which is what refuses replays.
- TOTP follows RFC 6238 (SHA-1, six digits, 30-second steps, one step of clock drift either side)
  and is checked against the RFC's own test vectors in `tests/Unit/TotpTest.php`.

## Academy staff

Each academy chooses which roles must enter an emailed code at every sign-in, with the setting
`security.sign_in_code_roles`. The default is `["Owner", "Management", "Accountant"]`: the roles
that can see money or change who has access. Setting it to an empty list turns codes off.

- **API** (`POST /api/v1/auth/login`): same two steps as operators. Send email and password, get a
  422 on `code`, then send them again with `code`. A wrong code counts towards the five-failure,
  fifteen-minute lock on that address.
- **Browser** (`/sign-in`): after the password, the user is sent to `/sign-in/code` and is not
  signed in until the code is accepted. The waiting state lasts ten minutes and lives only in
  that browser's session; "Send a new code" asks for another.
