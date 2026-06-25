SHELL := /bin/bash

.PHONY: bootstrap up down migrate serve demo-flow

bootstrap:
	./scripts/bootstrap.sh

up:
	docker compose -f docker-compose.yml up -d

down:
	docker compose -f docker-compose.yml down

migrate:
	php bin/console doctrine:migrations:migrate --no-interaction

serve:
	php -S 127.0.0.1:8000 -t public

demo-flow:
	./scripts/demo_flow.sh

