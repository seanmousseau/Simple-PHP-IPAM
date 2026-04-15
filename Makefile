.PHONY: gate lint stan cs test sec

# Full local gate — must be green before pushing a PR.
# Mirrors the CI checks in .github/workflows/php-qa.yml.
gate: lint stan cs test sec
	@echo "✓ Local gate green."

lint:
	@find Simple-PHP-IPAM -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null

stan:
	@vendor/bin/phpstan analyse --memory-limit=1G --no-progress

cs:
	@vendor/bin/phpcs

test:
	@vendor/bin/phpunit

sec:
	@semgrep --config=.semgrep/rules.yml --error --quiet Simple-PHP-IPAM/
