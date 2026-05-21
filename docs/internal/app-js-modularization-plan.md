# `assets/app.js` modularization plan (#939 + #1047)

> **Status:** ✅ shipped 2026-05-21 (v3.34.0). All phases complete; #939 + #1047 closed. The historical narrative below is retained for archaeological context — see CHANGELOG v3.34.0 for the final shipped result (46 modules under `assets/modules/`, regression spec at `testing/playwright/tests/frontend-modules.spec.ts`).
> **Scope:** v3.34.0 Refactor Wave 3 anchor work.
> **Slot:** wraps #939 (the split) and #1047 (the test umbrella) into a single PR per project invariant ("every helper lands with ≥1 caller", "tests land with the code they cover").
> **Source docs:** `docs/internal/archive/code_quality_review.md` finding D4; `docs/internal/coding-guide.md` frontend conventions; `docs/internal/design-guide.md` UI section; v3.34.0 PRs #1261 (icons + dead code), #1262 (drawer consolidation), #1265 (api.php P2).

This plan is the prerequisite for the actual code work. The code work is **not** in scope for this document — it lands in a follow-up PR after this plan is reviewed and approved. The "operating mode" rules in `lessons-learned.md` §8 explicitly require this for architectural splits.

---

## ⚠️ Amendment 1 (2026-05-20) — main-IIFE helper hoisting

