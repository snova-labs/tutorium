# Documentation

The specification set is the contract for what gets built. Requirement IDs referenced in
pull requests point here.

| ID | Document | Covers |
|---|---|---|
| SL-PRD-000 | Product Vision & Business Requirements | Market, ICP, pricing model, presets, phases, open decisions |
| SL-SRS-001 | Software Requirements Specification | Tenant application (Part A) and platform control plane (Part B), NFRs, acceptance criteria |
| SL-ARC-002 | Design Principles & System Architecture | Principles, tenancy model, stack, layering, extension points, deployment modes |
| SL-DAT-003 | Data Model & Database Design | Schema, conventions, sequences, grading model, indexing |
| SL-SEC-004 | Multi-Tenancy, Security & Compliance | Threat model, isolation, RBAC, minors' data, jurisdictions, incident response |
| SL-LOC-005 | Localization & Country Readiness | Timezones, calendars, periods, grading conventions, names, i18n |
| SL-BIL-006 | Billing, Plans, Entitlements & Metering | Active-learner definition, metering, providers, lifecycle, licensing |
| SL-OPS-007 | Integrations & DevOps | Environments, drivers, Microsoft 365, deliverability, CI/CD, scaling |
| SL-PLN-008 | Roadmap & Delivery Plan | Phases, estimates, milestones, scope control, risk, budget |

Document IDs use an `SL-` prefix rather than a product prefix, so a product rename
invalidates no reference.

## Structure

- `specs/` — the documents as distributed to stakeholders (.docx)
- `generators/` — the scripts that produce them; edit these, then regenerate, so the
  documents stay versioned rather than becoming untracked binaries that drift
- `adr/` — architecture decision records, dated and numbered
- `SIGN-IN.md` — emailed sign-in codes for operators and academy staff, the optional authenticator app and recovery codes
- `BILLING-PAYMENT-METHOD.md` — how an academy adds a card, sees what it pays with, switches to invoicing, and converts a trial in one step
- `LEARNER-IMPORT.md` — importing learners and guardians from a spreadsheet: template, preview, commit

## Regenerating

```bash
cd docs/generators
npm install docx
node d00_vision.js && node d01_srs.js && node d02_arch.js
node d03_04.js && node d05_06.js && node d07_08.js
```

## Decision records

| ADR | Decision |
|---|---|
| 001 | Admin panel first, custom frontend later (staff interface superseded by 004) |
| 002 | Shared database with row-level tenant isolation |
| 003 | Cost-aware defaults with documented upgrade triggers |
| 004 | A Next.js staff client on the API (`web/`) |

Add one whenever a choice would be expensive to reverse or puzzling to a newcomer.
