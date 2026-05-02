# Internationalization (i18n) and localization (l10n) — design

> **Forward-looking design.** Not in force. No version assigned yet. This doc locks the architectural decisions so future implementation work doesn't have to re-litigate them. Mirrors the pattern of `v4-tenancy-design.md`.
>
> Read this when: scoping the i18n release stream, evaluating a feature that touches user-facing strings, or designing a related preference (timezone, date format, currency).

---

## Goals

1. **Per-user locale preference** with a sensible cascade for unauthenticated paths and shared/install defaults.
2. **No build step.** All translation lookup is runtime. Catalogs ship as files; reload is process-level (PHP-FPM cache or app restart).
3. **Standard tooling.** Translators get a workflow they already know (Poedit / Weblate / Crowdin). No bespoke editing of PHP arrays.
4. **Minimal call-site noise.** Inline `_('User accounts')` should read like ordinary PHP, not a key-lookup ceremony.
5. **Translatable strings only.** User-entered free text (subnet descriptions, notes, hostnames) is **never** translated.

## Non-goals (explicit)

- **Not translating the API surface.** REST/JSON responses stay English. Clients that build localized UIs handle their own translation. This keeps the API contract stable.
- **Not translating documentation in phase 1.** `docs/*.md` remains English-only until the localization stream has shipped + stabilized; doc translation is a separate workstream.
- **Not translating the marketing site as part of this stream.** `simplephpipam.com` is WordPress and has its own l10n story (Polylang, WPML, etc.).
- **Not auto-detecting locale silently.** Unauthenticated pages may use `Accept-Language` as a hint, but authenticated users see their explicit preference — no guessing once we know who they are.

---

## Locale model

### Identifier format

BCP-47 short codes: `en-CA`, `en-US`, `fr-CA`, `fr-FR`, `de-DE`, `es-ES`, `pt-BR`, etc.

Always store the full `language-region` form, even when only the language portion is used today (`en-CA` vs `en-US` matters for date format and spelling, even if both load the same `en` Gettext catalog initially).

### Cascade (resolution order)

Mirrors the existing `ipam_setting()` cascade pattern:

1. **User preference** — new column `users.locale TEXT NULL`. Set via the Account page.
2. **Tenant default** — *(v4.0.0+ only)*. New column on `tenants` table. Skipped in v3.x.
3. **Install default** — new setting `branding.default_locale` (registry default `en-CA`).
4. **`Accept-Language` header** — for unauthenticated paths only (login page, password reset emails). Parse, match best available locale, fall through to (3) if no match.
5. **Hard fallback** — `en-CA`. Always exists as the source-of-truth catalog.

### Where the cascade is consulted

- **`init.php`** — sets `$_SESSION['locale']` after login from `users.locale` (or cascade if null). Pages read from session.
- **`api.php`** — does not have a session by default; locale is irrelevant because API responses are not translated. (Confirmed non-goal above.)
- **Email dispatch (`ipam_send_*` helpers)** — must respect the **recipient's** locale, not the sending user's. Look up `users.locale` for the recipient inside the helper, do not inherit ambient session locale.
- **Background jobs (`cron.php`, `scan_run.php`)** — install default; no user context.

---

## String catalog technology

### Decision: PHP `ext-gettext` (`.po` / `.mo` files)

Selected over JSON or PHP-array catalogs because:

- **PHP-native extension.** Not a Composer dep — does not have to clear the runtime-dependency-policy bar. Just an `ext-gettext` requirement bumped in `install.md`.
- **Industry standard.** WordPress, Drupal, Joomla all use Gettext. Translators have existing workflows.
- **Concise call sites.** `_('User accounts')` reads naturally. The single-character `_` function and its `_n($singular, $plural, $count)` plural variant are intuitive in procedural PHP.
- **Mature tooling.** Poedit (desktop), Weblate (self-hosted SaaS-equivalent), Crowdin (commercial). All speak `.po` natively.
- **Plural-form support.** Gettext handles complex plural rules (Russian: 4 forms; Polish: 3; Arabic: 6) via `Plural-Forms` header. Don't roll your own `count > 1 ? 's' : ''`.

### Fallback option

If `ext-gettext` proves unacceptable on target deployments (verify during phase 1 spike), fall back to **flat JSON catalogs** (`lang/en-CA.json`, `lang/fr-CA.json`) with a hand-rolled `t($key, $vars = [])` helper. Same call-site shape, weaker tooling. Keeps the architecture intact.

Decision criteria for the fallback: confirm during phase 1 that ≤5% of plausible deployment targets lack `ext-gettext`. If higher, JSON is the safer choice.

### File layout

```text
Simple-PHP-IPAM/
  lang/
    en-CA/
      LC_MESSAGES/
        ipam.po       # translator-edited source
        ipam.mo       # compiled binary (committed; built by msgfmt at PR time)
    fr-CA/
      LC_MESSAGES/
        ipam.po
        ipam.mo
```

Domain name: `ipam` (matches `bindtextdomain('ipam', __DIR__ . '/lang')`).

