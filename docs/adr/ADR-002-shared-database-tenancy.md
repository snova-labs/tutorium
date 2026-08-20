# ADR-002 — Shared database with row-level tenant isolation

- **Status:** Accepted
- **Date:** 2026-08-16
- **Context doc:** SL-ARC-002 §3, SL-SEC-004 §3

## Context

Three tenancy models were considered: shared database with a tenant column, database per
tenant, and schema per tenant. The expected shape is many small tenants (30–500 learners)
operated by one person.

## Decision

Shared database, `tenant_id` on every tenant-owned row, automatic query scoping.

## Consequences

- One migration run regardless of tenant count; cheapest to operate at this scale.
- **A missing scope is a data breach.** This risk is answered structurally rather than by
  discipline: `BelongsToTenant` scopes automatically, `TenantRegistryTest` fails the build
  when a model is not covered, and `TenantIsolationTest` runs the same contract against
  every registered resource.
- Queries fail closed: with no tenant bound, a tenant-owned query returns nothing rather
  than everything.
- Self-hosted mode is the degenerate case — the same code with one tenant bound at boot.
- Per-tenant export requires a filtered dump rather than a database copy. Accepted.

## Rejected

- **Database per tenant** — migrations across N databases and connection overhead are
  unmanageable for one operator at 200+ tenants.
- **Schema per tenant** — MySQL handles this poorly; worst of both operationally.
