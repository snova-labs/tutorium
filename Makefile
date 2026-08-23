export WWW_UID := $(shell id -u)
export WWW_GID := $(shell id -g)

.PHONY: setup up down restart build install migrate fresh seed test stan fmt fmt-check shell logs isolation

setup:         ## one command for a new developer
	@test -f .env || cp .env.example .env
	docker compose build
	docker compose up -d
	docker compose exec -T app composer install
	docker compose exec -T app php artisan key:generate
	docker compose exec -T app php artisan migrate --seed
	@echo "Ready: http://localhost:8080  |  mail http://localhost:8025  |  minio http://localhost:9001"

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

shell:
	docker compose exec app sh

logs:
	docker compose logs -f app worker
