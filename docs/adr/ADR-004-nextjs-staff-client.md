# ADR-004 — A Next.js staff client on the API

- **Status:** Accepted
- **Date:** 2026-10-04
- **Supersedes:** ADR-001 for the staff interface (the Filament panel stays for administration)
- **Issue:** SL-416 (#132)

## Context

ADR-001 built the staff interface as an admin panel, keeping all business logic in services
behind a versioned API so the interface could be replaced. SL-416 asks for the interface to be
built against that API. The product owner chose Next.js with TypeScript and shadcn/ui.

## Decision

Build the staff client as a Next.js application in `web/`, using only `/api/v1`.

- **Backend-for-frontend.** The browser talks only to the Next.js server. The API token lives in
  an httpOnly cookie, and server components and server actions call the API with it. There is no
  token in browser storage and no CORS surface on the API.
- **The API stays the single source of rules.** The client hides what a person cannot use, but
  every permission, tenant and validation check remains in Laravel.
- **The conventions are components, with tests.** The provenance chip and the timezone display
  are implemented once and tested, including a test zone behind UTC that catches date drift.
- **Filament remains** for administrative CRUD until each screen has a counterpart here.

## Consequences

- Two applications to deploy. The Next.js server needs `API_BASE_URL`, and Laravel must trust it
  as a proxy so sign-in rate limits stay per person.
- Building against the real API exposed three bugs the API tests could not see, because they fake
  authentication with `Sanctum::actingAs`. All three are fixed, and a test now signs in for real:
  - Tokens were refused, because the token's own user was hidden by the tenant scope.
  - Browser sessions did not survive to the next request, for the same reason.
  - Every ordinary token was treated as support access, because the wildcard ability answers yes
    to "impersonate".
- Screens arrive in slices. This first slice covers sign-in, home, learners (list, add, import),
  batches with sessions, and billing. The attendance register and grade book follow.
