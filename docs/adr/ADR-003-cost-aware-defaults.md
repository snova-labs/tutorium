# ADR-003 — Cost-aware defaults with documented upgrade triggers

- **Status:** Accepted
- **Date:** 2026-08-16
- **Context doc:** SL-ARC-002 §11, SL-SRS-001 NFR-COST-1

## Context

The product must run on one small server while it has no revenue, without compromising the
architecture that lets it scale later. Free cloud tiers are time-limited trials rather than
hosting, and a 1 GB instance cannot hold the full stack with a headless browser.

## Decision

Ship lighter defaults, each behind an interface with a documented upgrade trigger:

| Component | Default | Upgrade trigger | Path |
|---|---|---|---|
| PDF rendering | Pure-PHP renderer | Report layout needs modern CSS | Swap `PdfRenderer` driver |
| Queue | Database | Sustained backlog, or ~50 tenants | Redis, same job classes |
| Cache / sessions | Database | Response times degrade | Redis |
| Object storage | Local disk | Multiple app hosts, or lifecycle rules | S3-compatible, same abstraction |
| Database | Same host | CPU contention or backup windows | Separate host |

## Consequences

- The whole product runs on a 2 vCPU / 4 GB machine, including report generation.
- No proprietary managed service is required — which also keeps the self-hosted product
  possible and hosting negotiable.
- Each upgrade is a configuration change, never a code change. Verified by the drivers
  being exercised in tests.

## Non-goal

Premature scaling. Every step above is triggered by a measurement, not by anticipation.