`.mo` files are committed (not built at deploy time). Translator workflow: edit `.po` in Weblate → PR → CI runs `msgfmt` → review compiled `.mo` is in the diff. Avoids requiring `gettext` package on deployment hosts.

---

## What gets translated, what doesn't

| Surface | Translated? | Notes |
|---|---|---|
| UI labels, buttons, table headers, nav, validation messages | ✅ | Primary scope |
| Server-emitted error messages (flash, 4xx/5xx pages) | ✅ | |
| Email templates (notifications, alerts, password reset) | ✅ | Recipient's locale, not sender's |
| Audit log `details` JSON | 🟡 | Store action key + render translated at display time. Existing JSON details are user-entered; only translate the labels around them |
| API JSON responses | ❌ | Per non-goal — stays English |
| User-entered free text (descriptions, notes, hostnames, tag names, contact names) | ❌ | Never. Stored as user typed it |
| `docs/*.md` user-facing docs | ❌ in phase 1 | Separate workstream, much later |
| Marketing site | ❌ | Separate WordPress workstream |
| Setting registry `label` and `description` (in `ipam_setting_definitions()`) | ✅ | Wrap in `_()` at definition time |

---

## JS string handling

UI strings rendered by `app.js` (command palette labels, dynamic table cells, dialog text) need server-supplied translations. Two options:

| Option | Mechanism | Pros | Cons |
|---|---|---|---|
| **Bootstrap (recommended)** | `page_header()` emits `<script>window.IPAM_I18N = {...};</script>` with the strings used by `app.js`, scoped to the current page | No extra request; works offline; simple | Bloats every page render with all-locale strings even if unused |
| **Endpoint** | `api.php?resource=i18n&locale=fr` returns a JSON catalog. Cacheable per-locale | Smaller per-page payload; cacheable | Extra request; first-paint blocking unless preloaded |

**Recommendation: bootstrap**, scoped to a tight allowlist of JS-needed keys. Fits the project's "no extra moving parts" style. Audit annually whether the bootstrapped string set has bloated.

---

## Date / number / currency formatting

Use `ext-intl` (`IntlDateFormatter`, `NumberFormatter`) — also PHP-native, also standard. Pairs naturally with the Gettext locale.

- **Dates** — `IntlDateFormatter::formatObject($dt, IntlDateFormatter::MEDIUM, $locale)`. Existing `branding.timezone` setting handles the *zone*; the new locale handles the *format*.
- **Numbers** — `NumberFormatter::create($locale, NumberFormatter::DECIMAL)->format($n)` for thousands separators (en-US: `1,234`; fr-FR: `1 234`; de-DE: `1.234`).
- **Currency** — not currently used by IPAM, but if added (e.g. cost tracking on subnets), use `NumberFormatter::CURRENCY` with explicit ISO 4217 code, never inferred from locale.
- **Dataset sizes / byte units** — keep existing helpers; SI/IEC prefixes are language-neutral.

---

## Plurals

Always use `_n($singular, $plural, $count)`:

```php
echo _n('1 user', '%d users', $count);
```

The Gettext `.po` `Plural-Forms` header per language declares the plural rules. Don't add ad-hoc plural logic in PHP — defer to Gettext.

Common plural-form counts:

| Language | Forms |
|---|---|
| English, German, Dutch, Spanish | 2 |
| French | 2 (with different boundary: 0 = singular) |
| Russian, Polish | 3 |
| Arabic | 6 |

The first non-English language we ship validates that the pipeline handles non-binary plurals correctly.

---

## Right-to-left (RTL) languages

Out of scope for phase 1. When Arabic or Hebrew is added:

- `<html dir="rtl">` toggle based on locale's `Direction` metadata.
- Audit `assets/app.css` for hard-coded `left:`/`right:` and replace with logical properties (`inline-start`/`inline-end`).
- Test sidebar collapse, command palette positioning, table-action-pill alignment specifically — these have directional assumptions.
- Defer to phase 3+; design now (using logical properties going forward) so the rewrite cost stays bounded.

---

## Translation source

Phase 3+ decision (not committed today). Candidates:

| Source | Cost | Quality | Sustainability |
|---|---|---|---|
| **Self-translated** | Free | High (maintainer-edited) | Doesn't scale beyond 1-2 languages |
| **Weblate** (self-hosted OSS) | Self-hosting cost | Crowd quality + reviewer workflow | Sustainable if community engages |
| **Crowdin** (SaaS, free OSS tier) | Free for OSS | Crowd quality | Vendor lock-in concerns |
| **Machine translation baseline** (DeepL API, then human review) | API cost (low) | Acceptable starting point | Requires human review before shipping |

Recommendation when phase 3 lands: machine-translated baseline + Weblate for human review + community PRs. Avoid shipping MT-only — it gets the project mocked.

---

## Test surface

i18n changes the test matrix:

