# Runtime dependency policy

> Full policy text. The current whitelist + carve-out summary lives in `CLAUDE.md` (load-bearing every session). The procedure for proposing a new dep lives in `adding-a-runtime-dependency.md`. This doc is the policy rationale that informs both.
>
> In force since v2.9.0.

The project uses Composer-managed runtime dependencies under a narrow, curated policy. Goals:

1. Avoid hand-rolling security-sensitive network protocols and cryptography.
2. Preserve the "rsync and run" deployment story for end users.

---

## Deployment model

- `vendor/` is gitignored and never committed to source.
- `releases/make_releases.sh` runs `composer install --no-dev --optimize-autoloader` against the working tree before building the tarball.
- The tarball includes `vendor/` with all production deps pre-built.
- End users extract the tarball and run — no `composer install` on the server, no network access to packagist required at install time.
- `.htaccess` inside `vendor/` denies all web access to bundled library source.
- Security advisories are tracked via `composer audit` in CI (scheduled nightly and on every PR).

---

## When a runtime dependency is acceptable

A new dep must meet **all six** criteria:

1. **Narrow purpose.** It solves a security-sensitive protocol or standards compliance problem where hand-rolling is error-prone. Canonical examples: SMTP, SAML, OAuth/OIDC/JWT verification, LDAP, TOTP, WebAuthn, SSH/SFTP.
2. **Mature.** >5 years of active maintenance, with visible security advisories and a track record of prompt response.
3. **Widely used.** Big enough user base that bugs get found by someone else first. "Thousands of GitHub stars" is not a metric but "used by WordPress, Drupal, Joomla" is.
4. **Minimal dependency tree.** Prefer libraries with zero or few transitive deps. A 300KB lib with 20 transitive deps is worse than a 500KB lib with none.
5. **Liberal license.** MIT, BSD, Apache 2.0. No GPL-family licenses (viral). LGPL acceptable only with explicit bundling exception (PHPMailer is the precedent).
6. **Maintainer justification.** Adding a new dep requires a PR that updates the CLAUDE.md whitelist with the dep name, version constraint, purpose, and a one-paragraph justification explaining why vanilla PHP is a poor fit.

---

## Carve-outs (vendored frontend assets)

### Data-visualization primitives

Sparklines, time-series charts, gauges, heatmaps, and similar chart primitives MAY use a curated, vendored library if hand-rolling would require reinventing axis scaling, tick generation, DPI-aware canvas rendering, or responsive sizing. The bar is the same as the SMTP/SAML rule above plus four extra constraints:

- **Vanilla JS only** — no framework deps, no build step
- **Self-hosted** under `Simple-PHP-IPAM/assets/vendor/` rather than Composer
- **Single file** or small handful of files that can be copied in with no transitive deps
- **Under ~50KB minified** — "lean" is a hard criterion

Adding a viz library still requires a CLAUDE.md PR with the same justification as a Composer dep. The v3.8.0 UI rework is expected to vendor uPlot (~40KB, zero deps, MIT) for the dashboard time-series work; see #513.

### Design token libraries

Curated collections of CSS custom properties (spacing, sizing, typography, colour, shadows, borders, easings) MAY be vendored under the same rules as the viz carve-out. This exists because maintaining a homegrown token scale across ~40 pages is error-prone and because proven token libraries encode years of accessibility and responsive-design work that would otherwise have to be reinvented.

Constraints identical to viz libraries: **vanilla CSS only** (no Sass, no PostCSS, no build step), **self-hosted** under `assets/vendor/`, **single file or small handful**, **under ~50KB minified**, liberal license, mature, wide adoption.

A vendored token library is a *source* of tokens — the project-specific overrides still live in `assets/app.css` and take precedence. v2.13.0 #506 vendors [Open Props](https://open-props.style) (~30KB, MIT, curated and maintained by Adam Argyle of Google Chrome DevRel) as the token source instead of hand-rolling `--space-*`, `--size-*`, `--radius-*`, `--font-size-*`, etc.

