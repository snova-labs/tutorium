# platform

Cohort management platform for tutoring centres, language schools and training institutes.
Multi-tenant SaaS, with a self-hosted deployment mode planned.

> **The product name is not in this repository by design.** The public name is provisional
> and lives in `config/platform.php` (`PLATFORM_NAME` in `.env`) plus the language files.
> Namespaces, table names and the repository name are neutral, so a rename is a config change.

---

## What this repository is

This is the **engineering skeleton**: everything that is ours and load-bearing, ready to be
layered onto a fresh framework install. Specifically it contains:

| Area | What is here |
|---|---|
| Tenancy | Automatic query scoping, tenant context binding, request + queue middleware, mismatch guards |
| Isolation proof | A test harness that fails the build when a tenant-owned model is not covered |
| Sequences | Configurable business identifier generation (prefix / padding / scope) |
| Settings | Five-level resolver (tenant → brand → branch → course → batch) with request caching |
| Environments | Docker Compose stack that runs the whole product offline |
| CI | Format, static analysis, tests, migration up/down, dependency and secret scanning |
| Docs | The specification set and architecture decision records |

Framework scaffolding (routes, views, auth, panel resources) is **not** vendored here. See
"Getting started" below.

## Getting started

```bash
# 1. create the framework skeleton in a temporary directory
composer create-project laravel/laravel _fresh

# 2. copy the framework scaffolding into this repo (do not overwrite app/Support, config/tenancy.php,
#    config/platform.php, database/migrations, tests/, docker/, .github/)
rsync -a --ignore-existing _fresh/ . && rm -rf _fresh

# 3. install dependencies listed in composer.json
composer install

# 4. bring up the stack
cp .env.example .env
make up
make install        # key:generate + migrate + seed
```

Then:

| Service | URL |
|---|---|
| Application | http://localhost:8080 |
| Mail catcher (all outbound mail) | http://localhost:8025 |
| Object storage console | http://localhost:9001 |

Nothing above requires a cloud account or credentials. If any part of the product can only be
exercised against a paid service, that is a defect — see `docs/specs` (SL-OPS-007 §1).

## Layout

```
app/
  Models/                 Eloquent models (tenant-owned models use BelongsToTenant)
  Support/Tenancy/        Tenant context, global scope, middleware, job awareness
  Support/Sequences/      IdSequenceService
  Support/Settings/       SettingsResolver
  Providers/              TenancyServiceProvider
config/
  tenancy.php             Resource registry — every tenant-owned model must be listed
  platform.php            Product naming and mode (cloud | self_hosted)
database/migrations/      Schema, in dependency order
tests/Feature/Tenancy/    Isolation harness (blocking check in CI)
docs/specs/               SL-PRD-000 … SL-PLN-008
docs/adr/                 Architecture decision records
docs/generators/          Scripts that produce the specification documents
```

## Non-negotiable rules

1. **Every tenant-owned model uses `BelongsToTenant` and is registered in `config/tenancy.php`.**
   `TenantRegistryTest` fails the build otherwise. This is the mechanism that makes a cross-tenant
   leak structurally unlikely rather than merely discouraged.
2. **No query filters tenant data by hand.** Scoping is automatic. If you find yourself writing
   `where('tenant_id', ...)`, something is wrong.
3. **Jobs carry a tenant id and re-bind context.** A job that cannot resolve a tenant fails loudly.
4. **Business identifiers come from `IdSequenceService`.** No format is hard-coded anywhere.
5. **No product name, customer name, or real learner data in code, seeds or fixtures.**
   Fixtures use neutral names ("Sample Learner").
6. **UTC in storage, local at the edges.** Only `PeriodService` computes period boundaries.

## Commands

```bash
make up / down / restart     stack lifecycle
make install                 key, migrate, seed
make migrate / fresh         schema
make test                    full suite
make stan                    static analysis
make fmt / fmt-check         formatting
make shell / logs            debugging
```

## Licence

Proprietary. See `LICENSE.md`.
