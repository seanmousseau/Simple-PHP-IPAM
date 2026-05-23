# Design guide

> **Audience:** developer or agent touching UI — HTML, CSS, JS in `Simple-PHP-IPAM/assets/` or page handlers that render markup. Project-specific UI conventions only; general accessibility/UX principles aren't restated.

---

## Overall shape

Server-rendered PHP. Every page is one handler that calls `page_header($title)`, emits HTML, calls `page_footer()`. No SPA, no client routing, no hydration.

Vanilla CSS in `assets/app.css` (~3000 lines, CSS custom properties for theming). Vanilla JS in `Simple-PHP-IPAM/assets/modules/*.js` — 46 per-concern modules (sidebar interactions, command palette, theme toggle, progressive enhancement, etc.), each loaded as its own `<script defer>` by `page_header()`. Load order is encoded in the numeric/letter filename prefix (`00-bootstrap.js` → `c5-sudo-replay-resume.js`), so the deferred-script execution order matches the documented dependency chain (e.g. `20-drawer.js` runs before `80-command-palette.js`, which consumes `window.IpamDrawer`). No bundlers, no transpilation, no source maps. The v3.34.0 split of the former monolithic `assets/app.js` is locked by `testing/playwright/tests/frontend-modules.spec.ts` (#939 + #1047).

---

## Design tokens

Per **ADR-007** (`docs/internal/architecture-decisions/007-open-props-adoption.md`), `assets/app.css` consumes [Open Props](https://open-props.style) as the underlying token base, layered with IPAM-specific brand values where the OP scale doesn't fit a compact admin UI.

**Token surface (`:root` in `app.css`):**

| Layer | Tokens | Source |
|---|---|---|
| Color (light + dark) | `--bg`, `--fg`, `--muted`, `--border`, `--border-strong`, `--card`, `--card-2`, `--link`, `--danger`, `--success`, `--warn`, `--btn`, `--btnfg`, `--btn-secondary`, `--btn-secondary-fg`, `--brand`, `--badge-bg`, `--input-bg`, `--nav-bg`, `--shadow` (composed from `--shadow-near` + `--shadow-far`) | **Brand-defined.** Each token uses CSS `light-dark(<light>, <dark>)`; the chosen scheme is pinned by `html[data-theme="light|dark"] { color-scheme: ... }`. Closest OP gray noted in comment for reference; IPAM keeps its cool-toned neutrals as brand identity. |
| Spacing | `--space-1` … `--space-13` | **6 alias OP** `--size-{1,2,3,4,5,8}` where values match exactly (4 / 8 / 16 / 20 / 24 / 48 px). The remaining 7 (2 / 6 / 10 / 12 / 14 / 40 / 60 px) have no OP equivalent and stay as px literals. |
| Radius | `--radius-xs` … `--radius-4xl`, `--radius-pill` | **2 alias OP** `--radius-3` and `--radius-round` (16 px and full pill). The other 7 have no OP equivalent. |
| Typography (6-step) | `--text-xs` (12) · `--text-sm` (14) · `--text-base` (16) · `--text-lg` (20) · `--text-xl` (24) · `--text-2xl` (32) | **5 alias OP** `--font-size-{0,1,3,4,5}`; the 14px `--text-sm` (body default) stays as a brand-defined intermediate. **The legacy `--font-size-{2xs..8xl}` 14-step scale and the prior 7-step `--text-*` scale are kept as deprecation aliases** pointing at one of the six canonical sizes, so existing var() callers still resolve. New rules should use the 6-step scale directly. |
| Z-index | `--z-sticky`, `--z-topbar`, `--z-overlay`, `--z-drawer`, `--z-nav-overlay`, `--z-nav-drawer`, `--z-dropdown`, `--z-col-vis`, `--z-spinner`, `--z-toast`, `--z-page-overlay` | **Brand registry.** Each layer has exactly one z value; do not introduce ad-hoc z-indices. |
| Misc | `--focus-ring`, `--font-sans`, `--font-mono` | Brand-defined. |

**Theme controls.** `html[data-theme]` takes three values: `auto` (default — defers to `prefers-color-scheme`), `light`, `dark`. The setting is persisted per user in `users.theme` and re-applied on login via `login_user()`. With ADR-007-3 in place, `light` and `dark` toggles set `color-scheme` on the `html` element; `auto` leaves it as `light dark` so `light-dark()` resolves against the OS preference.

**Adding a new token.**

- Color: add a `light-dark()` declaration on `:root`; do not add new `[data-theme=dark]` override blocks.
- Spacing / radius: if the value exists in Open Props, alias it (`var(--size-N)` or `var(--radius-N)`); otherwise add a px literal with a `/* no OP match */` comment.
- Never hardcode a colour in a rule. Use a token. If the token you need doesn't exist, add it; don't bypass.
- Typography: use one of the six `--text-*` sizes. Do not introduce a 7th step. Do not use raw `rem`/`px` font-sizes in new CSS — that recreates the drift #953 collapsed.

**Browser support.** `light-dark()` requires Chrome 123+ (Mar 2024) / Safari 17.5 (May 2024) / Firefox 120 (Nov 2023). The project assumes modern evergreen browsers (already used: `:has()`, `:focus-visible`, `:where()`).

---

## Nav structure (v3.8.0 sidebar)

Two breakpoints, one component:

- **Desktop (≥1024px)** — always-visible left sidebar. Logo + nav items (Dashboard, Subnets, Addresses, Search, Audit, Admin section) + user block (username + role badge → Theme, Account, Logout).
- **Mobile (<1024px)** — hamburger button in top bar opens the same sidebar as a full-height overlay. Escape key or backdrop click closes.

**Admin section nav items** (gated by `require_role('admin')`): Sites, VLANs, VRFs, Tags, Contacts, Custom Fields, Users, DHCP Pools, API Keys, Webhooks, Import CSV, Database Tools (hidden when engine != SQLite), Backups, Health.

The full nav-component spec lives in `docs/sidebar-and-command-palette.md` (public-facing). Don't restate it here; cross-link from new pages.

---

## Icons

Single SVG sprite at `Simple-PHP-IPAM/assets/icons.svg` using Heroicons. Reference an icon with:

```html
<svg class="icon" aria-hidden="true"><use href="assets/icons.svg#icon-name"></use></svg>
```

Never inline an SVG in a page. Never reach for an emoji. The emoji-icon → Heroicon migration in v3.8.0 was deliberate — emojis render inconsistently across platforms and screen readers.

When adding a new icon, prefer an existing Heroicon over a custom path. If you must add one, keep it monochrome, 24×24 viewBox, and matching stroke width to the rest of the sprite.

---

## Command palette (⌘K / Ctrl+K)

Keyboard-driven navigation, record creation, theme toggle. Accessible from any page. Implementation in `assets/modules/80-command-palette.js`; items are populated from `lib.php` `ipam_command_palette_items()` plus per-page additions echoed into a `<script type="application/json">` block.

When adding a new top-level page or admin action, add a palette entry. Without one the action is keyboard-inaccessible from anywhere except its own nav link.

---

## Component patterns

### Buttons

| Class | Use |
|---|---|
| (default) | Standard button (`--btn` / `--btnfg`) |
| `.button-secondary` | Lower-emphasis action |
| `.button-danger` | Destructive (delete, disable, reset) — always with confirmation |
| `.action-pill` | Inline row action (small, pill-shaped) |

Destructive actions render a `<button class="button-danger">` with a `data-confirm="…"` attribute. JS handler intercepts the click, shows a confirm dialog, and submits the form on confirmation. **Sudo-class destructive actions** (vault reveal, DB import, API key creation, MFA disable) also pass through `ipam_sudo_verify()` server-side — see `auth-model.md` → Step-up.

### Cards

`.card` is the default container with `--card` background, 1px `--border`, rounded corners, modest padding. Stack cards in `.row` for grid-like layouts.

Card titles use `<h2>` for the page-level card and `<h3>` for cards inside that. Don't skip heading levels.

### Badges, pills, and status utilities

Three **functionally distinct** primitives exist for small inline indicators. They look superficially similar but serve different purposes — don't fold them into one class. This is the documented taxonomy; new code follows it.

| Primitive | Role | Interactive | Has visual chrome (box/border/bg)? | Examples |
|---|---|---|---|---|
| **`.badge`** + `.badge-*` variants | Non-interactive **label** | No | Yes — bordered pill with `--badge-bg` background | Role badges (`.badge-role-admin`), device types (`.badge-type-router`), update indicator (`.badge-update`), tag pills |
| **`.nav-pill`** / **`.action-pill`** | Interactive **button**, pill-shaped | Yes | Yes — same shape as `.badge` but with hover state | Top nav items, inline row actions ("Add Subnet", "Bulk Update", "Export CSV") |
| **`.status-{used,reserved,free}`** / **`.status-badge`** | Color **utility** for inline status — text-only | Sometimes (`.status-badge[data-addr-id]` is clickable) | **No** — just `color` + `font-weight: 600` | Dashboard KPI counts, addresses.php status cell |

#### When to use which

- **A non-interactive label** that names a category, role, type, or version → `.badge` (+ tinted variant).
- **A clickable button** that triggers navigation or an action → `.nav-pill` (top nav) or `.action-pill` (row/page actions). Not a badge.
- **Coloring text/numbers** by status without adding a box → `.status-{used,reserved,free}`. Used for counts on the dashboard and the click-to-cycle status cell on addresses.php.
- **A new color-coded label primitive** that doesn't fit any of the three above → first ask whether the existing variants (`.badge-success/.badge-muted/.badge-broadcast/.badge-gateway/...`) cover it. If not, add a new `.badge-*` variant; don't introduce a 4th primitive.

#### Variant tinting convention

Every `.badge-*` color variant uses the same recipe: `color-mix(in srgb, var(--accent) 12-15%, var(--badge-bg) 85-88%)` for background, full `var(--accent)` for foreground, and a 35-40% mix for the border. This keeps every tinted badge legible in both themes (the `--badge-bg` and `--border` tokens flip on dark mode automatically via `light-dark()`).

#### Footgun

`.status-badge[data-addr-id]:hover` currently sets `text-decoration: underline` to advertise clickability. That is *intentional* — don't strip it; it's the only at-rest affordance for the click-to-cycle interaction. Pattern is similar to but **opposite** of the sidebar brand link (#958), which deliberately suppresses the underline.

#### Out of scope (do not add)

- A unified `.badge--{status,tag,filter,action}` BEM hierarchy — buttons and color utilities don't belong under the badge umbrella; forcing them in either strips button interactivity or adds chrome to colored numbers. The current 3-primitive split is the right shape.

### Utilisation bars (subnet allocation gauges)

```html
<div class="util-bar">
  <div class="util-bar-fill" style="width: 67%"></div>
</div>
```

Add `util-bar-fill--warn` or `util-bar-fill--crit` at the policy thresholds (defaults: warn 80%, crit 95% — configurable per setting). The threshold logic is in `lib.php`; the visual stays in CSS.

---

## Forms

Every browser POST form has:

```html
<form method="POST">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="create_subnet">
  …
  <button type="submit">Save</button>
</form>
```

The hidden `action` field is matched in the handler via `$_POST['action']` — multiple handlers per page dispatch on it. CSRF is invariant #9, no exceptions.

Field validation surfaces server-side errors via a flash mechanism (`$_SESSION['flash']`) that the next page render consumes and displays via `<div class="flash flash-error">`. Client-side validation is layered on top via `required` / `pattern` attributes and `aria-describedby` for error hints — but never replaces server-side validation.

---

## Accessibility

The codebase is largely WCAG 2.1 AA compliant; do not regress it. Concrete rules:

- Every interactive element either has a visible label or `aria-label`.
- Every form input has an associated `<label>`.
- Focus order matches visual order; skip-to-content link at the top of every page.
- Tap targets ≥ 44px on mobile (the sidebar overlay, command palette, and primary buttons all already comply).
- Don't disable focus outlines; theme them via `:focus-visible` instead.
- Use Heroicons + visible text together for nav items — icon-only nav fails screen readers.
- **Color is never the sole signal** (WCAG 1.4.1). Every color-coded status must pair with a non-color counterpart — text, icon, bar width, percentage number, or `<span class="sr-only">` for screen-reader announcement. Examples in-tree (closed by #956):
    - **`.util-bar-fill--warn` / `--crit`** — wrap the `.util-bar` (or `.util-bar-block`) in `role="meter"` with `aria-label` describing the level and `aria-valuenow/min/max`. Add `<span class="sr-only">{Warning|Critical} level</span>` adjacent to the percent number when above threshold.
    - **`health_dot()` in `health.php`** — already emits `aria-label` on the dot; also emits a sibling `<span class="sr-only">{OK|Warning|Critical}</span>` so the level is announced in plain text.
    - **`.status-used/reserved/free`** — the surrounding label (`<div class="label">Used</div>`) or column header is the non-color signal; no additional sr-only text needed.
    - **`.status-badge.status-*`** — the visible text (`used`/`reserved`/`free`) is itself the non-color signal.
- The `.sr-only` utility class in `app.css` is the standard "visually hidden, accessible to AT" helper. Use it for any screen-reader-only text that pairs with a visual signal.

---

## Asset cache-busting

Automatic. `page_header()` and `demo_gate.php` derive `?v=` from `IPAM_VERSION` plus the asset's `filemtime()` for `app.css` / `app.js`, so an in-version edit busts the cache without a version bump. This is design-document invariant #16. **Do not** reintroduce hardcoded `?v=X.Y.Z` literals.

---

## Adding a new page

Procedure is in `adding-a-page.md`. Visual checklist for the page itself:

- Calls `page_header($title)` before any output.
- Renders inside `<main class="container">` (or `.container-wide` for tables that need every pixel).
- One `<h1>` per page, matching the `$title`.
- Cards in `.row` for grid layout; `.stack` for vertical layout with gap.
- Adds a command palette entry if it's a top-level destination or a frequent action.
- Adds a Playwright happy-path spec (auth, render, smoke action).

---

## Cross-references

- `docs/sidebar-and-command-palette.md` — public-facing nav + palette spec.
- `adding-a-page.md` — procedural recipe for creating a new page.
- `coding-guide.md` — `e()` rule, CSRF rule, validation patterns.
- `auth-model.md` → Step-up — the step-up prompt partial (`views/_step_up_prompt.php`) and its rendering shapes.
- `security-model.md` — what self-protection guards look like in the UI (hidden affordances on own account).

---

## Update protocol

- New UI pattern adopted in more than one place → document it here as a reusable component.
- Theme variable added → update the palette table.
- Accessibility rule that was implicit and broke once → make it explicit here.
- Public-facing UI docs (e.g. `docs/sidebar-and-command-palette.md`) remain the source of truth for their topic. Cross-link them; don't restate.
