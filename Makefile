check: ## Check code
	find ./ -name '*.php' -not -path './vendor/*' | xargs -r php -l
	vendor/bin/phpstan --memory-limit=512M
	vendor/bin/php-cs-fixer check
	composer audit
	vendor/bin/rector process -n

test: ## Test code (unit suite)
	vendor/bin/phpunit --testsuite=unit

test-integration: ## Test code (integration suite — requires composer-integration.json deps)
	vendor/bin/phpunit -c phpunit-integration.xml.dist

test-with-coverage: ## Test code with coverage (unit suite)
	XDEBUG_MODE=coverage vendor/bin/phpunit --testsuite=unit --coverage-html coverage

test-integration-with-coverage: ## Test integration suite with coverage (requires composer-integration.json deps)
	XDEBUG_MODE=coverage vendor/bin/phpunit -c phpunit-integration.xml.dist --coverage-html coverage

install-integration: ## Install composer dependencies including optional libs
	COMPOSER=composer-integration.json composer install --prefer-dist --no-progress --no-interaction

regenerate-baseline: ## Regenerate baseline
	vendor/bin/phpstan analyse --memory-limit=512M -b phpstan-baseline.neon

fix: ## Fix code
	vendor/bin/php-cs-fixer fix
	vendor/bin/rector process

## Help
help: ## List of all commands
	@grep -E '(^[a-zA-Z_0-9-]+:.*?##.*$$)|(^##)' Makefile \
	| awk 'BEGIN {FS = ":.*?## "}; {printf "${G}%-24s${NC} %s\n", $$1, $$2}' \
	| sed -e 's/\[32m## /[33m/' && printf "\n";

.DEFAULT_GOAL := help
.PHONY: help