After Phases 0 + 1 landed (PR #1268: scaffold + IpamDrawer extraction — the standalone IIFE case), the start of Phase 2 — extracting main-IIFE concerns C01–C18 — revealed a structural problem the §2.1 inventory missed:

**The main IIFE is not a clean container of independent concerns.** Inspection of the first concern (C02 — theme/banners, line range 68–91 in the post-Phase-0 monolith) showed:

- C02's call-site code at L68–91 invokes helpers `updateThemeButton()`, `cycleTheme()`, `dismissUpdate()`.
- Those helpers are defined at the **TOP** of the main IIFE (L41–60), above the single big `DOMContentLoaded` handler that wraps every concern from C02 through C18.
- Those helpers themselves reference further IIFE-local helpers (`currentTheme()`, `applyTheme()`) — likely at the very top of the file (lines 1–40).
- The `DOMContentLoaded` event wires up every concern's listeners; concerns share the closure scope.

**Implication for the rollout:** extracting just the L68–91 range to its own module would lose access to the helpers it depends on. The §2.1 concern table assumed clean line ranges, but the helpers and call sites are interleaved with several other concerns' helpers and call sites in a way the line-range view doesn't show.

**Revised Phase 2 strategy (replaces the §5 Phase 2 entry below):**

The original §5 Phase 2 ("extract main-IIFE concerns one at a time in numeric order, ~18 commits") is not directly viable. Replace with a two-step sub-phase:

- **Phase 2a — In-place restructure.** Before any code moves out of `_monolith.js`, wrap each concern in its own per-concern IIFE *inside the file*. Move each concern's helpers down to live with the concern's call site, rather than at the top. The `DOMContentLoaded` handler splits into N independent per-concern IIFEs. No code leaves the file in this phase; the diff is intra-file restructure only. Per-concern IIFEs that need cross-concern helpers either inline them or, if shared by ≥2 concerns, hoist them to the file's top-level as named functions on `window.IpamUtils.*` (new). Run the full Playwright suite after each per-concern restructure. **~18 commits, all intra-file.**

- **Phase 2b — Extract.** Now that each concern is a self-contained IIFE in `_monolith.js`, the mechanical per-concern move to `assets/modules/<NN>-<name>.js` (the original §5 Phase 2 spirit) becomes a pure cut-and-paste. **~18 commits, all moves.**

**Phases 3 (trailing-IIFE concerns), 4 (delete `_monolith.js`), 5 (test umbrella), 6 (delete `assets/app.js`) are unchanged** — trailing concerns C20–C33 are already standalone IIFEs in the original file, so they don't need a 2a step.

**Total commit count revised:** ~18 (Phase 2a) + ~18 (Phase 2b) + ~14 (Phase 3) + 4 (Phases 4/5/6) ≈ **~54 commits** vs the original ~36. The increase is the cost of doing the boundary work in-place first instead of trying to do it during the move.

**Open question for scope-lock:**

Should each concern's per-concern IIFE in Phase 2a use the *closure pattern* (`(function(){ … }())` with locals hidden) or the *module pattern with no IIFE* (top-level `var` declarations, relies on browser script-scope semantics like IpamDrawer does today)? Closure pattern preserves the current monolith's encapsulation; no-IIFE pattern flattens to one logical block per module without the wrapping ceremony. Recommendation at scope-lock: closure pattern, because (a) it matches the existing IIFE style across the file, (b) it's the documented approach in §2.2's load-order analysis, (c) future contributors are more likely to add new code via the existing pattern than the new one.

**Decision needed before Phase 2a starts:** Sean confirms one of:
- proceed with Phase 2a / 2b as above (recommended)
- skip the in-place restructure — accept that every Phase 2 extract is a "concern + helpers" multi-line move, larger per-commit diffs
- defer Phase 2/3/4 entirely until a future release; ship v3.34.0 with `_monolith.js` + `20-drawer.js` as the final layout for now

**✅ Decided 2026-05-20 (Sean):** **Phase 2a + 2b as above.** Proceed with in-place per-concern IIFE restructure inside `_monolith.js` first (~18 commits, intra-file), then mechanical cut-and-paste extract to `assets/modules/<NN>-<name>.js` (~18 commits, all moves). Closure-pattern per-concern IIFEs (matches existing style). Next-session start: pick the first concern (recommend C01 — bootstrap, the smallest and most independent) and run a per-concern Phase 2a sample commit to validate the pattern before doing the rest in batch.

---

## 1. Goal

Split `Simple-PHP-IPAM/assets/app.js` (currently **3,439 lines / ~148 KB minified-equivalent**, single file loaded as one `<script defer>` from `lib/presentation.php:393`) into per-concern modules under `Simple-PHP-IPAM/assets/modules/*.js`, each loaded as its own `<script defer>` tag. The browser executes deferred scripts in DOM order, so emit order = run order.

**Hard invariants (cannot be violated):**

- **Behaviour-preserving.** No user-visible change. Same selectors, same flows, same event handlers, same CSP shape.
- **No bundler introduced.** The project ships as PHP-on-Apache; the deploy story is "tar up the tree, drop it on the server". Adding a build step is a much bigger architectural change than this refactor and is explicitly out of scope.
- **CSP unchanged.** `script-src 'self'` stays. Every module loads from `assets/modules/*.js?v=…` via asset-buster querystring, exactly the same shape as `assets/app.js?v=…` does today.
- **Load order preserved where it matters.** `IpamDrawer` is the only cross-module global with a real consumer in another module. That consumer order must be respected (drawer defined before consumers run).
- **No `global $config;` regression** (ADR-003 — enforced by `testing/scripts/lib-module-linter.php` repo-wide post v3.33.0). Not directly applicable to JS but called out so the JS split doesn't accidentally trigger a CSP/lint gate elsewhere.
- **Three-driver gate passes locally** before push. Same rule as every other PR in this project.

**Non-goals (out of scope, will be filed as follow-ups if discovered):**

- Rewriting any concern's behaviour. The split is a syntactic re-layout, not a refactor of logic inside any block.
- Migrating any IIFE to ES modules (`type="module"`). Plain `<script defer>` is the issue's prescription.
- Consolidating the webhooks-page `#wh-form-drawer` onto IpamDrawer — that's a separate third drawer system, deferred (already noted in PR #1262 description).

---

## 2. Current state (post-wave-3)

`assets/app.js` after PRs #1261 (icons), #1262 (drawer consolidation), #1265 (api.php P2) has three top-level structural regions:

| Region | Lines | Bytes-ish | Notes |
|---|---|---|---|
| **Main IIFE** `(function(){ … }())` | 1–2068 | ~75 KB | Wraps the page-load handlers, form behaviour, sidebar, drawers, dashboard widgets, command palette. |
| **`IpamDrawer` IIFE** `var IpamDrawer = (function () { … }())` | 2071–2440 | ~14 KB | The drawer system survives wave-3; consolidated post #1262. |
| **Trailing un-IIFE'd code** | 2444–3439 | ~36 KB | Independent feature blocks added incrementally over time, never wrapped in the main IIFE. Most are themselves IIFEs (`(function(){…}())`). |

### 2.1 Concern inventory

Numbered by current line ranges. These are the units that become per-concern modules; the names map directly to `assets/modules/<name>.js`.

**Main-IIFE concerns (18):**

| # | Lines | Module name | Concern |
|---|---|---|---|
| C01 | 2–67 | `00-bootstrap.js` | skeleton-loader removal, theme-seed from `<meta>`, IIFE shell |
| C02 | 68–91 | `10-theme-banner.js` | theme toggle, dismiss update banner, dismiss admin banners |
| C03 | 92–122 | `15-site-group-collapse.js` | site-group collapse/expand |
| C04 | 123–179 | `20-ping-shortcuts.js` | live-ping buttons, ⌘N drawer shortcut, alert-recipients clear-all |
| C05 | 180–364 | `30-forms-core.js` | auto-submit selects, password show/hide (with reveal flow), pre-auth replay marker |
| C06 | 365–499 | `35-forms-confirm-bulk.js` | confirm dialogs (forms + buttons + links), submit-on-change, loading state, bulk-select, SSO toggle, reCAPTCHA |
| C07 | 500–566 | `40-search-validation.js` | search-page site→subnet cascade, IP/CIDR validate, wheel-hijack guard |
| C08 | 567–656 | `50-sidebar.js` | sidebar hamburger toggle, resize observer, inline status toggle |
| C09 | 657–711 | `60-fill-ip-spinners.js` | data-fill-ip helper, import spinner, restore-apply spinner |
| C10 | 712–765 | `65-contact-typeahead.js` | contact typeahead (data-contact-typeahead) |
| C11 | 766–915 | `70-dashboard-prefs.js` | dashboard pinnable widgets, site filter, per-user column visibility |
| C12 | 916–1020 | `75-subnet-addr-grids.js` | subnet list/map view toggle, inline cell editing |
| C13 | 1021–1727 | `80-command-palette.js` | ⌘K command palette (LARGEST single concern; ~26 KB) |
| C14 | 1728–1837 | `85-contact-picker.js` | contact picker (v3.0.0 #563) |
| C15 | 1838–1911 | `a0-dhcp-export.js` | DHCP export card on dhcp_pool.php |
| C16 | 1913–1967 | `a1-backups-modals.js` | backups.php modal handlers (CSP-safe) |
| C17 | 1968–1983 | `a2-totp-toggle.js` | TOTP backup-code toggle on totp_verify.php |
| C18 | 1984–2068 | `a3-uplot-chart.js` | uPlot dashboard growth chart |

**Standalone-IIFE concerns (15):**

| # | Lines | Module name | Concern |
|---|---|---|---|
| C19 | 2071–2440 | `b0-ipam-drawer.js` | **IpamDrawer global** (#517) — exposed on `window`, consumed by C13 (palette) + C26 (webhooks) |
| C20 | 2444–2592 | `c0-subnets-filter.js` | site-filter pill strip on subnets.php |
| C21 | 2593–2624 | `c1-addresses-bulk-bar.js` | bulk-select bar on addresses table |
| C22 | 2625–2722 | `c2-webhooks-page.js` | webhooks page (drawer + gen_secret + test-fire) |
| C23 | 2723–2749 | `c3-addresses-cascade.js` | addresses device→interface dropdown cascade |
| C24 | 2750–2786 | `c4-collapsible-rows.js` | collapsible row toggle (sites admin) |
| C25 | 2787–2834 | `d0-passkey-verify.js` | passkey verify page (#689) |
| C26 | 2835–2947 | `d1-step-up-prompt.js` | step-up auth prompt (#1107) — WebAuthn `credentials.get()` |
| C27 | 2948–3020 | `d2-passkey-register.js` | passkey registration (change_password.php) |
| C28 | 3021–3091 | `d3-settings-anchor.js` | settings.php tab-anchor remap (#749) |
| C29 | 3092–3313 | `e0-backup-destinations.js` | v3.17 destinations admin |
| C30 | 3314–3355 | `e1-restore-confirm.js` | restore confirm-typing gate + remote-backup delete-with-confirm |
| C31 | 3356–3405 | `e2-destinations-verify-all.js` | v3.25.0 destinations Verify-all bulk action (#850) |
| C32 | 3406–3429 | `e3-skeleton-toggle.js` | v3.25.0 skeleton-toggle helper (#855) — writes `window.ipamSkeleton` |
| C33 | 3430–3439 | `e4-resuming-card.js` | resuming-card POST (deep-link return after timeout) |

**Total: 33 concerns → 33 modules.** The original D4 finding cited "12 concerns" — actual surface has more because concerns added incrementally over v3.0–v3.34.

### 2.2 Cross-module dependencies

The only real cross-module dependency is `IpamDrawer`:

| Defined | Consumed by | Constraint |
|---|---|---|
| `IpamDrawer` — module `b0-ipam-drawer.js` (C19) | C13 command-palette (calls `IpamDrawer.open(...)` at current L1029, L1192), C26 webhooks-page (`window.IpamDrawer.close()` at current L2421), plus the global `[data-drawer-tpl]` / `[data-drawer-url]` delegates inside C19 itself | **C19 must load before C13 and C26.** Move `b0-ipam-drawer.js` to slot **`20-drawer.js`** (load before `30-forms-core.js`); both consumers are later. |

Other `window.*` interactions are all module-internal (one module writes + reads its own `window.__ipamPwReplayInProgress` or `window.ipamSkeleton`).

### 2.3 Load order rationale

`<script defer>` executes in DOM emission order. The proposed numeric-prefix file naming pins the order:

```
00-bootstrap.js
10-theme-banner.js
15-site-group-collapse.js
20-drawer.js              ← C19 (was b0); promoted because palette + webhooks need it
25-ping-shortcuts.js      ← C04 renumbered
30-forms-core.js          ← C05
35-forms-confirm-bulk.js  ← C06
40-search-validation.js   ← C07
50-sidebar.js             ← C08
60-fill-ip-spinners.js    ← C09
65-contact-typeahead.js   ← C10
70-dashboard-prefs.js     ← C11
75-subnet-addr-grids.js   ← C12
80-command-palette.js     ← C13 (consumes IpamDrawer, loads after 20-drawer.js)
85-contact-picker.js      ← C14
a0-dhcp-export.js         ← C15
a1-backups-modals.js      ← C16
a2-totp-toggle.js         ← C17
a3-uplot-chart.js         ← C18
c0-subnets-filter.js      ← C20
c1-addresses-bulk-bar.js  ← C21
c2-webhooks-page.js       ← C22 (consumes IpamDrawer via window guard)
c3-addresses-cascade.js   ← C23
c4-collapsible-rows.js    ← C24
d0-passkey-verify.js      ← C25
d1-step-up-prompt.js      ← C26
d2-passkey-register.js    ← C27
d3-settings-anchor.js     ← C28
e0-backup-destinations.js ← C29
e1-restore-confirm.js     ← C30
e2-destinations-verify-all.js ← C31
e3-skeleton-toggle.js     ← C32
e4-resuming-card.js       ← C33
```

33 modules. Numeric prefixes encode the constraint. Alphabetic sort = load order.

---

## 3. `lib/presentation.php` change

Today (`lib/presentation.php:381–393`):

```php
$jsV = e(ipam_asset_buster('assets/app.js'));
// …
echo "<script defer src='assets/app.js?v={$jsV}'></script>";
```

Becomes:

```php
$jsModules = [
    '00-bootstrap', '10-theme-banner', '15-site-group-collapse',
    '20-drawer', '25-ping-shortcuts', '30-forms-core',
    '35-forms-confirm-bulk', '40-search-validation', '50-sidebar',
    '60-fill-ip-spinners', '65-contact-typeahead', '70-dashboard-prefs',
    '75-subnet-addr-grids', '80-command-palette', '85-contact-picker',
    'a0-dhcp-export', 'a1-backups-modals', 'a2-totp-toggle',
    'a3-uplot-chart',
    'c0-subnets-filter', 'c1-addresses-bulk-bar', 'c2-webhooks-page',
    'c3-addresses-cascade', 'c4-collapsible-rows',
    'd0-passkey-verify', 'd1-step-up-prompt', 'd2-passkey-register',
    'd3-settings-anchor',
    'e0-backup-destinations', 'e1-restore-confirm',
    'e2-destinations-verify-all', 'e3-skeleton-toggle',
    'e4-resuming-card',
];
foreach ($jsModules as $mod) {
    $v = e(ipam_asset_buster("assets/modules/{$mod}.js"));
    echo "<script defer src='assets/modules/{$mod}.js?v={$v}'></script>";
}
```

`assets/app.js` itself is deleted in the same commit. The cache-buster machinery already exists; nothing new needed.

---

## 4. Test umbrella (#1047)

`testing/playwright/tests/csp-no-inline.spec.ts` (already shipped, #906) handles the CSP regression guard. Three new test pieces:

### 4.1 Module-load-order spec
`testing/playwright/tests/frontend-modules.spec.ts` (new). Per page in a representative roster (login, dashboard, subnets, addresses, settings, backup_admin, change_password), assert:

- Every expected `<script defer src='assets/modules/*.js?v=…'>` tag is present in the rendered HTML.
- They appear in the documented order (numeric-prefix lexicographic).
- No `assets/app.js` references remain anywhere.

Implementation: HTML scrape via `page.goto(...).text()` + regex, same shape as `csp-no-inline.spec.ts`.

### 4.2 Cross-module `window.*` namespace contract
Same spec file. Asserts the documented `window.*` surface after `DOMContentLoaded`:
- `window.IpamDrawer` exists and has `.open`, `.openNode`, `.close` methods
- `window.ipamSkeleton` exists (set by C32) only on pages that include backup_admin destinations
- No unexpected `Ipam*` globals beyond the documented two

### 4.3 `prefers-reduced-motion` smoke
Same spec file. Emulates `(prefers-reduced-motion: reduce)`, navigates each page, asserts the documented animation modules respect the media query (existing CSS-level rule at `app.css:1446-1449` doesn't change; this test just pins it to a Playwright run).

### 4.4 Existing tests verification
Run the full Playwright suite on every driver matrix slot. Specifically watch for regressions on:
- `addresses.spec.ts` "Reserve Infra IPs pill" (rewritten in #1262 — relies on `IpamDrawer.openNode`)
- `account-mfa.spec.ts` (passkey + TOTP — relies on C25/C26/C27 wiring)
- `backup_restore.spec.ts` (relies on C29/C30/C31)
- `addresses-site-filter.spec.ts` (relies on C20)

---

## 5. Rollout phases

Each phase is a separate commit. Tests rerun after each.

1. **Phase 0 — Scaffolding.** Create `Simple-PHP-IPAM/assets/modules/` directory. Update `lib/presentation.php` to emit the new `<script defer>` set, BUT load `assets/modules/00-monolith.js` as a single-file copy of current `app.js` first. This proves the new emit path works without yet splitting any concern. Run gate. Commit.

2. **Phase 1 — Extract `b0-ipam-drawer.js`** (now `20-drawer.js`) **first.** It's the cross-module dependency anchor; getting it right early unblocks the rest. Run gate. Commit.

3. **Phase 2 — Extract main-IIFE concerns (C01 → C18).** **⚠️ Superseded by Amendment 1 at the top of this document.** The original "one at a time in numeric order" approach is not viable as written because the main-IIFE concerns share hoisted helpers. Amendment 1 splits this into Phase 2a (in-place restructure to per-concern IIFEs inside `_monolith.js`) then Phase 2b (the cut-and-paste extraction). Re-read the amendment before starting Phase 2.

4. **Phase 3 — Extract trailing-IIFE concerns (C20 → C33).** Same per-concern-one-commit pattern. ~14 commits.

5. **Phase 4 — Delete `00-monolith.js`.** When all concerns are extracted, the monolith file is empty (or contains only the IIFE shell wrapper). Delete it and remove the entry from the module list. Commit.

6. **Phase 5 — Land the test umbrella (#1047).** Tests added in 4.1, 4.2, 4.3 above. Plus a sweep through `assets/app.js` references in docs (CLAUDE.md, design-guide.md, coding-guide.md, internal docs) — replace with `assets/modules/`. Commit.

7. **Phase 6 — Delete `assets/app.js`.** Final commit. The file no longer exists.

**Total: ~36 commits across one PR.** Heavy for review but each commit is small and reversible.

**Alternative pacing if 36 commits is too granular:** group main-IIFE concerns by 3–5 (e.g., one commit = C01+C02+C03+C04 = `bootstrap + theme + collapse + ping-shortcuts`). Reduces to ~12 commits. The smaller-commit pattern is safer for review; the larger-commit pattern is faster but harder to bisect on regression. **Recommendation: start with the smaller-commit cadence; switch to larger only if the review is bogging down.**

---

## 6. Open questions to resolve at scope-lock

1. **Module naming.** Numeric prefix `20-drawer.js` vs. semantic `drawer.js`. Numeric guarantees `<script defer>` order without a maintained config; semantic reads better in editor tabs. Recommendation: numeric, because the load-order constraint is a real invariant (IpamDrawer-before-consumers). The constraint living in the filename is harder to break than the constraint living in a config file.

2. **Per-page module subsets.** Today every page loads `assets/app.js` whole. After split, the cleanest path is: every page loads all 33 modules. Browsers cache them individually, so subsequent navigations are mostly cache hits. **Alternative:** per-page module manifests (e.g., login.php only loads `00-bootstrap`, `10-theme-banner`, `30-forms-core`). Cleaner but couples `lib/presentation.php` to a per-page concern map — exactly the kind of indirection that turned `lib.php` into an unmaintainable monolith. **Recommendation:** load all on every page. Revisit only if real perf measurements justify it.

3. **Cache-busting strategy.** Today `ipam_asset_buster()` hashes the file content. After split, 33 hashes are computed on every page render. Negligible (file_get_contents on small files; ~3 ms total) but worth measuring on a slow disk. **Recommendation:** keep the per-file hash; cache the result inside `ipam_asset_buster()` for the request lifetime (memoize). Probably already memoised — verify at execution time.

4. **Phase 0 monolith name.** `00-monolith.js` vs. some other transitional name. Bikeshed. **Recommendation:** use `_monolith.js` (underscore-prefix so it sorts BEFORE `00-`); makes it obvious that the file is transitional and to-be-deleted.

---

## 7. Risks and mitigations

| Risk | Mitigation |
|---|---|
| **A concern hidden inside a larger block.** A label like "Search-page site → subnet cascade filter" (C07) might wrap behaviour another module also depends on. | Read each extraction's diff before committing — every variable declared at function-scope inside the main IIFE must either move with its concern or stay in a shared bootstrap. The current grep for `window.*` reads/writes is the cross-module dep audit; do the same for top-level `var`/`function` declarations inside the main IIFE during execution. |
| **`<script defer>` order incompatibility with `async`-loaded vendor scripts** (uPlot, recaptcha). | The current code already loads vendors via `<script defer>` from CDN; defer-vs-defer preserves order. Spot-check `views/_head.php` (or wherever those `<script>` tags live) during execution. |
| **Test fixture cookies.** Playwright tests assume `DOMContentLoaded` fires once per navigation. Adding 33 `<script defer>` tags doesn't change that — but the parse-then-execute cost might shift slightly. | Re-baseline test timeouts only if they actually flake. Don't pre-emptively raise timeouts. |
| **Visual regression** (#775 dashboard VR) **not yet restored.** | Out of scope here; #775 is a separate wave-3 item. After modularization lands, VR baseline may need a re-snap if the load timing shifts any animation-bound layout. Document in the PR body as a known follow-up. |

---

## 8. Concrete acceptance criteria (PR description copy)

When the actual PR for #939 + #1047 opens, the test plan should include:

- [ ] `assets/app.js` deleted; `assets/modules/` has 33 numeric-prefixed `.js` files
- [ ] `lib/presentation.php` emits `<script defer>` tags in the documented order
- [ ] `composer ci-gate` passes (PHPUnit 1517/1517, PHPStan no errors, PHPCS clean, icon audit clean)
- [ ] CI 3-driver + full Playwright matrix passes — especially `addresses.spec.ts` "Reserve Infra IPs pill" (depends on IpamDrawer being loaded before the command palette consumes it)
- [ ] CSP regression guard (`csp-no-inline.spec.ts`) passes — no inline `<script>` or `<style>` reintroduced
- [ ] New module-load-order spec (`frontend-modules.spec.ts`) passes
- [ ] Manual smoke: dashboard, subnets, addresses, settings, login, change_password, backup_admin — every interactive feature works exactly as before
- [ ] No `IpamFoo` global appears in browser DevTools that wasn't there before (cross-check via window key snapshot before/after)
- [ ] CHANGELOG entry under v3.34.0 documents the split and the `assets/app.js` → `assets/modules/` move
- [ ] `docs/internal/design-guide.md` updated to reference `assets/modules/` instead of `assets/app.js`
- [ ] `CLAUDE.md` (project) hot-cache section — no changes needed (it references `lib.php`, not `app.js`)

---

## 9. References

- Finding **D4** — `docs/internal/archive/code_quality_review.md` §D.P1
- Issue **#939** — refactor(frontend): split assets/app.js into per-concern modules (D4)
- Issue **#1047** — tests: app.js module-loading + frontend regression + P3 cleanup verification (v3.30.0)
- Issue **#940** — IpamVirtualTable (closed in PR #1261; informs how to handle dead-code-during-extraction)
- Issue **#1243** — drawer consolidation (closed in PR #1262; informs IpamDrawer load-order rule)
- Issue **#906** — CSP regression guard (`testing/playwright/tests/csp-no-inline.spec.ts`)
- `docs/internal/design-guide.md` § "Frontend layout" — current `assets/app.js` convention
- `docs/internal/coding-guide.md` § "Frontend conventions" — CSP rules, no inline handlers
- `lessons-learned.md` § 8 — operating-mode commitments (this plan exists *because* of the "architectural splits need integration test plan first" rule)
