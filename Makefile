.DEFAULT_GOAL := help
SHELL := bash

# Silences `docker compose`'s "[+] Running ..." / "Container ... Creating/Created"
# progress lines — keeps only the actual command output.
export COMPOSE_PROGRESS := quiet

.PHONY: help
help:
	@echo -e "\033[1;36mAVAILABLE COMMANDS :\033[0m"
	@awk 'BEGIN {FS = ":.*##"} /^[a-zA-Z_0-9-]+:.*?##/ { printf "  \033[32m%-20s\033[0m %s\n", $$1, $$2 } /^##@/ { printf "\n\033[33m%s\033[0m\n", substr($$0, 5) } ' Makefile

##@ Base commands
.PHONY: doctor
doctor: ## Check that everything is OK
	@bin/doctor

.PHONY: install
install: serve assets-install ## Start the docker stack and prepare the application
	@echo -e "\n"
	@echo -e "\033[32m🥳 EVERYTHING IS RUNNING! 🥳\033[0m"

.PHONY: serve
serve: ## Start this worktree's stack on demand (installs/seeds only what's missing) and print its URL
	bin/serve

.PHONY: vendors-install
vendors-install: ## Install vendors
	docker compose run --rm php composer ins

.PHONY: vendors-update
vendors-update: ## Update all vendors
	docker compose run --rm php composer up
	docker compose run --rm node npm update

##@ Docker commands
.PHONY: build
build: ## Build docker stack
	docker compose build

.PHONY: start
start: ## Start the whole docker stack
	docker compose up --detach --remove-orphans

.PHONY: stop
stop: ## Stop the docker stack
	docker compose stop

.PHONY: destroy
destroy: ## Destroy all containers, volumes, networks, ... (run by Orca when archiving a worktree, see orca.yaml)
	docker compose down --remove-orphans --volumes --rmi=local

.PHONY: bash
bash: ## Enter in the application container directly
	docker compose exec php /bin/bash

.PHONY: psql
psql: ## Enter in DB container
	docker compose exec database psql -h database -U app app

.PHONY: psql-test
psql-test: ## Enter in Test DB container
	docker compose exec database psql -h database -U app app_test

##@ Backends commands
.PHONY: db-reset
db-reset: ## Reset DB
	docker compose run --rm php bin/reset-db

.PHONY: db-update
db-update: ## Apply migrations and re-run the imports, keeping existing data
	docker compose run --rm php bin/import-data

.PHONY: db-reset-test
db-reset-test: ## Reset test DB
	docker compose run --rm -e APP_ENV=test php bin/reset-db
	@mkdir -p var && touch var/.test-db-ready

var/.test-db-ready:
	@$(MAKE) db-reset-test

##@ Assets commands
.PHONY: assets-install
assets-install: ## Download assets dependencies
	docker compose run --rm node npm install

##@ Quality commands
.PHONY: sanitize-and-check
sanitize-and-check: phpstan rector-fix cs-fix ts-check test ## Run Rector, PHP-CS-fixer, PHPStan, TypeScript & tests
	docker compose run --rm php composer dump-autoload --optimize --strict-psr --strict-ambiguous

.PHONY: ts-check
ts-check: ## Type-check the TypeScript frontend code
	docker compose run --rm node npm run check-types

.PHONY: test
test: var/.test-db-ready ## Run all tests
	docker compose run --rm php rm -f var/cache/bab_tested_routes_checker_bundle_route_storage
	docker compose run --rm php bin/phpunit
	docker compose run --rm php bin/console bab:tested-routes-checker:check

.PHONY: phpstan
phpstan: ## Run PHPStan
	docker compose run --rm php vendor/bin/phpstan  --memory-limit=512M analyse

.PHONY: cs-lint
cs-lint: ## Lint all files
	docker compose run --rm php bin/console lint:twig templates/
	docker compose run --rm php bin/console lint:yaml config/ .github/
	docker compose run --rm php vendor/bin/php-cs-fixer fix --dry-run --diff
	docker compose run --rm php vendor/bin/twig-cs-fixer lint

.PHONY: cs-fix
cs-fix: ## Fix CS using PHP-CS
	docker compose run --rm php vendor/bin/php-cs-fixer fix
	docker compose run --rm php vendor/bin/twig-cs-fixer fix

.PHONY: rector-fix
rector-fix: ## Launch Rector to fix files
	docker compose run --rm php vendor/bin/rector process
