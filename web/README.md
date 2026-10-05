# Staff client (Next.js)

The staff interface, built on the versioned Laravel API (`/api/v1`). Next.js (App Router),
TypeScript, Tailwind CSS and shadcn/ui. See [ADR-004](../docs/adr/ADR-004-nextjs-staff-client.md)
for why it exists alongside the Filament panel.

## Running it

```bash
cp .env.example .env.local   # point API_BASE_URL at the Laravel app
npm install
npm run dev                  # http://localhost:3000
```

Laravel must be running too (`php artisan serve` serves the API on port 8000).

## How it talks to the API

The browser never calls the API. Every page and form runs on this server, which attaches the
signed-in person's token (a backend-for-frontend):

- Sign-in posts to `/api/v1/auth/login` from a server action. The token goes into an **httpOnly**
  cookie, so script in the page cannot read it. Roles that need an emailed code get a second step;
  the password is kept in memory between the steps, never written into the page.
- `src/lib/api.ts` makes every call. A 401 sends the person to `/sign-out`, which revokes the token
  and clears the cookie.
- `src/proxy.ts` sends anyone without a session cookie to `/sign-in` before a page renders. It
  checks only that a cookie is there; the API checks the token on every call.
- Downloads (the import template, blocked rows) go through route handlers that pass the CSV on.

Laravel rate-limits sign-in per address and IP, and this server forwards the browser's address as
`X-Forwarded-For`. In production, configure Laravel's trusted proxies to trust this server, or
every sign-in will appear to come from one address.

## Screens

- **Home**: trial status and the setup checklist.
- **Learners**: list and search, add (with the duplicate warning), spreadsheet import.
- **Batches**: each batch's clock with its provenance; a batch's sessions in batch time.
- **Register** (`/sessions/{id}/register`): one large button per status (44px or more, for a phone in
  a classroom), minutes late for a late mark, "mark the rest present", one save for the class.
  The batch's attendance rules sit above it, each with its provenance. Leaving with unsaved marks
  asks first.
- **Grade book** (`/batches/{id}/gradebook`): one period at a time, in the batch's clock. Each cell
  takes a result in its scheme's own terms (a score, a letter, a level, pass or fail) and a
  submission status; typing a result marks it submitted. Changed cells save together, and a value
  the scheme rejects comes back with the scheme's own message. Rubric totals are shown but
  scored per criterion elsewhere.
- **Billing**: payment method, card or invoice, converting a trial.

## Conventions that must not drift

- **Provenance chip** (`components/app/provenance-chip.tsx`): grey for a value inherited from a
  level above, amber for one set at this level. Amber is used for nothing else. The origin is also
  given in words (tooltip and screen-reader text), never by colour alone.
- **Times** (`lib/time.ts`, `components/app/session-time.tsx`): a session is shown at the local
  date and time that was agreed, in the batch's zone, with the zone named. It is never recomputed
  from UTC. When the viewer's own zone reads differently, their time is added underneath and
  labelled as theirs. The tests run in a zone behind UTC so that a date drifting through UTC fails.

## Checks

```bash
npm run lint
npm run typecheck
npm test
npm run build
```

## Adding shadcn/ui components

`components.json` is set up for the shadcn CLI (`npx shadcn@latest add dialog`). The components
here were written from shadcn's source, because the build environment could not reach
ui.shadcn.com; they follow the new-york style and can be replaced by the CLI's output.
