# ADR-001 — Admin panel first, custom frontend later

- **Status:** Accepted
- **Date:** 2026-08-16
- **Context doc:** SL-ARC-002 §4.2

## Context

The staff interface can be built two ways: a headless API with a custom single-page
application, or a generated admin panel with a versioned API alongside. The product has
no customers yet and one developer.

## Decision

Build the staff interface as an admin panel, with purpose-built pages only for the two
genuinely custom screens (attendance grid, grade grid). Expose `/api/v1` from the start.

## Consequences

- CRUD-heavy modules (settings, courses, people, roles) cost hours rather than weeks.
- The interface looks like an admin panel. Acceptable for internal staff tools; a
  liability only if it starts costing deals.
- **All business logic lives in services**, so the presentation layer is replaceable.
  Rebuilding the UI later touches no domain code.
- The learner and guardian portals (P5) consume the same API, so they are client work.

## Revisit when

Either the admin-panel aesthetic demonstrably costs a deal, or portal work makes a shared
component library worth building.