### Important: neither carve-out re-opens the frontend-framework door

Tailwind, Bootstrap, React, Vue, Svelte, Sass, PostCSS, and every similar tool remain explicitly forbidden. The carve-outs are narrow exceptions for **primitive data** (chart points, design tokens) — not for component libraries, utility-class vocabularies, or rendering engines. When in doubt: vanilla CSS and vanilla JS are the default.

---

## When a runtime dependency is NOT acceptable

- **IPAM business logic.** Subnet math, CIDR parsing, address allocation, audit logging, permission checks, etc. All of this is bespoke to the project and has no library equivalent worth pulling in.
- **UI and rendering.** All HTML is hand-authored PHP. No templating engines (no Twig/Blade/Latte/Smarty — no new syntax, no compile cache, no reserved directives), no frontend frameworks (no React/Vue/Svelte), no CSS preprocessors (no Sass/Less). **Exception:** shared `ipam_render($view, $props)` helpers that `require` a PHP file under `Simple-PHP-IPAM/views/*.php` with `extract($props)` ARE allowed and encouraged — they are ordinary PHP functions, not a DSL. The anti-pattern is a compiled syntax layer, not code reuse. Data-viz primitives are covered by the carve-out.
- **Simple utilities.** If it can be done in 20 lines of vanilla PHP, it should be done in 20 lines of vanilla PHP. Do not pull in a library for one function.
- **"Nice-to-have" conveniences.** Libraries that make code 5% cleaner are not worth the dep. The bar is "hand-rolling is meaningfully dangerous or expensive," not "this API is nicer."

---

## Explicitly not adopted (deliberate choices)

- **No HTTP client library yet.** `ext-curl` + careful wrapping is the current path for webhook dispatch (#399, v3.3.0). May revisit if curl wrapping proves painful at implementation time — Guzzle or symfony/http-client would be the likely candidates.
- **No JWT / JWK library yet.** The hand-rolled OIDC in `lib.php` works and is not being retrofitted on speculation. May revisit if a security-sensitive bug surfaces or if the RFC tracking burden becomes obviously not worth it.
- **No JSON Schema validator.** Custom fields (#313, v3.5.0) use a bespoke lightweight type system, not JSON Schema.
- **No templating engine, no DI container, no service locator, no ORM.** These are architectural departures that do not fit this project's philosophy.

If a candidate falls into one of these areas, the proposing PR is arguing against an existing decision. The PR description must explain what's changed since that decision — not just re-litigate it.

---

## When to use classes vs functions

The project's application code is predominantly procedural — `lib.php` is a bag of top-level functions, and most pages are procedural PHP. The one deliberate exception is the `Dialect` family of classes under `dialects/` (introduced in v2.9.0), which encapsulates per-engine SQL differences.

**Classes are appropriate for polymorphic contracts with a small, closed set of implementations.** The `Dialect` interface with `SqliteDialect` / `MysqlDialect` / `PgsqlDialect` is the canonical example: the contract says "every DB engine must implement these methods with these signatures," and PHPStan level 9 enforces that at compile time. Without an interface, we would have to reinvent the same guarantee with array shape annotations or runtime dispatch, both of which are worse.

**Classes are not appropriate for utility functions, request handlers, or anything that would otherwise be a plain function.** Do not OO-ify `lib.php`. Do not wrap handlers in controller classes. Do not introduce a service locator or DI container. When in doubt, write a function.

**Namespaces are not used.** The project has zero namespaces today and a hand-rolled autoloader is not worth the complexity for the small number of classes we expect to introduce. Keep class names unambiguous (`Dialect`, `SqliteDialect`, etc.) and `require_once` explicitly in `init.php` or `lib.php`.

---

## Cross-references

- `CLAUDE.md` → "Runtime dependencies" — load-bearing summary + current whitelist.
- `adding-a-runtime-dependency.md` — proposal procedure / PR checklist.
- `lessons-learned.md` — historical context.
