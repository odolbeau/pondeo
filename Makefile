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

# The compose project is named after the directory (see compose.yaml).
DEV_PROXY_DIR ?= $(HOME)/.local/share/dev-proxy
PROJECT = $(shell docker compose config --format json | python3 -c 'import json,sys; print(json.load(sys.stdin)["name"])')

# Prerequisites run in order: this target is not meant for `make -j`.
.PHONY: serve
serve: check-dev-proxy $(DEV_PROXY_DIR)/certs/pondeo.crt var/.images-built start vendor/autoload.php db-update ## Start this worktree's stack on demand (installs/updates only what's needed) and print its URL
	@echo ""
	@echo -e "\033[32mhttps://$(PROJECT).pondeo.localhost\033[0m"

.PHONY: serve-standalone
serve-standalone: export COMPOSE_FILE = compose.yaml:compose.override.yaml:compose.standalone.yaml
serve-standalone: var/.images-built start vendor/autoload.php db-update ## Same as serve, without the dev proxy: http://localhost:8080
	@echo ""
	@echo -e "\033[32mhttp://localhost:8080\033[0m"

.PHONY: check-dev-proxy
check-dev-proxy:
	@bin/check-dev-proxy

$(DEV_PROXY_DIR)/certs/pondeo.crt:
	bin/certs

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
	@mkdir -p var && touch var/.images-built

# Images are rebuilt only when what they are built from changes.
var/.images-built: compose.yaml $(wildcard .docker/php/*)
	@$(MAKE) build

.PHONY: start
start: ## Start the whole docker stack
	docker compose up --detach --remove-orphans --wait

.PHONY: stop
stop: ## Stop the docker stack
	docker compose stop

.PHONY: destroy
destroy: ## Destroy all containers, volumes, networks, ... (run by Orca when archiving a worktree, see orca.yaml)
	docker compose down --remove-orphans --volumes --rmi=local
	@rm -f var/.images-built

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
# Installed on demand, and again when the lock files change.
vendor/autoload.php: composer.lock
	$(MAKE) vendors-install

node_modules/.package-lock.json: package-lock.json
	$(MAKE) assets-install
	@touch $@

.PHONY: sanitize-and-check
sanitize-and-check: phpstan rector-fix cs-fix ts-check test ## Run Rector, PHP-CS-fixer, PHPStan, TypeScript & tests
	docker compose run --rm php composer dump-autoload --optimize --strict-psr --strict-ambiguous

.PHONY: ts-check
ts-check: node_modules/.package-lock.json ## Type-check the TypeScript frontend code
	docker compose run --rm node npm run check-types

.PHONY: test
test: vendor/autoload.php var/.test-db-ready ## Run all tests
	docker compose run --rm php bin/console cache:clear --env=test --no-debug
	docker compose run --rm php rm -f var/cache/bab_tested_routes_checker_bundle_route_storage
	docker compose run --rm php bin/phpunit
	docker compose run --rm php bin/console bab:tested-routes-checker:check

.PHONY: phpstan
phpstan: vendor/autoload.php ## Run PHPStan
	docker compose run --rm php vendor/bin/phpstan  --memory-limit=512M analyse

.PHONY: cs-lint
cs-lint: vendor/autoload.php ## Lint all files
	docker compose run --rm php bin/console lint:twig templates/
	docker compose run --rm php bin/console lint:yaml config/ .github/
	docker compose run --rm php vendor/bin/php-cs-fixer fix --dry-run --diff
	docker compose run --rm php vendor/bin/twig-cs-fixer lint

.PHONY: cs-fix
cs-fix: vendor/autoload.php ## Fix CS using PHP-CS
	docker compose run --rm php vendor/bin/php-cs-fixer fix
	docker compose run --rm php vendor/bin/twig-cs-fixer fix

.PHONY: rector-fix
rector-fix: vendor/autoload.php ## Launch Rector to fix files
	docker compose run --rm php vendor/bin/rector process
