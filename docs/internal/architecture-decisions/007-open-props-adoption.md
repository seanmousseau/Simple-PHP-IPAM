# ADR-007: Adopt Open Props as the CSS design-token base

**Status:** draft (amended 2026-05-20 — see correction below)
**Decided:** —
**Scope:** UX foundation milestone #59 + UX nav milestone #60. Gates the migration of `assets/app.css` from a hand-rolled token scale to Open Props as the source of truth for spacing, radii, font sizes, colors, easings, and animations. Affects every CSS variable read across `app.css` (84 KB) and every PHP view that inlines a `style=""` (≤30 sites).
**Stamped by:** —

---

## ⚠️ Correction (2026-05-20)

This ADR was drafted on the premise that Open Props was **not** loaded by the project. **That premise was wrong.**

While doing the v3.34.0 modularization Phase 0 inspection of `lib/presentation.php`, the `<link rel='stylesheet' href='assets/vendor/open-props.min.css?v=…'>` tag was discovered at line 386, and the vendor file already exists at `Simple-PHP-IPAM/assets/vendor/open-props.min.css` (committed in `e433f134 feat(#506): design token scales + Open Props vendor`).

What the original ADR §2 inventory missed: it searched `views/` + `assets/` + `lib/presentation.php` for the literal text `open-props` but the search did not reach the right line range. The vendor file + the load `<link>` have been in the tree since well before this ADR was filed.

**Effect on the ADR-007 issue arc:**

| # | Title | Original status | Corrected status |
|---|---|---|---|
| #1255 | vendor Open Props + load ahead of app.css | new work | **already done** — closed with explanation |
| #1256 | migrate spacing + radii + font-size tokens to OP | new work | still pending |
| #1257 | migrate neutral color tokens to OP grays | new work | still pending |
| #1258 | adopt OP shadows + easings + animations | new work | still pending |
| #1259 | cleanup + delete hand-rolled blocks + close #941 | new work | still pending |