- **Unit tests (`tests/`)** — locale-agnostic except for explicit i18n-helper tests. Force `en-CA` in `tests/bootstrap.php`.
- **Integration tests (`test_api.sh`)** — API is not translated; no change.
- **Playwright** — text assertions break under locale changes. Strategy: **lock CI Playwright runs to `en-CA`** (set via env var read by `init.php`). Run a per-release smoke pass under each shipped locale to catch layout regressions.
- **Visual regression** — text expansion (German strings ~30% longer) breaks layout assumptions. Capture VR snapshots per locale; the diff catches button overflow, truncated table headers, etc.

---

## Phased rollout

Each phase is a release of its own. **Do not bundle phases** — each is large, each carries different risk classes, each benefits from its own review window.

| Phase | Scope | Risk | Sequencing |
|---|---|---|---|
| **1: Infrastructure** | `_()` + `_n()` helpers, locale resolution + cascade, `users.locale` column, `branding.default_locale` setting, `ext-gettext` + `ext-intl` requirement bump in `install.md`. Ship `en-CA` + `en-US` baseline (catalogs identical except for date format) to prove the pipeline. No translation work yet | Low — pure plumbing | Anywhere in v3.x |
| **2: String extraction sweep** | Wrap every user-facing string in `_()` across ~40 pages + email templates + setting registry. Big mechanical PR. Mostly diff-noisy, low-risk | Medium — easy to miss strings, easy to break HTML escaping order (always `e(_(...))`, never `_(e(...))`) | Immediately after phase 1 |
| **3: First non-English translation** | Pick one language with real demand (`fr-CA` is a strong candidate given maintainer location). Validate translator workflow end-to-end. ~200-400 strings | Medium — exposes UX issues like text expansion (German), plural rules (Russian), RTL layout (Arabic — defer) | After v4.0.0 multi-tenancy ships, NOT concurrent |
| **4: Crowdsourcing tooling** | Self-hosted Weblate or Crowdin OSS, GitHub PR integration, contributor docs. Open the door for community translations | Low | After phase 3 stabilizes |

### Sequencing with v4.0.0 multi-tenancy

**Do not ship i18n + multi-tenancy in the same release window.** Both are large, both touch session/preference/cascade plumbing. Pick a sequence:

- **Phase 1 + 2** (infrastructure + extraction) can land **before** v4.0.0 — they don't conflict with the tenancy schema work.
- **Phase 3** (first translation) should land **after** v4.0.0 ships and stabilizes — to avoid concurrent multi-tenant + multi-lingual debugging.
- **The tenant-level locale layer** (item 2 in the cascade) is added in v4.0.0 itself or as a v4.0.x patch — trivial schema add, no UI exposure until phase 3.

---

## Open questions (deferred decisions)

These are not blocking phase 1 but should be decided before phase 3:

1. **Fallback rendering.** When a locale is partially translated, fall back to English silently or mark with `[?]`? Recommend **silent fallback** — marking partials creates maintenance burden and makes the UI ugly for users whose locale is in-progress.
2. **Right-to-left strategy.** Adopt CSS logical properties now (cheap) so the future RTL switch is bounded? Recommend **yes** — start writing new CSS with `inline-start`/`inline-end` immediately even though we don't ship RTL languages yet.
3. **Translation memory / glossary.** Maintain a project-wide glossary (`subnet`, `VLAN`, `prefix`, etc. should translate consistently)? Recommend **yes** in phase 3, via Weblate's built-in glossary feature.
4. **Date format choice — locale default vs explicit setting.** Should an admin be able to override the locale's default date format (e.g. force ISO 8601 across the install)? Recommend **yes** — IPAM is an operations tool, ISO 8601 is unambiguous and many ops users prefer it regardless of locale. Add `branding.date_format` with options `locale-default`, `iso-8601`, `us-medium`, etc.
5. **Translator workflow for `setting.update` audit details.** When an admin changes a setting, the audit log records the old/new values. If the value is a translatable enum (e.g. `theme: auto|light|dark`), should the log store the key or the rendered translation? Recommend **store the key**, render at display time.

---

## Out of scope for this design

- **Currency conversion** — IPAM has no currency surface today. If added later, separate design needed.
- **Time-zone-aware multi-region operations** — `branding.timezone` already exists at install level. Per-user timezone preference is a separate feature.
- **Right-to-left as a phase 1-3 deliverable** — explicitly deferred.
- **Translation of API documentation (`api.md`)** — separate doc workstream.

---

## Cross-references

- `CLAUDE.md` → "UI conventions" — load-bearing rule that all output goes through `e()`. The i18n call becomes `e(_('...'))`.
- `adding-a-setting.md` → registry `label` and `description` fields will be wrapped in `_()` once phase 1 ships.
- `v4-tenancy-design.md` → settings cascade pattern that the locale cascade mirrors.
- `cleanup.md` → Localization category (Canadian English baseline) — informs phase 1's choice of `en-CA` as default.
- `feedback_canadian_english.md` (auto-memory) — context for choosing `en-CA` over `en-US` as the source-of-truth locale.
