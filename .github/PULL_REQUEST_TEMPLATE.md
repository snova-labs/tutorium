## What

<!-- One or two sentences. What is true after this that was not true before. -->

Closes #

## Why

<!-- Who is better off, and how. If this is a refactor, what it makes possible or safer. -->

## How to check it

<!-- The reviewer should be able to see it work, not just read that it does. -->

```bash
docker compose exec app php artisan test --filter=
```

## Checks

- [ ] `php artisan platform:verify` passes
- [ ] Tests cover the change, and would fail without it
- [ ] `./vendor/bin/pint` and `phpstan` are clean
- [ ] No `dd()`, `dump()`, `ray()` or commented-out code
- [ ] Comments explain *why*, not what the next line does

## Tenancy

- [ ] Any new model is classified in `config/tenancy.php`
- [ ] No query filters by `tenant_id` by hand — the scope does that
- [ ] Any `withoutScoping()` call is deliberate and has a comment saying why

## Data and privacy

- [ ] No real name, address or contact detail in code, tests, seeds or fixtures
- [ ] Nothing sensitive added to the audit log or to an exception message
- [ ] Times are stored as UTC instants; local dates keep their timezone

## Compatibility

- [ ] Migrations run cleanly down and up again
- [ ] No breaking change to `/api/v1` — or it is labelled `breaking change` and noted in the changelog
- [ ] `CHANGELOG.md` updated under Unreleased

---

<sub>snova labs · see `docs/CONTRIBUTING.md` for branch and commit conventions.</sub>