`assets/app.css` does NOT actually consume OP tokens today — only one stray `var(--font-size-1)` matches an OP-defined token (likely coincidence). The remaining four migration issues (#1256–#1259) are still real, still scoped correctly, and still slotted to v3.35.0 + v3.36.0.

**Effect on the ADR's reasoning:** the recommendation (Option A) stands. The "vendor + drop-in" step was already done; the migrations are still the value-add. The ADR §3 "Decision drivers" section (no-build-step, milestone-#59 coupling, etc.) still applies. The ADR §5 "Implications" file list still applies (minus the new vendor file, which already exists).

**No other ADR text below has been edited.** The original "Open Props is NOT loaded" language in §2 stays as-is — it is the historical record of the (incorrect) understanding at the time of drafting.

---

## Context

`assets/app.css` defines a hand-rolled design-token scale at the `:root` level:

- **Color tokens** (~20): `--bg`, `--fg`, `--muted`, `--border`, `--border-strong`, `--card`, `--card-2`, `--link`, `--danger`, `--success`, `--warn`, `--btn`, `--btnfg`, `--btn-secondary`, `--btn-secondary-fg`, `--brand`, `--badge-bg`, `--input-bg`, `--shadow`, `--nav-bg`.
- **Spacing** (14): `--space-0` through `--space-13` on a 2px / 4px / 6px / 8px / 10px / 12px / 14px / 16px / 20px / 24px / 40px / 48px / 60px scale.
- **Radii** (9): `--radius-xs/sm/md/lg/xl/2xl/3xl/4xl/pill`.
- **Font sizes** — *two parallel scales:*
  - `--font-size-2xs` through `--font-size-9xl` (15 values, .65rem → 1.9rem)
  - `--text-xs` through `--text-2xl` (8 values, .6875rem → 1.5rem)
- **Z-index** (13): `--z-base` through `--z-page-overlay`.
- **Misc:** `--focus-ring`, `--font-sans`, `--font-mono`, `--shadow`.

Reference counts across `app.css` + views (top): `--border` (89), `--muted` (60), `--link` (51), `--card` (50), `--space-3` (47), `--space-5` (41), `--space-4` (41), `--space-6` (40), `--fg` (39), `--space-2` (37).

Dark mode is implemented via `html[data-theme="dark"]` + `@media (prefers-color-scheme: dark)` overrides (lines 98–169 + 842 + 1103 + 2034 of `app.css`). The override blocks redefine the same color tokens with darker values; the rest of the token surface (spacing/radii/fonts) is theme-invariant.

10 `!important` instances. Several are legitimate (`prefers-reduced-motion`, `.hidden` utility, chart-width override); a few are workaround-shaped (`.subnet-node margin-left:0`, `.button-loading color:transparent`).

**Issue #941** ("reconcile hand-rolled CSS variables with Open Props") was filed under the premise that Open Props was already loaded. **It is not.** No `open-props` reference exists in `views/`, `assets/`, or any include path. The "duplication" the issue describes is actually self-duplication — the parallel `--font-size-*` and `--text-*` scales coexist by accident, not by design.

[Open Props](https://open-props.style) is a curated set of CSS variables (~30 KB of `:root` declarations, gzips ~6 KB) that ship as either a single bundle or modular files (`colors.min.css`, `sizes.min.css`, `shadows.min.css`, `normalize.min.css`, etc.). It is plain CSS — no build step, no JS dependency. Maintained by [Adam Argyle](https://github.com/argyleink), GA since 2022.

## Decision drivers

- **Coupling to UX foundation milestone #59.** That milestone is "type scale, badge primitive, color-not-only-signal, dark-mode brand-link, mobile font sizing" — every item wants a coherent token base. Adopting Open Props *during* #59 means the token rename does real visual work; doing it standalone is pure churn.
- **No build step.** Project is server-rendered PHP with no bundler. Adoption must work as a vendored `*.css` file loaded via `<link rel="stylesheet">`.
- **Backward compatibility.** Existing custom-CSS use sites (admin tweaks, marketing-site overrides) read the current variable names. Adoption must not break installs that have custom rules referencing `--space-4` etc.
- **Migration cost.** 84 KB of `app.css` references hand-rolled tokens. Mechanical search-and-replace is possible for spacing/radii/font-size but NOT for color tokens (the project's `--link: #0b57d0` doesn't map 1:1 to any OP gray/blue — it's a brand-specific choice).
- **Vendor footprint.** ~30 KB minified, ~6 KB gzipped if we take the whole bundle; less if we take only the modules we use.
- **Theme story.** OP ships `open-props/normalize/dark` + `open-props/buttons/dark` as additive layers. Our current dark mode is inline overrides; OP's pattern would consolidate them.
- **No coupling to #58 (current refactor wave 3).** Wave 3 is `app.js` modularization. CSS work doesn't have to share the same PR.

## Options considered

### Option A — Adopt Open Props as the token base; migrate over v3.35.0 + v3.36.0

**Mechanism:** Vendor `open-props.min.css` (full bundle) to `Simple-PHP-IPAM/assets/vendor/open-props/open-props.min.css`. Load via `<link rel="stylesheet">` in `lib/presentation.php` immediately before `app.css`. In v3.35.0, migrate spacing + radii + font-size tokens (mechanical rename). Keep the IPAM color tokens (`--link`, `--brand`, etc.) as project-defined overrides on top of OP — those are brand identity, not generic. In v3.36.0, migrate the secondary `--text-*` scale onto OP fluid-font-size variants and consolidate dark-mode overrides onto OP's pattern. Close #941 after the sweep.

**Pros:**
- Coherent design-token vocabulary that new contributors recognize.
- Token base maintained externally; we stop maintaining a custom scale.
- Pairs naturally with #59 UX foundation work (type scale, badge primitive, dark-mode).
- OP shadow/easing/animation tokens give us primitives we currently don't have.
- Self-duplication of `--font-size-*` vs `--text-*` gets resolved as a side effect.

**Cons:**
- ~6 KB gzipped extra payload on every page.
- Migration touches ~300 `var(--…)` reference sites in `app.css` plus ≤30 PHP inline-style sites.
- Project loses fine-grained control over the exact spacing curve (current `--space-1: 2px` doesn't have a clean OP equivalent; closest is `--size-1: 0.25rem` = 4px).
- New external dep we have to keep current (advisory check on each release).

### Option B — Vendor Open Props but use it as a peer scale (both coexist)

**Mechanism:** Same as Option A's drop-in, but **don't migrate**. Keep current `--space-*` etc. for legacy code; use `--size-*` for any new CSS written during #59 and forward. Eventually the legacy scale gets retired by attrition.

**Pros:**
- Zero migration cost up front.
- New work uses the modern vocabulary; old work stays put.

**Cons:**
- Two token scales in the same file forever. The exact failure mode #941 originally complained about.
- New contributors face two systems and have to pick one.
- "Eventually retire by attrition" never actually happens in practice.

### Option C — Don't adopt; document the hand-rolled scale as intentional

**Mechanism:** Add a header comment to `app.css` declaring the existing scale as the project's design system. Audit the 10 `!important` instances and the self-duplication between `--font-size-*` and `--text-*`. Close #941 as a documentation-only change.

**Pros:**
- Zero churn.
- Full control over the curve.
- Closes #941 in one commit.

**Cons:**
- Project keeps maintaining its own scale forever — including future additions (any new spacing/radius value has to be picked and named by hand).
- New contributors learn an IPAM-specific vocabulary instead of a shared one.
- Doesn't help #59 UX foundation; that milestone still has to invent its own type-scale, badge primitive, color-not-only-signal logic from scratch.
- Doesn't address `--font-size-*` vs `--text-*` self-duplication; that gets its own follow-up issue.

### Option D — Adopt a different token system (Tailwind tokens-only, Radix Colors, etc.)

**Mechanism:** Pick a different external token system and vendor it.

**Pros:** depends on choice.

**Cons:** Tailwind tokens require JIT or `@tailwind` directives → build step → no fit. Radix Colors is colors-only — doesn't cover spacing/radii/fonts so we'd still maintain a hand-rolled half-scale. **Strawman; rejected.**

## Recommendation

**Pick Option A.** Adopt Open Props as the token base, migrate over v3.35.0 + v3.36.0, keep IPAM color tokens as brand overrides.

The decisive driver is the **coupling to milestone #59**. Every item in #59 (type scale, badge primitive, color-not-only-signal, dark-mode brand-link, mobile font sizing) is design-system work that needs a token vocabulary. If we don't adopt OP, #59 has to either reinvent that vocabulary in-house or skip the foundation work and patch each surface individually. Adopting OP means #59's tokens do the work for #60 (nav) and #61 (data-heavy) for free.

The secondary driver is **eliminating self-duplication**. `--font-size-*` vs `--text-*` is the kind of accidental drift that ADRs are supposed to catch. The OP migration forces a decision: pick one source, delete the other.

Option C (don't adopt) is the second-best choice and is the right answer if scope pressure on the v3.35.0/v3.36.0 releases makes the migration unsafe. The ADR records both so the deferral path is documented.

## Implications

**GH issues to open** (this ADR creates 5 tracking issues; see "Issue plan" below):
- ADR-007 vendoring + drop-in
- ADR-007 sizing token migration (space, radius, font-size)
- ADR-007 color token migration with brand overrides
- ADR-007 secondary tokens (easings, shadows, animations)
- ADR-007 cleanup — delete hand-rolled blocks, close #941

**GH issues that change shape:**
- **#941** — refile/repurpose as the **cleanup** issue (close hand-rolled scale, audit `!important`). The "Open Props is loaded → duplication" framing is wrong and gets corrected.

**Files that change:**
- New: `Simple-PHP-IPAM/assets/vendor/open-props/open-props.min.css` (~30 KB vendored).
- `Simple-PHP-IPAM/lib/presentation.php` — add `<link rel="stylesheet">` for OP ahead of `app.css`.
- `Simple-PHP-IPAM/assets/app.css` — token block rewrite (spacing, radii, font-size) + dark-mode override consolidation.
- `Simple-PHP-IPAM/views/*.php` — sweep any inline `style="…"` that reads `--space-*` / `--radius-*` / `--font-size-*` (estimated ≤30 sites).
- `docs/internal/design-guide.md` — document the OP-based system.

**Schema migrations needed:** none.

**Docs to update:**
- `docs/internal/design-guide.md` — new "Design tokens" section pointing at OP.
- `docs/internal/coding-guide.md` — frontend conventions section: prefer OP variables over inline values.
- `docs/internal/roadmap.md` — §6/§7 slot updates reflecting the v3.35.0/v3.36.0 adoption arc.

**Future ADRs unblocked:** none directly. Decouples #59 from "invent design system" work.

**Sequencing constraint:** OP vendor + drop-in must land **first** within v3.35.0 (before any #59 visual work) so #59 can consume the tokens. Migration can interleave with other #59 issues.

**Backward compatibility note:** existing installs with custom CSS referencing `--space-4` will break in v3.35.0. Release notes must call this out as a breaking-ish change with a deprecation alias period — keep the hand-rolled `--space-*` block as aliases (`--space-4: var(--size-2)`) for one release (v3.35.0), drop in v3.36.0. **Open question** below.

## Open questions

1. **Alias period for backward compatibility.** Do we keep `--space-*` as aliases pointing at OP values for one release, or do we cut over cleanly in v3.35.0 and call it a breaking change in CHANGELOG? Recommendation: keep aliases through v3.35.0, drop in v3.36.0. Lower install-breakage risk; one extra commit's worth of work.
2. **Color migration scope.** OP ships `--gray-{0-12}`, `--blue-{0-12}` etc. Do we migrate IPAM's neutral palette (`--bg`, `--fg`, `--muted`, `--border`, `--card`) onto OP grays, or keep our exact hex values as brand identity? Recommendation: migrate neutrals onto OP grays (gives us a coherent light/dark pair); keep `--brand`, `--link`, `--danger`, `--success`, `--warn` as IPAM-specific.
3. **OP `normalize` adoption.** OP ships an optional `normalize.css`-style baseline. Adopting it would change default styling of forms/inputs/headings repo-wide — a much bigger surface than tokens-only. Recommendation: **don't** adopt normalize in v3.35.0/v3.36.0; tokens-only.
4. **Bundle vs modular.** Vendor `open-props.min.css` (everything) or only `sizes.min.css` + `colors.min.css` + `fonts.min.css`? Recommendation: full bundle. Difference is ~4 KB gzipped; not worth the tax of picking modules at release time.

## Issue plan

Five tracking issues, attached to milestones #59 (foundation work) and #60 (overflow):

| # | Title | Milestone | Effort |
|---|---|---|---|
| ADR-007-1 | `feat(css): vendor Open Props and load ahead of app.css` | #59 | ~½ day |
| ADR-007-2 | `refactor(css): migrate spacing + radii + font-size tokens to Open Props` | #59 | ~2 days |
| ADR-007-3 | `refactor(css): migrate neutral color tokens to OP grays; keep brand overrides` | #59 | ~1 day |
| ADR-007-4 | `feat(css): adopt OP shadows + easings + animation primitives` | #60 | ~1 day |
| ADR-007-5 | `cleanup(css): delete hand-rolled token blocks; audit !important; close #941` | #60 | ~½ day |

Total ~5 days across two releases.

## Releases impacted (per roadmap.md §6/§7)

| Slot | Theme | OP work |
|---|---|---|
| **v3.35.0** | UX foundation — design system (#59) | ADR-007-1, ADR-007-2, ADR-007-3 land. Sizing + neutral colors migrated. Hand-rolled tokens kept as aliases for compat. |
| **v3.36.0** | UX nav — sidebar + command palette + theme (#60) | ADR-007-4, ADR-007-5 land. Animation/shadow tokens + cleanup. Aliases removed. |

## Fold-in evaluation (work that pairs naturally with OP releases)

**Fold into v3.35.0 (#59) alongside OP work:**

- **Existing #59 scope items already pair perfectly:**
  - Type scale → consume OP `--font-size-*` directly.
  - Badge primitive → use OP `--size-*` for padding + OP color tokens.
  - Color-not-only-signal → use OP semantic colors (`--red-*` for danger + an icon).
  - Dark-mode brand-link → consolidate onto OP dark pattern.
  - Mobile font sizing → use OP `--font-size-fluid-*` for the responsive curve.
- **Fold-in candidate:** dark-mode override consolidation across the 4 scattered `html[data-theme=dark]` blocks in `app.css` (currently lines 144, 166, 842, 1103). Worth doing in the same PR as ADR-007-3 since the migration touches every color token anyway.

**Fold into v3.36.0 (#60) alongside OP cleanup:**

- **Existing #60 scope item that pairs:**
  - Theme toggle improvements → with OP dark/light pattern in place, theme toggle gets a richer palette. Fold the actual toggle UX improvements in.
- **Fold-in candidate:** `prefers-reduced-motion` audit. The 4 `!important` lines at `app.css:1446–1449` honor reduced motion. If we adopt OP `--ease-*` and `--animation-*` tokens, the `prefers-reduced-motion` overrides can move onto OP's pattern. Same PR as ADR-007-4.

**Do NOT fold in (stays separate):**

- #58 wave 3 frontend modularization — different layer (JS, not CSS). Already in flight in v3.34.0.
- #61 data-heavy (pagination/virtualization) — performance work, unrelated to tokens.
- #62/#63 polish + cleanup — too broad; pulling either into v3.35.0/v3.36.0 would overflow scope.

## References

- `docs/internal/roadmap.md` § 6 (refactor stream) + § 7 (UX overhaul stream)
- `docs/internal/architecture-decisions/004-lib-php-size-module-shape.md` — precedent for module-extraction ADR style
- `Simple-PHP-IPAM/assets/app.css` — `:root` block lines 1–110
- GH issue #941 — original (now misframed) CSS reconcile issue
- GH milestone #59 (UX foundation), #60 (UX nav)
- Open Props: https://open-props.style — Adam Argyle, MIT licensed
- Open Props GitHub: https://github.com/argyleink/open-props
