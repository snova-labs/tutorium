export WWW_UID := $(shell id -u)
export WWW_GID := $(shell id -g)

# Where commands run. Name the environment first to use the deployed containers on the server
# (from a clone of this repository there): `make staging codes`, `make production academy`.
# Without it, the same targets run against the local docker compose app. ENV=staging also works.
ENV ?= $(firstword $(filter staging production,$(MAKECMDGOALS)))
ifeq ($(ENV),)
  ARTISAN = docker compose exec app php artisan
else ifeq ($(ENV),staging)
  SLUG ?= tutorium-staging
  DB ?= tutorium_staging
else ifeq ($(ENV),production)
  SLUG ?= tutorium
  DB ?= tutorium
else
  $(error ENV must be staging or production (or left out for local))
endif
ifneq ($(ENV),)
  # -t only when there is a terminal, so the targets also work from scripts and cron.
  TTY := $(shell [ -t 0 ] && echo -t)
  ARTISAN = docker exec -i $(TTY) $(SLUG)-api php artisan
endif

.PHONY: help setup up down restart build install migrate fresh seed test stan fmt fmt-check shell logs isolation assets npm \
	demo academy artisan backup backups drill codes db-dump status staging production deploy promote

# `make staging <task>` / `make production <task>`: the environment word only selects where <task>
# runs; on its own it does nothing.
staging production:
	@:

help:          ## list the commands
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | sed -E 's/:.*## /\t/' | expand -t 16

setup:         ## one command for a new developer
	@test -f .env || cp .env.example .env
	docker compose build
	docker compose up -d
	docker compose exec -T app composer install
	docker compose exec -T app php artisan key:generate
	docker compose exec -T app php artisan migrate --seed
	@echo "Ready: http://localhost:8080  |  mail http://localhost:8025  |  silo http://localhost:9001"

build:
	docker compose build

up:
	docker compose up -d

down:
	docker compose down

restart: down up


install:       ## first run: key, migrate, seed
	docker compose exec app composer install
	docker compose exec app php artisan key:generate
	docker compose exec app php artisan migrate --seed

migrate:
	docker compose exec app php artisan migrate

fresh:         ## drop everything and rebuild with seed data
	docker compose exec app php artisan migrate:fresh --seed

seed:
	docker compose exec app php artisan db:seed

demo:          ## (re)build the demo academy: make demo, or make staging demo [PASSWORD=…]
	@test "$(ENV)" != production || { echo "The demo academy is never built on production."; exit 1; }
	$(ARTISAN) platform:demo-academy --fresh $(if $(PASSWORD),--password='$(PASSWORD)')

# ── Deploying (from any machine with the GitHub CLI signed in: gh auth login) ────────────────

deploy:        ## put main (or REF=…) on staging, and wait until it is built
	@command -v gh >/dev/null || { echo "Needs the GitHub CLI: https://cli.github.com, then gh auth login"; exit 1; }
	gh workflow run deploy-staging.yml --repo snova-labs/tutorium -f ref=$(or $(REF),main)
	@sleep 5
	@gh run watch --repo snova-labs/tutorium --exit-status \
		$$(gh run list --repo snova-labs/tutorium --workflow deploy-staging.yml --limit 1 --json databaseId -q '.[0].databaseId')
	@echo "Built. Portainer redeploys staging on its next poll (within 5 minutes)."

promote:       ## put what staging runs (or TAG=sha-…) on production, after asking
	@command -v gh >/dev/null || { echo "Needs the GitHub CLI: https://cli.github.com, then gh auth login"; exit 1; }
	@read -p "Promote $(or $(TAG),staging) to production? Take a dump first (make production db-dump). [y/N] " ok; [ "$$ok" = y ]
	gh workflow run promote.yml --repo snova-labs/tutorium -f tag=$(or $(TAG),staging)
	@echo "Then in Portainer: Stacks → tutorium → Pull and redeploy, with Re-pull image."

# ── Running an environment (local, or the server: make staging <task>, make production <task>) ──

