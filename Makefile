.PHONY: gate lint stan cs test sec gate-mysql pw-sqlite pw-mysql

# Full local gate — must be green before pushing a PR.
# Mirrors the CI checks in .github/workflows/php-qa.yml (sqlite slot).
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

# MySQL gate — spins up a fresh mysql:8.0 service container via the
# containerized Playwright harness and runs the full Playwright suite
# against db_driver=mysql. Mirrors the CI matrix slot in
# playwright-nightly.yml. Slower (~2 min). Optional — CI runs the same
# path on every PR, but handy when editing the Dialect layer or any
# cross-engine SQL site.
gate-mysql: pw-mysql
	@echo "✓ MySQL gate green."

pw-sqlite:
	@set -e; \
	  root="$$(pwd)"; \
	  trap 'bash "$$root/testing/playwright/teardown-app.sh"' EXIT; \
	  bash "$$root/testing/playwright/bootstrap-app.sh" sqlite; \
	  ( cd "$$root/testing/playwright" && \
	    IPAM_BASE_URL=https://127.0.0.1:8443 \
	    IPAM_ADMIN_USER=demo IPAM_ADMIN_PASS=demo \
	    npx playwright test )

pw-mysql:
	@set -e; \
	  root="$$(pwd)"; \
	  trap 'bash "$$root/testing/playwright/teardown-app.sh"' EXIT; \
	  bash "$$root/testing/playwright/bootstrap-app.sh" mysql; \
	  ( cd "$$root/testing/playwright" && \
	    IPAM_BASE_URL=https://127.0.0.1:8443 \
	    IPAM_ADMIN_USER=demo IPAM_ADMIN_PASS=demo \
	    IPAM_DRIVER=mysql \
	    npx playwright test )
