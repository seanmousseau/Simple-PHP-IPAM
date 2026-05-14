# Design guide

> **Audience:** developer or agent touching UI — HTML, CSS, JS in `Simple-PHP-IPAM/assets/` or page handlers that render markup. Project-specific UI conventions only; general accessibility/UX principles aren't restated.

---

## Overall shape

Server-rendered PHP. Every page is one handler that calls `page_header($title)`, emits HTML, calls `page_footer()`. No SPA, no client routing, no hydration.

Vanilla CSS in `assets/app.css` (~3000 lines, CSS custom properties for theming). Vanilla JS in `assets/app.js` (sidebar interactions, command palette, theme toggle, progressive enhancement). No bundlers, no transpilation, no source maps.

---

## Theme system

Theme is controlled by `html[data-theme]` with three values: `auto` (default), `light`, `dark`. `auto` defers to `prefers-color-scheme`. The setting is persisted per user in `users.theme` and re-applied on login via `login_user()`.

CSS uses custom properties exclusively for theme-able values. The full palette:

| Variable | Light | Dark | Use |
|---|---|---|---|
| `--bg` | page background | | Outermost surface |
| `--fg` | foreground text | | Default text |
| `--muted` | secondary text | | Helper text, timestamps |
| `--border` | divider lines | | All borders |
| `--card` | elevated surface | | Cards, modals, sidebar |
| `--danger` | red | | Destructive actions, errors |
| `--success` | green | | Success states, confirmations |
| `--warn` | amber | | Warning callouts |
| `--link` | accent | | Links, primary actions |
| `--btn` / `--btnfg` | button bg / fg | | Default button |
| `--badge-bg` | badge fill | | Badges |

**Never hardcode a colour.** Use a variable. If the colour you need doesn't exist, add a variable; don't bypass.

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

Keyboard-driven navigation, record creation, theme toggle. Accessible from any page. Implementation in `assets/app.js`; items are populated from `lib.php` `ipam_command_palette_items()` plus per-page additions echoed into a `<script type="application/json">` block.

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

### Status indicators

| Class | Use |
|---|---|
| `.status-used`, `.status-reserved`, `.status-free` | Address status pill |
| `.badge` | Generic small label |
| `.badge-update` | "Update available" indicator in footer |
| `.muted`, `.danger`, `.success`, `.warning` | Inline text colour utilities |

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