academy:       ## create an academy; asks for the name, owner's email and name
	@name="$(NAME)"; email="$(EMAIL)"; owner="$(OWNER)"; \
	[ -n "$$name" ] || read -p "Academy name: " name; \
	[ -n "$$owner" ] || read -p "Owner's full name: " owner; \
	[ -n "$$email" ] || read -p "Owner's email: " email; \
	[ -n "$$name" ] && [ -n "$$email" ] && [ -n "$$owner" ] || { echo "All three are needed."; exit 1; }; \
	$(ARTISAN) platform:provision-academy "$$name" "$$email" "$$owner" \
		--timezone=$(or $(TZ),Asia/Kathmandu) $(if $(PRESET),--preset=$(PRESET))

artisan:       ## any artisan command; asks which (or CMD="…")
	@cmd="$(CMD)"; [ -n "$$cmd" ] || read -p "php artisan " cmd; $(ARTISAN) $$cmd

backup:        ## take a backup now
	$(ARTISAN) platform:backup

backups:       ## recent backups and restore drills
	$(ARTISAN) platform:backups

drill:         ## restore last night's backup into the drill database and check it
	$(ARTISAN) platform:restore-drill

codes:         ## sign-in codes and confirmation links from the last hour: make staging codes
	@test -n "$(ENV)" || { echo "Locally, mail is in Mailpit: http://localhost:8025 (on the server: make staging codes)"; exit 1; }
	@docker logs --since 1h $(SLUG)-api 2>&1 | grep -E -B1 "^Subject: Your sign-in code" | grep -E "^(To|Subject): " | tail -n 20
	@docker logs --since 1h $(SLUG)-api 2>&1 | grep -Eo "https?://[^ \"<]+/(sign-up/confirm|invitations)/[A-Za-z0-9]+" | tail -n 5

db-dump:       ## dump the database to ~/backups before a release: make production db-dump
	@test -n "$(ENV)" || { echo "Say which: make production db-dump, or make staging db-dump"; exit 1; }
	@mkdir -p $(HOME)/backups
	docker exec mysql sh -c 'MYSQL_PWD="$$MYSQL_ROOT_PASSWORD" mysqldump -u root --single-transaction --routines $(DB)' \
		| gzip > $(HOME)/backups/mysql-$(DB)_predeploy_$$(date +%F_%H-%M).sql.gz
	@ls -lh $(HOME)/backups | tail -n 1

status:        ## containers, health and memory: make staging status
	@test -n "$(ENV)" || { docker compose ps; exit 0; }
	@docker ps --filter name=$(SLUG)- --format 'table {{.Names}}\t{{.Status}}'
	@docker stats --no-stream --format 'table {{.Name}}\t{{.MemUsage}}' $$(docker ps -q --filter name=$(SLUG)-)

test:          ## full suite
	docker compose exec app php artisan test

isolation:     ## tenancy isolation harness only (blocking check in CI)
	docker compose exec app php artisan test --testsuite=Tenancy

stan:
	docker compose exec app ./vendor/bin/phpstan analyse --memory-limit=1G

fmt:
	docker compose exec app ./vendor/bin/pint

fmt-check:
	docker compose exec app ./vendor/bin/pint --test

shell:         ## a shell in the app container (make staging shell on the server)
	$(if $(ENV),docker exec -it $(SLUG)-api sh,docker compose exec app sh)

logs:          ## follow the app's logs (make staging logs on the server)
	$(if $(ENV),docker logs -f --tail 100 $(SLUG)-api,docker compose logs -f app worker)


npm:           ## one-off npm, e.g. make npm ARGS="install -D tailwindcss"
	docker compose run --rm node npm $(ARGS)

assets:        ## vite dev server on :5173
	docker compose --profile assets up -d node

composer:      ## install dependencies from composer.lock
	docker compose exec app composer install

require:       ## add a package: make require PKG="livewire/livewire"
	@test -n "$(PKG)" || { echo "Usage: make require PKG=\"vendor/package\""; exit 1; }
	docker compose exec app composer require $(PKG)