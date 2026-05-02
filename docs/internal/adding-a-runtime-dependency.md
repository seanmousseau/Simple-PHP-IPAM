# Adding a runtime dependency

> The runtime-dependency policy in `CLAUDE.md` → "Runtime dependencies" is the source of truth. This doc is the operational checklist — what to actually do when proposing a new Composer package or vendored frontend asset. Use it as a PR template.

The bar is high on purpose. Every dep added is one more attack surface, one more upgrade obligation, and one more thing that could break the "rsync and run" deployment story. Most candidates fail before reaching step 1.

---

## Step 0 — Should this be a dep at all?

Disqualifiers (any one of these → do not add):

- It implements **IPAM business logic** (subnet math, allocation, audit, permissions). All of this is bespoke to the project; no library competes with hand-rolled correctness here.
- It is a **UI/templating/framework layer** (Twig, Blade, Smarty, React, Vue, Svelte, Sass, PostCSS, Tailwind, Bootstrap). Hand-authored PHP + vanilla CSS/JS is the floor.
- It can be **replaced by ~20 lines of vanilla PHP**. The cost of one more dep is greater than the cost of writing the function.
- It is a **"nice to have"** — cleaner API, fancier syntax, slightly less verbose. Insufficient justification.
- The **license is GPL or LGPL without a bundling exception**. The release tarball ships `vendor/`, so viral-license dynamics matter.
- It has a **deep transitive dependency tree**. Prefer libraries with zero or few transitive deps. A 300KB lib with 20 transitive deps is worse than a 500KB lib with none.

If the candidate survives, continue.

---

## Step 1 — Acceptance criteria

A new Composer dep must satisfy **all six** criteria from `CLAUDE.md`:

| # | Criterion | How to verify |
|---|---|---|
| 1 | Narrow purpose (security-sensitive protocol or standards compliance) | One-paragraph description of what hand-rolling would require |
| 2 | Mature (>5 years active maintenance, visible advisories) | Check Packagist "First release" date + GitHub commit cadence over the last year |
| 3 | Widely used (used by recognised projects, not just star-counted) | Cite ≥2 projects of meaningful scale that depend on it |
| 4 | Minimal dependency tree | `composer show <pkg> --tree` — count transitive deps |
| 5 | Liberal license (MIT, BSD, Apache 2.0; LGPL only with explicit bundling exception) | Read the LICENSE file directly |
| 6 | Maintainer justification | Write the CLAUDE.md whitelist row + the one-paragraph why-vanilla-PHP-is-a-poor-fit |

If any criterion is shaky, do not add. There are no exceptions for "this is just a small utility."

### Vendored frontend assets (the carve-outs)

The two carve-outs are **data-visualization primitives** (e.g. uPlot for v3.8.0 dashboard charts) and **design-token libraries** (e.g. Open Props for v2.13.0). Same six criteria, plus:

- **Vanilla JS / vanilla CSS only.** No framework deps, no Sass, no PostCSS, no build step.
- **Self-hosted under `Simple-PHP-IPAM/assets/vendor/`.** Not Composer.
- **Single file or small handful**, no transitive deps.
- **Under ~50KB minified.** Lean is a hard criterion.

These carve-outs are narrow exceptions for **primitive data**, not for component libraries or rendering engines. Tailwind/Bootstrap/etc. remain forbidden under both.

---

## Step 2 — The PR

A dependency-addition PR has a fixed shape. Reviewers will look for all of it.

### Files in the PR

1. **`composer.json`** — `require` block addition with a tight version constraint (`^X.Y` for libs that follow semver carefully; `^X.Y.Z` if minor versions have introduced breakage in the past).
2. **`composer.lock`** — committed, generated locally with `composer update <vendor/pkg>`. Never commit a wholesale `composer update` with this dep PR; isolate the change.
3. **`CLAUDE.md`** — add a row to the "Current runtime dependency whitelist" table:
   ```text
   | <vendor/pkg> | ^X.Y | <one-line purpose> | #<issue>, v<release> |
   ```
   Do **not** edit any of the surrounding policy prose in the same PR — keep policy edits in a separate PR if needed.
4. **`CHANGELOG.md`** — under the in-flight release's `## [Unreleased]` section, add a bullet under **Added** or **Changed**: `Vendor <pkg> <version> for <purpose>`.
5. **The justification paragraph** — in the PR description, not in code. Cover: what hand-rolling would entail, why this specific library was chosen over alternatives, what the dep tree looks like, license verification.

### Optional but expected

- **Removal of any code the new dep replaces.** If you're adopting `phpseclib/phpseclib` for SFTP, the hand-rolled SFTP wrapper goes away in the same PR. Don't carry both.
- **A test that exercises the new dep's surface.** PHPUnit if pure PHP; Playwright if user-facing.

---

## Step 3 — Pre-merge checks

Before the local gate:

```bash
composer install --no-dev --optimize-autoloader   # production install
composer audit                                    # MUST be clean — fail if any advisories
composer show <vendor/pkg> --tree                 # eyeball the transitive tree
ls -lh vendor/<vendor>/<pkg>                      # rough size — large libs need extra justification
```

`composer audit` is run in CI and wired into the nightly schedule. A new dep that introduces a known advisory is a hard block.

### Tarball verification

The release-bundle build runs `composer install --no-dev --optimize-autoloader` — verify the bundle actually contains the new dep before tagging:

```bash
./releases/make_releases.sh Simple-PHP-IPAM <next-version>
tar -tzf ipam-<next-version>/ipam-<next-version>.tar.gz | grep <vendor>/<pkg>
```

If the tarball doesn't contain the dep, `composer install` did not run with `--no-dev` correctly or the dep is in `require-dev` by mistake.

---

## Step 4 — Post-merge

- The new dep is now part of the deploy story. Operators do **not** run `composer install` on the server — `vendor/` ships in the tarball. Verify on the next release that the dep is present after `upgrade.sh` runs (it strips and replaces `vendor/` from the tarball).
- Add the dep to the project's mental model — write a Memory MCP observation on the relevant release entity: dep name, version, purpose, PR.

---

## Current whitelist

The authoritative list lives in `CLAUDE.md` → "Current runtime dependency whitelist" and "Vendored frontend assets". Do not duplicate it here — the table moves whenever a new dep is added, and a duplicate would drift.

As of v3.21.x: **4 Composer packages** (phpmailer/phpmailer, robthree/twofactorauth, lbuchs/webauthn, phpseclib/phpseclib) and **1 vendored asset** (qrcode.min.js).

---

## Explicit non-adoptions

`CLAUDE.md` records dependencies that have been deliberately *not* adopted. Read those before proposing something similar:

- No HTTP client library yet. `ext-curl` + careful wrapping is the path. Revisit if curl wrapping proves painful at implementation time.
- No JWT/JWK library yet. The hand-rolled OIDC works.
- No JSON Schema validator. Custom fields use a bespoke lightweight type system.
- No templating engine, no DI container, no service locator, no ORM.

If your candidate falls into one of these areas, you are arguing against an existing decision. The PR description must explain what's changed since that decision — not just re-litigate it.

---

## Cross-references

- `CLAUDE.md` → "Runtime dependencies" — the policy.
- `CLAUDE.md` → "When to use classes vs functions" — the architectural ethos.
- `release-workflow.md` Phase 2 — bundle build that ships `vendor/`.
- `lessons-learned.md` — historical context on why the bar is this high.
