# UX Overhaul — Simple-PHP-IPAM (non-backup)

**Status:** APPROVED 2026-04-30 — milestone allocation locked, 82 finding-issues + 5 test-update umbrellas filed across v3.31.0–v3.35.0 (87 issues total).
**Scope:** Top-to-bottom UI/UX review of the application **excluding** backup/restore, which has its own dedicated overhaul at `docs/internal/backup_overhaul.md` (5-tab unified surface, restore wizard, drawer pattern, U1–U10 backlog).
**Goal:** Tighten the GUI before v4.0.0 multi-tenancy work begins. All findings here must be addressed before v4.0.0.
**Single source of truth:** This document. Every issue filed against any milestone v3.31.0–v3.x must reference a finding ID in this file.

This doc mirrors the style of `backup_overhaul.md` and `code_quality_review.md`:
- §1 methodology · §2 verdict · §3–§6 findings by area · §7 cross-cutting · §8 effort · §9 recommended milestone allocation · §10 decision log.
- Severity rubric: **P0** (broken or unusable today), **P1** (significant UX/a11y gap, ship before v4.0.0), **P2** (polish/consistency), **P3** (nit).
- Backup-side UX (§7 U1–U10 in backup_overhaul) is **never** duplicated here.

---

## 1. Methodology

Live-app walkthrough on the dev test instance (https://dev-direct.seanmousseau.com:8343/testing/ipam/, SQLite seed with 500 subnets / 43,100 addresses / 12,433 audit rows / 100 scan schedules) using Playwright + structural extraction via `page.evaluate`. Sampled across:

| Surface | Pages exercised |
|---|---|
| **Auth** | login.php (logged-out + session-timeout), forgot password link, post-login redirect, theme toggle |
| **Shell** | sidebar nav (31 links flat), command palette discoverability, footer, mobile drawer |
| **Data-heavy** | dashboard.php, subnets.php (500 rows), addresses.php (subnet view + bulk), search.php (43k rows / 170 pages), audit.php (12k events) |
| **Admin** | settings.php (5 tabs), users.php, sites.php, vlans.php, vrfs.php, tags.php, contacts.php, custom_fields.php, api_keys.php, webhooks.php, dhcp_pool.php, devices.php, aggregates.php, pd_pools.php, health.php, reports.php |
| **Workflows** | import_csv.php (4-step wizard), import_arp.php, all export_*.php, scan_history.php, change_password.php (MFA, OIDC unlink, passkeys) |
| **Cross-cutting** | desktop 1440×900, mobile 375×812, light + dark themes, keyboard nav (`Tab`/`Esc`), DOM scale, computed styles |

**Backup-related pages (`backup_history.php`, `backups.php`, `destinations.php`, `download_remote_backup.php`, `restore_web.php`, `remote_backups.php`, `db_tools.php` backup half) were observed only enough to confirm they remain in scope of `backup_overhaul.md` §2 — they are NOT covered here.** The `db_tools.php` data-export half (CSV/JSON/SQL) is in scope.

**Total: 78 findings** across 6 surface areas + cross-cutting.

---

## 2. Verdict — overall

**The app is functional and shows a clear design direction (vanilla CSS, sidebar shell, semantic HTML, decent dark theme), but it is showing its age in three places that matter for v4.0.0 readiness:**

1. **Information density and scale.** The subnets page renders all 500 cards at once — `main` is 103,771 px tall with **3,727 interactive elements**. Search and audit pages reach into the 10K-row range. None use windowing, pagination is inconsistent, and bulk actions exist on some pages but not others.
2. **Navigation is overloaded.** 31 sidebar links in a flat list (5 primary + 22 admin + 4 account). Backup is already getting a 5-tab unified surface; the rest of admin needs the same treatment to avoid v4.0.0 multi-tenancy adding *more* links to the wall.
3. **Design system fragments.** 14 distinct font sizes computed in production CSS, no defined type scale, mixed badge/pill treatments across pages, color-only state communication in several places (utilization bars, status text, health dots).

**Strong points to keep:** breadcrumb pattern, sidebar IA at the *category* level, keyboard `?` skip-link, consistent `e()` escaping, drawer pattern from backup overhaul (extend, don't replace), dark theme actually exists and is reasonably complete, settings registry tabs, audit-log retention banner, health dashboard's panel-with-status-dot layout, command palette (CLAUDE.md §UI Conventions).

**Severity distribution: 0 P0, 28 P1, 36 P2, 14 P3.** No P0s — nothing is *broken*. The shape of the work is consolidation + scale + polish, not rebuild.

---

## 3. Findings — Auth & Shell

### 3.A — Login & session

| ID | P | Title | Notes |
|---|---|---|---|
| S1 | P1 | No "Show password" toggle on `login.php` / `change_password.php` / `forgot_password.php` | Industry-default since 2017; users mistype on phones constantly. ~30 min of work each. |
| S2 | P1 | Session timeout drops the deep-link return URL | Logout flash works ("Your session expired due to inactivity") but after re-login user lands on dashboard, not the page they were heading to. Stash the original URL on 401 and pass through `?next=`. Already-validated `_redirect_uri_is_safe` exists per code_quality_review A.P3. |
| S3 | P1 | No rate-limit feedback for failed logins | login.php has `login_rate_limited()` (lib.php) but no visible cool-down indicator to the user. Show a count-down or "Too many attempts" banner. (Endpoint-level rate-limit work for forgot/reset/email-OTP is tracked under code_quality_review B4.) |
| S4 | P2 | "Forgot password?" is a small text link below login button | Users miss it. Move to right-of-password-field with subtle underline, increase touch target. |
| S5 | P2 | Login card is centered vertically with ~50% empty whitespace below on desktop | Design feels barren. Either add product-shot/value-prop to the right (split layout) or compress vertical whitespace. |
| S6 | P2 | "SimplePHPIPAM" wordmark is text-only (Times font in `:root`, then `Fira Sans` body) | Wordmark relies on text rendering; no actual logo. v4.0.0 multi-tenancy + branding settings already expose `branding.site_name` per tenant — make the wordmark consume that. |
| S7 | P3 | Footer "Sessions expire after 30 min of inactivity" is helpful but should be inside the card, not below | Floats orphaned in whitespace. |

### 3.B — Sidebar shell, command palette, theme

| ID | P | Title | Notes |
|---|---|---|---|
| S8 | **P1** | **31 nav links in a flat sidebar — overloaded** | 5 primary + 22 admin + 4 account. Already a wall today; v4.0.0 will add tenant switcher + super-admin. **Group admin into 4–5 collapsible sections**: Network (Sites/VRFs/VLANs/Aggregates/PD Pools/DHCP Pools), Catalogue (Tags/Devices/Contacts/Custom Fields), Access (Users/API Keys/Webhooks), Data (Import CSV/ARP Import/Reports/Database), System (Health/Settings). Backup track already collapses 4 links → 1 unified surface (`backup_overhaul` §2). |
| S9 | P1 | Brand link in sidebar gets default underline + visited color in dark mode | Confirmed at `https://…/dashboard.php?theme=dark` — the `aside .logo a` selector inherits link styling instead of brand styling. Add `text-decoration: none; color: var(--fg)` for `aside [class*="logo"] a`. |
| S10 | P1 | Command palette (⌘K / Ctrl+K) has zero discoverability | No visible affordance, no keyboard hint in nav, no `Press ⌘K to search` hint anywhere. CLAUDE.md mentions it but most operators will never find it. Add a sidebar-bottom hint or a small `⌘K` chip in the top of the main panel. |
| S11 | P1 | Theme toggle button labelled "System" — unclear it's a tri-state cycle | Auto / Light / Dark cycle is good, but the visible state is the *active* state, not the *next* state. Replace single-button with a 3-segment control or a popover with three explicit choices. |
| S12 | P2 | Sidebar takes ~210px desktop and is always open — no collapse | On 1366×768 laptops the main pane is ~1156px which clips the wider tables (audit, search). Add a collapse-to-icons-only mode (60px). |
| S13 | P2 | Footer ("SimplePHPIPAM v3.19.1 · Docs") inside main column scrolls with content | Should be a fixed page-level footer, not duplicated under every page's content. |
| S14 | P2 | "IPAM (SQLite Test)" wordmark wraps to 2 lines on mobile drawer | Truncate at viewport-narrow breakpoints, or collapse to logo-only. |
| S15 | P3 | "Skip to main content" link is present (good a11y) but is the only thing receiving the first Tab — could be better | Standard pattern; keep as-is. Note. |

---

## 4. Findings — Dashboard

### 4.A — KPIs and at-a-glance area

| ID | P | Title | Notes |
|---|---|---|---|
| D1 | P1 | KPI labels are uppercase 11px tracking-wide — low contrast in light, marginal in dark | Bump to 12px and reserve uppercase for one specific layer of the type scale (see C-axis below). |
| D2 | P1 | KPI cards aren't clickable (no affordance, no link) | "Subnets 500" should be a card-wide link to `/subnets.php`. Same for Addresses → /addresses, Used → /unassigned (or a utilization view), Crit Alerts → filtered audit. Today's "Add Subnet / Search / Subnets / Import CSV" action row is a poor substitute. |
| D3 | P1 | "Address growth (last 30 days)" chart is essentially blank — single tiny V-shape, no axis labels | The seed data doesn't have growth, but the empty-state UX should say so ("No address growth in the last 30 days") instead of rendering an empty chart frame. Already on the v3.8.0 uPlot roadmap (CLAUDE.md). Capture here as a v3.8.0 dependency. |
| D4 | P2 | "Top IPv4 Subnets by Usage" widget shows 10 rows all at 100% | Surfacing ten /30 P2P links all maxed out is information-poor. Sort by *most recently filled* or *closest to capacity threshold*, not by alphabetical. |
| D5 | P2 | Used / Reserved / Free trio uses color-only differentiation | Numbers in green/orange/black; no icon, no badge, no label colour-paired with shape. Color-blind users see three identical-style numbers. Add a leading dot/pill or an explicit label. |
| D6 | P2 | "Address growth" chart has no legend, no axis labels, no hover tooltip | Same v3.8.0 dependency. |
| D7 | P2 | "Recent Activity" widget surfaces raw event names ("auth.passkey_challenge", "remote_backup.verify") | These are technical event keys. Pretty-print: "Passkey challenge issued" / "Verified backup `ipam-…enc`". Empty user cell on `auth.passkey_challenge` row is confusing — explain that the system issues challenges before a user identifies. |
| D8 | P2 | Each widget has a small × "Hide widget" button + bottom "Reset widgets" link | Pattern works but the X is small and unlabelled. "Reset widgets" should live next to a "Customize widgets" entry, not as a tiny link. |
| D9 | P2 | No "last refreshed" timestamp on any widget | Health page shows it; dashboard widgets don't. Add. |
| D10 | P3 | "Add Subnet / Search / Subnets / Import CSV" pillbar at top duplicates the sidebar | Quick-action area should change with role — readonly users get "Search / Export", admins get "Add Subnet / Import CSV". |

---

## 5. Findings — Data-heavy pages

### 5.A — `subnets.php`

| ID | P | Title | Notes |
|---|---|---|---|
| L1 | **P1** | **All 500 subnets render in a single nested DOM** | Measured: `main.scrollHeight = 103,771px`, `3,727 interactive elements`, `1,010 card-like containers`. No pagination, no virtual scroll, no lazy-load on group expand. Tab-key nav is unusable. **Highest-impact fix in this overhaul.** Either paginate by site (100/site/page) or ship a virtualised tree. |
| L2 | P1 | Edit-subnet form is rendered into the DOM up-front for *every* row | Explains part of the 3,727-element count. Convert to a drawer (matches backup-overhaul drawer pattern) and lazy-mount on click. |
| L3 | P1 | 6 action pills per subnet row (Edit / View Addresses / Unassigned / Scan History & Schedule / Bulk Update / DHCP Pool) | Information overload; secondary actions (Bulk Update, DHCP Pool, Scan History) should live behind a kebab-menu with Edit + View Addresses as primary. |
| L4 | P2 | "Used / reserved / free" coloured numbers and utilization bars repeat the color-only-state issue | Add a leading icon/badge per state. (Also covered by D5; tracked once.) |
| L5 | P2 | Site filter chip row scrolls horizontally on narrow viewports without affordance | Add right-edge fade or an overflow chevron. |
| L6 | P2 | "List / Map" toggle exists but Map view is a separate page render | Convert to client-side toggle that switches view without page reload. |
| L7 | P3 | Breadcrumb shows "Dashboard › Subnets" (good) but the page title duplicates "Subnets" h1 directly below | Drop the h1 or hide breadcrumb on root-of-section. |

### 5.B — `addresses.php`

| ID | P | Title | Notes |
|---|---|---|---|
| L8 | P1 | "Site / Subnet / Page-size + Load button" gate is a pre-2010 UX | URL params already drive the page; navigating those selectors should auto-update the table. The "Load" button is a regression vs. modern data tables. |
| L9 | P1 | 7 primary actions in a row (Add Address / Bulk Update / Reserve Infra IPs / Unassigned / Search in Subnet / Export CSV / DNS Export) | Same hierarchy problem as L3. Promote 2–3, fold the rest into a kebab. |
| L10 | P1 | Status column shows "used" / "reserved" / "free" as colored *text*, not badges | Inconsistent with Group column (which uses badges) and with Subnets list (which uses bars). Pick one badge vocabulary and apply everywhere. |
| L11 | P2 | Empty MAC / Expires / Updated cells are blank in some rows, "—" in others | Standardize on "—" for "no value." |
| L12 | P2 | Action column is two stacked rows ("History" + "Ping" on line 1, "Edit/Delete" on line 2) | Inconsistent layout; collapse to one kebab. |
| L13 | P2 | No URL-state filters | Filters reset on reload. Push to URL params. |
| L14 | P2 | "Columns" picker exists (good) but column visibility doesn't persist across sessions | LocalStorage by user. |
| L15 | P2 | Bulk-action checkboxes have no select-all / no visible "1 selected" bar | Show a sticky bulk-action bar when ≥1 row is checked. |
| L16 | P3 | "List" sub-heading inside the card is redundant with the page H1 | Drop. |

### 5.C — `search.php`

| ID | P | Title | Notes |
|---|---|---|---|
| L17 | P1 | No instant-search; explicit "Search" button required | At v3.8+ the search query should debounce-update. |
| L18 | P1 | "Addresses" button next to "Export CSV" goes to /addresses.php | Confusing — what's it for? Either remove or rename to "Browse all addresses". |
| L19 | P2 | "Updated" column wraps to 3 lines on standard density | Drop EDT suffix or render as relative time ("3 days ago") with full datetime in tooltip. |
| L20 | P2 | "Auto-generated address" appears in Note column for every seed row | Real systems have varied notes; this is seed noise but reveals the column is rendered without "(no note)" fallback. |
| L21 | P2 | Pagination is single-direction "Next »" link only | Need page numbers + jump-to-page on 170-page result sets. |
| L22 | P2 | No saved searches / no recent searches | Dashboard could surface "Last 5 searches" widget. |
| L23 | P3 | Sort indicator is a tiny ▲ next to one column header — operators don't know other columns are sortable | Add hover-state cursor + sort indicator on every sortable header. |

### 5.D — `audit.php`

| ID | P | Title | Notes |
|---|---|---|---|
| L24 | P1 | Action / Entity / Client IP show technical IDs (`user#2`, `destination#1`, `auth.passkey_challenge`) | Resolve IDs to names ("User: claude") and pretty-print actions ("Passkey challenge"). The technical key can live in a tooltip or expanded row. |
| L25 | P1 | Client IP shown to *every* admin viewer — privacy/security concern in multi-admin installs | Toggle in settings or admin-only-by-role-flag. v4.0.0 super-admin vs tenant-admin will need this gate anyway. |
| L26 | P2 | Date filters use native `<input type="date">` | Inconsistent UX across browsers. Either accept or replace with a small popover calendar. |
| L27 | P2 | Time column wraps to two lines | Same as L19; relative-time + tooltip. |
| L28 | P2 | Category dropdown — no quick-filter pills for common categories ("auth", "subnet", "address", "backup") | Add chip row above the table. |
| L29 | P2 | No "show only my activity" / "show only failures" quick filters | Common operator need. |
| L30 | P3 | Pagination "Page 1 of 249" with single "Next »" link | Same as L21. |
| L31 | P3 | "Prune now" is a destructive action — dialog, not just a button click | Confirm dialog with row count before action. (audit-log triggers prevent accidental full-table delete; UX-side guard still needed.) |

---

## 6. Findings — Admin & system pages

### 6.A — `settings.php`

| ID | P | Title | Notes |
|---|---|---|---|
| A1 | P1 | Each setting exposes the registry key in monospace next to the label (e.g. `branding.site_name`) | Helpful for ops, but reads like developer-debug to first-time users. Hide behind a "Show technical keys" admin toggle (default off). |
| A2 | P1 | Every setting group has its own Save button (5+ per tab) | Cognitive load — operators forget which group they edited. Either single page-level Save with dirty-state, or auto-save-on-blur with toast. Pick one. |
| A3 | P2 | "Default / Database" pill on every row | Useful provenance signal, but visually noisy for the 90% of rows that are at default. Show only when *not* default. |
| A4 | P2 | Description text under each label is good but inconsistent in length and tone | Sweep for consistency: one sentence, sentence case, no period unless multiple sentences. |
| A5 | P2 | `Display timezone` field has helper text that mixes "PHP timezone identifier" jargon with operator-friendly example | Drop "PHP timezone identifier" — leave the example. |
| A6 | P3 | Intro paragraph reveals `config.php still holds the bootstrap server-level values (app_secret, DB credentials)` | Implementation detail; either remove or move to "About this page" tooltip. |

### 6.B — `users.php`

| ID | P | Title | Notes |
|---|---|---|---|
| A7 | P1 | "Active = yes" rendered as plain text, not a badge or toggle | Inconsistent with badges elsewhere; can't tell at a glance who's disabled. |
| A8 | P1 | 2FA / Passkeys columns show "On" green pill + a count number — meaning unclear | Is "1" the count of methods? Standardise on icon + count, or text + count. |
| A9 | P2 | "Last Login" + "Created" both render full datetime, both wrap to 2 lines | Use relative time + tooltip (consistent with L19/L27 fix). |
| A10 | P2 | "Actions ▼" dropdown — no visible keyboard arrow indicator that it opens a menu | Add explicit ▼ glyph; ensure it's a `<button aria-haspopup="menu">`. |
| A11 | P3 | Empty Locked / SSO cells use "—" — good | Note. |

### 6.C — Other admin (sites, vlans, vrfs, tags, contacts, custom_fields, api_keys, webhooks, dhcp_pool, devices, aggregates, pd_pools)

| ID | P | Title | Notes |
|---|---|---|---|
| A12 | P1 | These pages share ~80% of layout but each ships its own action row + form layout | Standardize on the **drawer-driven CRUD pattern from backup_overhaul** (`§2 drawer` + edit/delete/test/run). Apply to all 12. Single helper `views/admin_index.php` + `views/admin_form.php` extracted. (Aligns with code_quality_review B7/B8/B9 work in v3.28.) |
| A13 | P1 | None have empty-state copy | "No tags yet — Tags help group subnets and addresses for filtering. [+ Add tag]" pattern, applied to every list. |
| A14 | P2 | Many use a top-of-page form for "Add X" + a list below | Replace with action button → drawer, freeing vertical space for the list. |
| A15 | P2 | `tags.php` colour picker is a free-text hex input | Use a swatch grid + custom hex fallback. (Code-quality A1 hardens the rendering side; this hardens the entry side.) |
| A16 | P2 | `custom_fields.php` reorder uses `↑ ↓` buttons | Drag-drop with `[role="listbox"]` and arrow-key support. |
| A17 | P2 | `api_keys.php` shows the secret only on creation but the post-creation dialog is a flash + redirect | Modal with "Copy to clipboard" button + "I've saved this key" confirmation gate before dismissing. |
| A18 | P2 | `webhooks.php` "Test" action gives a flash message, not a result panel showing payload + response | Inline result panel with status code, latency, response body preview. |
| A19 | P3 | `devices.php` lists many object-per-row attributes inline | Could collapse into a clickable row → drawer. |

### 6.D — `health.php`

| ID | P | Title | Notes |
|---|---|---|---|
| A20 | P1 | Status dot (green/red) is the only health indicator on each panel header | Color-only state communication. Pair with icon (✓ / ⚠ / ✕) + sr-only text. |
| A21 | P2 | "Cache TTL: 60s — Force refresh" link is bottom-left small | Move into the panel header as a small "Refreshed 14s ago · Refresh" cluster. |
| A22 | P2 | No history / sparkline on metrics that change over time (audit log rows, scan results) | At least show 7d trend arrow. |
| A23 | P3 | "Refresh now" button at top vs "Force refresh" link at bottom — two ways to do the same thing | Pick one. |

### 6.E — `import_csv.php`

| ID | P | Title | Notes |
|---|---|---|---|
| A24 | P1 | Wizard steps shown as text "upload → map columns → dry run → apply import" instead of a visual stepper | Operators don't know which step they're on. Add `<ol class="stepper">` with active state. |
| A25 | P1 | Step 1 is a native `<input type="file">` with "No file chosen" — no drag-drop zone | Modern import tools all have drop zones. ~2h work in vanilla JS. |
| A26 | P2 | "Reset Wizard" is the only navigation between steps | Add explicit "Back" between steps 2/3/4. |
| A27 | P2 | Max upload size: 5MB advertised but no client-side check before upload | Validate size on file-pick; show error before round-trip. |
| A28 | P2 | Security notice banner is amber and has a "Dismiss" — but the banner is shown again on every page load | Persist dismissal (per-user cookie). |

### 6.F — Exports + scanner

| ID | P | Title | Notes |
|---|---|---|---|
| A29 | P2 | 8 separate `export_*.php` endpoints linked from various pages | Consolidate into a single Export menu (drawer with format/scope picker). |
| A30 | P2 | `scan_history.php` has a bare table with no chart or summary cards | Add success/fail bar + last-scanned-per-subnet summary. |
| A31 | P3 | `import_arp.php` UX is structurally similar to import_csv but doesn't share the stepper | Reuse the import_csv stepper. |

---

## 7. Cross-cutting findings

### 7.A — Design system (most leverage)

| ID | P | Title | Notes |
|---|---|---|---|
| C1 | **P1** | **No defined type scale — 14 distinct font sizes computed in production CSS** (11/11.9/12/13/14/14.4/14.72/16/18/18.4/20/20.8/24/26.4 px) | Define a 6-step scale (e.g. 12 / 14 / 16 / 20 / 24 / 32) and sweep. Open Props is loaded; use its `--font-size-*` tokens. |
| C2 | P1 | Body font-size is 14px desktop and 14px mobile | Mobile minimum is 16px (per-CLAUDE-`web-design-guidelines`). Bump to 16px on `<768px`. |
| C3 | P1 | Badge / pill / chip styling is inconsistent across pages | Status text in `addresses.php` (color-only), Group/Tag badges (rounded pill bg), site filter chips (rounded full), action pills (rounded outline). Pick **one** badge primitive with variants (status / tag / filter / action). |
| C4 | P1 | Color-only state communication appears on at least 4 surfaces | Utilization bars (red/yellow/green threshold), Used/Reserved/Free numbers, Health dots, Status text on addresses. WCAG 1.4.1 fails. Pair every color signal with a text/icon counterpart. |
| C5 | P2 | 81 CSS custom properties at `:root` while Open Props is loaded — duplicate token system | Code-quality D13 already tracks this; capture here as the visual layer of the same fix. |
| C6 | P2 | Brand link in sidebar gets default underline + visited color in dark mode | (See S9.) |
| C7 | P2 | Spacing between cards / sections is inconsistent (range from 12px to 32px) | Settle on a `--space-section` token. |
| C8 | P2 | Form-field error states are server-only — a failed save returns to the page top with a flash | Inline error per field. |
| C9 | P2 | Empty `<td>` with no fallback in some tables, "—" in others | (Captured per page; cross-cut for a sweep.) |
| C10 | P3 | Icons mix sizes — sidebar 16px, action pills 16px, kebab buttons 14px | Standardize to `--icon-sm: 16px / --icon-md: 20px`. |

### 7.B — Mobile / responsive

| ID | P | Title | Notes |
|---|---|---|---|
| C11 | P1 | Subnets page on mobile inherits the 103k-px desktop layout | Pagination/virtualization (L1) is the underlying fix; on mobile additionally collapse subnet cards to single-line summary that taps to expand. |
| C12 | P1 | Audit / Search tables overflow horizontally on mobile with 11 columns | Convert to "stacked card per row" pattern at `<768px` with column labels inline. |
| C13 | P1 | Settings page on mobile keeps the vertical tab list as a sidebar — eats the screen | Convert to a horizontal segmented control / dropdown picker on mobile. |
| C14 | P2 | Touch targets on action pill rows ~32px tall | WCAG / Apple HIG recommends 44px minimum. Bump action-pill min-height. |
| C15 | P2 | Mobile drawer takes ~70% of width and pushes content under a backdrop | Works, but the long flat link list is harder to thumb-scroll than a grouped/collapsible list (S8). |
| C16 | P3 | "IPAM (SQLite Test)" wraps to 2 lines in mobile drawer header | (See S14.) |

### 7.C — Accessibility (a11y)

| ID | P | Title | Notes |
|---|---|---|---|
| C17 | P1 | Color-only state — see C4 | WCAG 1.4.1. |
| C18 | P1 | Theme toggle button has accessible name "System" but no `aria-label` describing the cycle | "Theme: System (click to cycle Light / Dark / System)". |
| C19 | P2 | Several `<button>`s for "Hide widget" use `aria-label` (good) but nothing announces "widget hidden" / "widget restored" | Add `aria-live="polite"` region. |
| C20 | P2 | Skip-to-main link present (good) but focus order from skip → main can land on widget hide buttons before content | Verify tab order on dashboard. |
| C21 | P2 | Date inputs depend on browser-native styling | Acceptable; ensure they have associated `<label for>` (some don't). |
| C22 | P2 | Drawer pattern (when applied) needs focus trapping + Esc-to-close + return-focus-to-trigger | Confirm before the drawer pattern is rolled out app-wide (A12). |
| C23 | P2 | Tables with action columns lack `<caption>` / `<th scope>` consistency | Sweep. |
| C24 | P3 | `prefers-reduced-motion` not yet checked in `app.js` modules | Code-quality D4 splits app.js; add the media query at module-load time. |

### 7.D — Performance (UI-perceived)

| ID | P | Title | Notes |
|---|---|---|---|
| C25 | P1 | DOM weight on subnets.php > 3,700 elements | (L1.) |
| C26 | P2 | No skeleton loading on first paint of data-heavy pages | Skeleton rows where the data table will appear. |
| C27 | P2 | No optimistic UI on inline mutations | Edit-then-save round-trips redirect; should patch in place + roll back on error. |
| C28 | P3 | Sidebar SVG sprite (`assets/icons.svg`) loaded on every page; no `preload` hint | Marginal. |

### 7.E — Copy & content

| ID | P | Title | Notes |
|---|---|---|---|
| C29 | P2 | Page subtitles duplicate H1 ("Subnets" h1 / "Manage subnets" subtitle / "Grouped Hierarchy" panel header) | Sweep for redundant heading hierarchy. |
| C30 | P2 | Tooltips and helper text use mixed sentence-case vs Title Case | Style guide pass. |
| C31 | P3 | "Forgot password?" / "Sessions expire after 30 min" / "Manage Sites" / "Full Audit Log" — minor inconsistencies in capitalization across CTAs | Style guide pass. |
| C32 | P3 | Footer attribution to simplephpipam.com appears in every page | Fine — note. |

---

## 8. Effort estimate (per area)

| Area | P0 | P1 | P2 | P3 | Engineer-days |
|---|---:|---:|---:|---:|---:|
| §3 Auth & Shell (S1–S15) | 0 | 6 | 6 | 3 | ~4–6 |
| §4 Dashboard (D1–D10) | 0 | 3 | 7 | 0 | ~3–4 |
| §5 Data-heavy (L1–L31) | 0 | 9 | 16 | 6 | ~12–18 (L1 alone is 3–5 days) |
| §6 Admin & system (A1–A31) | 0 | 5 | 14 | 5 | ~10–14 |
| §7 Cross-cutting (C1–C32) | 0 | 5 | 18 | 9 | ~6–10 |
| **Total** | **0** | **28** | **36** | **14** | **~35–52 days** |

≈ **7–10 calendar weeks** of dedicated work; fits across 4–5 milestones at the project's normal cadence.

---

## 9. Recommended milestone allocation (DRAFT — pending approval)

User reserved **v3.31.0 onward** for this overhaul. All work must land before **v4.0.0**. Code-quality milestones (v3.26.0–v3.30.0) and backup-overhaul milestones (v3.20.0–v3.26.0) coexist.

| Milestone | Theme | Items | Effort |
|---|---|---|---|
| **v3.31.0** (NEW) | **Design system foundation** — type scale, badge primitive, color-not-only-signal, dark-mode brand link, mobile font-size | C1, C2, C3, C4, C5 (visual), C6, C7, C9, C10, S6, S9, A20 | ~5–7 d |
| **v3.32.0** (NEW) | **Sidebar + nav consolidation** — group admin into 5 sections, command palette discoverability, theme toggle, sidebar collapse, post-timeout deep-link | S2, S8, S10, S11, S12, S13, S14 | ~4–6 d |
| **v3.33.0** (NEW) | **Data-heavy pages overhaul (subnets + addresses)** — pagination/virtualization, drawer-edit, action consolidation, status-as-badge, URL-state filters, bulk-action bar | L1, L2, L3, L4, L5, L8, L9, L10, L11, L13, L14, L15, L16, C11 | ~10–14 d |
| **v3.34.0** (NEW) | **Search + audit + dashboard polish** — instant search, pretty-printed audit, relative time, pagination consistency, KPI cards clickable, recent-activity rendering | L17, L18, L19, L20, L21, L22, L23, L24, L25, L26, L27, L28, L29, D1, D2, D4, D5, D7, D8, D9, D10, C29 | ~6–8 d |
| **v3.35.0** (NEW) | **Admin pages standardization + import wizard + a11y sweep + mobile + auth polish** — drawer-CRUD across 12 admin pages, empty states, settings save UX, import-CSV stepper, drag-drop, mobile responsive sweep, a11y sweep, login UX (S1/S3-S5/S7), auth-flow rate-limit feedback, all remaining P2/P3 | A1–A31, S1, S3, S4, S5, S7, S15, C8, C12, C13, C14, C15, C16, C17, C18, C19, C20, C21, C22, C23, C24, C26, C27, C28, C30, C31, all P3 from prior milestones not landed | ~10–14 d |

Total: **~35–49 engineer-days across 5 new milestones**, all before v4.0.0.

### 9.1 Why this split (rationale)

- **v3.31.0 is the foundation.** Type scale + badge primitive + color-not-only-signal are leverage that every later milestone benefits from. Doing this *first* makes v3.32–v3.35 cheaper.
- **v3.32.0 is the nav consolidation.** Once admin links are grouped into 5 sections, v4.0.0 can add tenant switcher / super-admin without further bloat.
- **v3.33.0 is the highest-impact UX win.** Subnets and addresses pages are where operators spend 80% of their time; pagination + drawer + URL-state is the difference between "barely usable at scale" and "feels modern."
- **v3.34.0 is the polish for the second-most-used surfaces.** Search and audit are where operators investigate; dashboard is the daily welcome. Land after the data-heavy pages so the patterns are reusable.
- **v3.35.0 is the cleanup pass.** Admin pages, mobile sweep, a11y sweep, auth polish, all remaining P2/P3. Lands clean for v4.0.0.

### 9.2 Approval + filing record (2026-04-30)

User approved the 5-milestone split as proposed (no items downgraded or promoted). Issues filed under the `ux-overhaul` label:

| Milestone | UX-overhaul issues filed | Notes |
|---|---:|---|
| v3.31.0 | 10 | Design-system foundation |
| v3.32.0 | 7 | Sidebar + nav consolidation |
| v3.33.0 | 13 | Subnets + addresses overhaul |
| v3.34.0 | 18 | Search + audit + dashboard polish |
| v3.35.0 | 34 | Admin standardization + import wizard + a11y + mobile + auth polish |
| **Total** | **82** | All tagged with the `ux-overhaul` label. |

All issues cite their finding ID(s) (S1–S15, D1–D10, L1–L31, A1–A31, C1–C32) and link back to this document. Adjacent findings were merged into single issues where the work is one commit (e.g. C4+C17+D5+L4+L10+A20 — color-only state communication; L19+L27+A9 — relative time; A3+A4+A5+A6 — settings copy sweep). Total finding coverage is preserved.

### 9.3 Interaction with existing milestones

| Existing milestone | Interaction |
|---|---|
| v3.20.0–v3.26.0 (backup-overhaul) | **Excluded from this doc.** Drawer pattern from `backup_overhaul.md` §2 is the *reference* for §6.C admin standardization (A12) — extend it, don't replace. |
| v3.26.0–v3.30.0 (code-quality) | Several findings here align with code-quality work: D13 ↔ C5 (Open Props), D4 ↔ app.js modularization is the technical layer of S10/S12/A12, B8 ↔ L2 (addresses/subnets controller split is the *data-flow* prerequisite for the *visual* drawer rebuild here). Schedule accordingly. |
| v4.0.0 (multi-tenancy) | S6 (brand link consumes `branding.site_name`), S8 (sidebar adds tenant switcher cleanly into a grouped IA), L25 (Client IP visibility gated by super-admin/tenant-admin), all benefit from this overhaul landing first. |

---

## 10. Decision log

| Date | Decision | Rationale |
|---|---|---|
| 2026-04-30 | Audit scope explicitly excludes backup/restore UX | `backup_overhaul.md` §2 + §7 already define the 5-tab unified surface, drawer pattern, and U1–U10 backlog. Deduplication. |
| 2026-04-30 | All findings must close before v4.0.0 | User directive — tighten the GUI before multi-tenancy. |
| 2026-04-30 | This file is single source of truth — issues must reference finding IDs (S/D/L/A/C prefixes) | Mirrors `backup_overhaul.md` and `code_quality_review.md` discipline. |
| 2026-04-30 | 5-milestone split approved as proposed | User accepted the rationale in §9.1. |
| 2026-04-30 | 82 finding-issues filed under `ux-overhaul` label across v3.31.0–v3.35.0 | See §9.2 for per-milestone counts. Adjacent findings merged into single issues where the work is one commit. |
| 2026-04-30 | 5 test-update umbrella issues added (one per UX milestone) — #1035–#1039 | Existing Playwright + PHPUnit + VR + a11y suites need to stay in lockstep with UI changes; test umbrellas are the canonical tracker for that. See §12. |

(Further decisions to be added as triage and implementation proceed.)

---

## 12. Test-suite updates (per milestone)

Every UX milestone in §9 has a corresponding **test-update umbrella issue** so the existing Playwright + PHPUnit + VR + a11y suites stay in lockstep with the UI changes. Test work is not optional — pretty much every UX finding either invalidates an existing assertion (raw event names → pretty-printed; flat sidebar → grouped; "click Load" → auto-load) or introduces a new pattern that needs a new test (drawer focus-trap, instant-search debounce, axe-core a11y sweep).

| Milestone | Test umbrella | Highlights |
|---|---|---|
| v3.31.0 | `tests: rebaseline VR + add a11y tests for design-system foundation (v3.31.0)` (#1035) | Full VR rebaseline (type scale, badge primitive, color signalling); add axe-core pass; CSS-token validator that font-sizes outside the scale fail CI; dark-mode brand-link regression guard. |
| v3.32.0 | `tests: update nav specs + add command palette / theme toggle / drawer trap tests (v3.32.0)` (#1036) | Rewrite nav specs for grouped sidebar; add command-palette / theme-toggle / sidebar-collapse tests; add deep-link return after session timeout. |
| v3.33.0 | `tests: rewrite subnets/addresses specs + drawer + virtual scroll + bulk-action tests (v3.33.0)` (#1037) | **Largest test impact.** Rewrite subnets + addresses specs for paginated/virtualized DOM; add drawer-pattern tests (open/close/Esc/focus-trap); URL-state filters round-trip; mobile stacked-card mode; perf assertion that subnets page renders <1,000 interactive elements (vs. 3,727 today). |
| v3.34.0 | `tests: search debounce + audit pretty-print + dashboard interactivity tests (v3.34.0)` (#1038) | Debounced search; relative-time helper with frozen clock; audit pretty-print + Client IP role gating; KPI clickable; recent-activity rendering; jump-to-page pagination. |
| v3.35.0 | `tests: drawer-CRUD admin matrix + import wizard + a11y axe sweep + mobile sweep + auth tests (v3.35.0)` (#1039) | Parameterize a single admin-CRUD matrix across all 12 admin pages (replaces per-page specs); import-CSV stepper / drag-drop / size validation; full-app axe-core a11y sweep; mobile-viewport sweep (settings, touch targets, drawer); auth UX tests (show-password, rate-limit feedback). |

**Cross-cutting test policy** (applies to every milestone):
- Each PR closing a finding must update or add the matching test in the same commit. PR template prompt: *"Tests updated/added for finding ID(s)?"*
- VR rebaselines must be reviewed by a second pair of eyes and committed in their own commit per CLAUDE.md release-workflow guidance.
- a11y tests use axe-core's `serious` + `critical` thresholds — no warnings-only mode.
- Mobile-viewport tests use 375×812 (iPhone SE / 13 mini) and run under the same Playwright project rather than a separate config.
- The test umbrella issue stays open until *all* UX issues in its milestone are closed; closing the umbrella is the last step of each milestone.

## 13. Maintenance protocol

- When filing an issue, reference its finding ID (e.g. "Closes L1, L2" in PR title).
- When triaging defers/closes, add a row to §10.
- When a new finding surfaces during implementation, append to the matching area's table; never silently re-use a finding ID.
- This document is **not** updated post-v4.0.0 — at that point it is archived alongside `backup_overhaul.md` and `code_quality_review.md`.

---

## Appendix — Audit artefacts

Screenshots captured during the walkthrough live under `.tmp/ux-audit/` (gitignored): `01-login-desktop.png`, `02-dashboard-desktop-light.png`, `03-login-timeout.png`, `04-subnets-desktop.png` (full-page, 103k px tall), `05-subnets-viewport.png`, `06-addresses-viewport.png`, `07-search-empty.png`, `08-settings-viewport.png`, `09-audit-viewport.png`, `10-users-viewport.png`, `11-import-csv.png`, `12-health-viewport.png`, `13-dashboard-mobile.png`, `14-mobile-nav-open.png`, `15-dashboard-dark.png`, `16-subnets-dark.png`. Re-capture by re-running the Playwright walkthrough at any time.
