# ADR-002: Settings save semantics — per-key vs group-form

**Status:** accepted
**Decided:** 2026-05-15
**Scope:** prerequisite for refactor wave 1 (v3.30.0); forward-looking policy for `settings.php` save handling.
**Stamped by:** Sean Mousseau

> **⚠️ Correction (2026-05-16) — the current-state premise was wrong.**
> During v3.30.0 execution (Task 5.3 prep) the codebase was checked against this
> ADR and the assumption that `site_theme` is a row in the `settings` table was
> found to be **factually incorrect**. There is no `site_theme` setting anywhere
> in the registry. Theme is *already* a per-user preference: a `users.theme`
> column written by a dedicated `set_theme.php` endpoint (POST
> `theme=light|dark|auto`, CSRF + session gated, no admin gate, instant-save),
> with `assets/app.js` `cycleTheme()` POSTing to it and `page_header()` reading
> `$_SESSION['user_theme']`.
>
> **What survives:** the core decision — Option B, "the group form is the only
> `settings` save path; per-user instant-save preferences live in a separate
> subsystem with a separate table and endpoint" — is unaffected. Theme already
> embodies that separation; it is simply implemented as an ad-hoc `users` column
> + bespoke endpoint rather than the generic `user_preferences` table.
>
> **Re-stamped (Sean, 2026-05-16):** Open Question 2 is resolved to **option (a)**
> — migrate `users.theme` → `user_preferences` with a one-time backfill, retire
> `set_theme.php`, drop the `users.theme` column. The Recommendation (point 3)
> and the Implications file list below are corrected accordingly. Task 5.3
> resumes on this basis.
>
> **Endpoint correction (2026-05-16):** earlier revisions of this ADR (Option B
> mechanism, Recommendation, Implications) described the write endpoint as a
> resource in `api.php` / a `/api/user_preference` URL. That is **wrong** and
> would violate CLAUDE.md invariant #4: `api.php` is the Bearer-only,
> CSRF-exempt surface and must stay that way. The preference write endpoint is
> instead a **dedicated session-authenticated, CSRF-required JSON endpoint file**
> (`user_preference.php` at the web root) that supersedes `set_theme.php`. All
> "`api.php`" / "`/api/user_preference`" references below should be read as
> "`user_preference.php`".

---

## Context

`settings.php` historically had **two parallel write paths** for the same `settings` table rows:

1. **Group form** — operator edits multiple fields in a Settings tab, clicks "Save Group," every field in the group commits atomically inside a transaction.
2. **Per-key handler** — JavaScript-driven shadow form POSTed a single key/value on toggle/change, e.g. the security tab's "Enable OIDC" boolean would auto-commit the instant the operator flipped it.

The two paths converged on `ipam_setting_set()` but had **distinct POST shapes, distinct CSRF handling, distinct redirect semantics, and distinct UI side-effects** — which meant they could disagree about what was "currently being edited" in the same browser tab.

### Bug V (#1121) — the root incident

> "Toggling a boolean in the settings page can wipe unsaved text-field input in the same group."

Scenario: operator types a new value into an `int` field in the Security tab (e.g. session_idle_seconds = 3600), then before clicking Save Group, flips the "Enable WebAuthn" toggle in the same tab. The toggle's JS auto-submitted a per-key POST, which on redirect re-rendered the page **from DB state** — wiping the unsaved `int` field input.

Two writes to the same logical state (the Settings form) raced; the per-key write always won because it autocommitted, and the operator's stage area silently lost the unsaved text. This was a Pass A regression in v3.27.1 and was deferred through v3.27.2 → v3.29.0.

### Resolution trajectory

| Release | Action | File |
|---|---|---|
| v3.27.2 | UI-side fix: shadow form removed, auto-submit JS removed, `data-setting-toggle-target` attribute gone. Bool toggles now stage in the group form like every other field. | `settings.php`, `assets/app.js` |
| v3.29.0 (#1126) | Server-side per-key handler block deleted from `settings.php`. Every Playwright fixture migrated to the group-form path. New regression test `SettingsToggleConsistencyTest::testPerKeyHandlerIsGone`. | `settings.php` (block at lines 95-174 deleted) |

**Today (post-v3.29.0):** there is only one save path. The group form. Bug V cannot recur because the second path doesn't exist.

### Why an ADR if the bug is fixed?

Two reasons:

1. **No written policy.** The fact that we're down to one save path is implemented but not declared. A future contributor (or a future Claude session) could plausibly re-introduce a per-key shortcut for a "narrow" UX need — e.g. a nav-theme toggle that "should obviously commit instantly," a feature-flag toggle in a /dev admin tab, an "advanced" panel where everything autosaves. Each would be a defensible micro-decision that re-introduces the exact bifurcation Bug V proved is dangerous.

2. **ADR-001's `setting_definitions` schema introduces an obvious knob.** With `setting_definitions.type = 'bool'` it's tempting to add `setting_definitions.save_mode = 'instant'|'group'` and say "now we have a controlled, schema-described bifurcation, what could go wrong?" Bug V is exactly what could go wrong. The decision shape needs to be locked **before** the schema lands so the column isn't there to be misused.

## Decision drivers

- **Bug V must remain impossible to recur.** A fixed bug is not a closed case; a fixed bug with a written policy preventing reintroduction is.
- **UX expectation.** Some controls (theme toggle, sidebar pin, density toggle) historically autosave on other apps. Operators may expect this on IPAM too. Saying "no, click Save" is a deliberate UX cost.
- **Atomicity guarantee.** Group form's transaction wrapping is load-bearing — the step-up auth and audit emission rely on "all fields in a group commit together or none do." A per-key path breaks that.
- **Test surface.** The per-key handler doubled the Playwright fixture surface. Maintenance cost was real.
- **Coupling to ADR-001.** Decision must land before `setting_definitions` schema commits so we know whether to add a `save_mode` column.

## Options considered

### Option A — Lock in "group form only," forever

**Mechanism:** Declare that the group-form path is the single save semantic for `settings.php` permanently. No per-key endpoint, no instant-save, no schema knob. `SettingsToggleConsistencyTest::testPerKeyHandlerIsGone` becomes the load-bearing regression guard.

**Pros:**
- Zero risk of Bug V recurrence.
- Simplest mental model: "type, click Save, persisted."
- No schema-side toggle to misuse.

**Cons:**
- UX cost for controls where instant-save is a real ergonomic win (theme toggle in particular — operators flip it to see how the app looks in dark mode, and clicking Save to commit feels wrong).
- Closes off a legitimate design direction without weighing future cases.

### Option B — Group form is the default; per-key is opt-in via allowlist

**Mechanism:** Group form is the only path **unless** a setting's `setting_definitions.save_mode = 'instant'` (or similar). The allowlist is small (current candidates: theme toggle, sidebar pinned/unpinned, density preference). Each allowlisted setting must:

- Be a `'bool'` or `'enum'` subtype (not free-form text).
- Be **user-scoped**, not tenant-scoped or global. Storage moves into a different table (`user_preferences` or similar) and stops sharing a row with system settings.
- Have a CR-level review note explaining the UX justification.

The instant-save path lives at a **different URL** (e.g. `/api/user_preference?key=…`) so it cannot accidentally race a `settings.php` group submit — the two paths target different tables.

**Pros:**
- Preserves the instant-save UX for the small set of controls where it matters.
- The atomicity guarantee of the group form is preserved (no instant-save touches `settings`).
- The new path is **operator preference, not system config** — a different shape, not a re-bifurcation.

**Cons:**
- Adds a new table + a new endpoint + a new auth model (user-scoped, not admin-only).
- The "different URL, different table" framing is honest but a future contributor could still file a "wouldn't it be nice if X were instant-save" issue and the answer requires explaining why it's not allowed.
- Scope drift candidate: once user_preferences exists, every "could this be a preference?" question runs through the same review filter.

### Option C — All-AJAX commit-on-blur

**Mechanism:** Every settings field auto-commits when it loses focus. No Save buttons. Each field is its own atomic write.

**Pros:**
- No "did I save?" anxiety.
- Modern app pattern (Notion, Linear, Stripe Dashboard).

**Cons:**
- **Re-introduces Bug V in a worse form.** Now every field is its own per-key write; the "wipe unsaved input on toggle" interaction generalises to "wipe unsaved input on tab key."
- Loses transactional atomicity entirely — a partial commit (some fields saved, some not) is now the normal case if the network blips mid-form.
- Step-up auth and audit can't reason about "this group changed together" — they get N micro-events.
- The validation-error path is hostile: an error on field 3 now means fields 1 and 2 are persisted but field 3 is rejected. Operator must figure out what's saved vs not.

### Option D — Freeze status quo via lint; defer policy until a real UX need surfaces

**Mechanism:** Keep the current state (single group-form path, regression test in place). Don't take a forward-looking position now. The next time someone files "we should add an instant-save toggle for X," **that** triggers an ADR-002A scoped to the specific case.

**Pros:**
- No premature decision; the real cases will be self-evident when they arrive.
- Zero work in v3.30.0.

**Cons:**
- Doesn't actually close the ADR-002 prerequisite — anyone reading "settings save semantics is an open ADR" later will not know if the answer is "decided permanently" or "deferred indefinitely."
- The ADR-001 schema lands without `save_mode`; that's correct under D but it should be declared on purpose rather than as an absence.

## Recommendation

**Pick Option B (group form default; per-key allowed only via user-preferences table + explicit allowlist).**

The decision drivers tip toward B because:

1. **It addresses both the bug-prevention and UX-preservation drivers.** A pure "lock to one path" (Option A) protects against Bug V but pays UX cost for the legitimate cases. B captures the legitimate cases in a structurally different shape — user preferences in their own table, system settings in `settings` — so the bifurcation isn't a bifurcation anymore; it's two distinct subsystems.

2. **The atomicity guarantee is preserved.** Bug V's root cause was "two paths writing to the same logical state." B's split makes that impossible by construction: the instant-save path writes to a different table, the group-form path writes to `settings`. Two writes can't race because they're not writing the same rows.

3. **The user-preferences split is a real architectural improvement on its own merits.** A consolidated `user_preferences` table gives per-user view preferences one schema-defined home. *(Correction 2026-05-16: theme is **already** a per-user preference — the `users.theme` column, written by `set_theme.php` — not a `settings` row. The improvement is consolidating that ad-hoc column + bespoke endpoint into the generic `user_preferences` table + `user_preference.php`, not "splitting it out of `settings`.")*

4. **B is forward-compatible with A.** If user_preferences turns out to be more trouble than the UX win is worth, we can collapse back to A in a future release by absorbing user_preferences rows back into `settings` (single migration) and re-locking. A → B → A round-tripping is straightforward; A → C → A isn't.

5. **B forces the question every time.** Adding a setting to the user_preferences allowlist requires a CR-level review note. There's no "drift into bifurcation" — every entry is intentional.

The `setting_definitions.save_mode` knob that ADR-001 might naively add is **explicitly not introduced** under B. The split lives in the table choice, not in a flag.

## Implications

If accepted:

- **GH issues to open (milestone #56 unless noted):**
  - `feat(prefs): introduce user_preferences table (key/value, user-scoped, no `type` column — schema-defined)` — schema mirrors the v3.30.0 `setting_definitions` shape but is user-scoped not global
  - `feat(prefs): user_preference.php endpoint — dedicated session-authed + CSRF-required JSON file, POST {key, value}, auth = current user, no admin gate, atomic single-row UPSERT (NOT a resource in api.php — invariant #4)`
  - `refactor: move site_theme + future "view preferences" out of settings into user_preferences`
  - `tests(prefs): per-key instant-save round-trip; verify writes never touch settings table; CSRF + auth gate`
  - `docs(internal): security-model.md — user_preferences is user-scoped, no admin gate, distinct trust boundary from settings`
  - `docs(internal): coding-guide.md — "new settings go in settings; new per-user view preferences go in user_preferences. Adding a NEW row to the per-key allowlist requires an ADR amendment, not just a code review."`
- **GH issues to close / scope-cut:**
  - Bug V #1121 already closed (in v3.27.2 + v3.29.0). This ADR formalises why it stays closed.
- **Files that change** *(corrected 2026-05-16 — the original `settings.php` / `site_theme`-row entries were wrong; the real theme surface is `set_theme.php` + the `users.theme` column)*:
  - `Simple-PHP-IPAM/schema.sql` + .mysql + .pgsql — new `user_preferences` table
  - `Simple-PHP-IPAM/migrations.php` — new migration closure (plus a `users.theme` → `user_preferences` backfill if re-opened Q2 lands on option (a))
  - `Simple-PHP-IPAM/lib/user_preferences.php` (new module per ADR-004 decomposition) — `ipam_user_preference_get/set` helpers
  - `Simple-PHP-IPAM/user_preference.php` — new dedicated endpoint file (POST/GET only, session-authed, CSRF-required for POST, no admin gate). **Not** a resource in `api.php` — `api.php` is the Bearer-only, CSRF-exempt surface (CLAUDE.md invariant #4) and stays that way.
  - `Simple-PHP-IPAM/set_theme.php` — retired / superseded by `user_preference.php` (Q2 option (a))
  - `Simple-PHP-IPAM/assets/app.js` — `cycleTheme()` re-points from `set_theme.php` to the new endpoint
  - `Simple-PHP-IPAM/lib/presentation.php` — `page_header()` theme read sources from `user_preferences` instead of `$_SESSION['user_theme']` / `users.theme`
- **Schema migrations needed in v3.30.0:** new `user_preferences` table; whether existing `users.theme` data is backfilled into it depends on the re-opened Open Question 2.
- **Docs to update:**
  - `docs/internal/data-dictionary.md` — new `user_preferences` table
  - `docs/internal/security-model.md` — user-preference trust boundary
  - `docs/internal/coding-guide.md` — settings vs preferences rule
  - `docs/internal/roadmap.md` § 10 — strike "Per-key vs group-form bifurcation" from the locked-pre-wave-1 list; ADR-002 resolves it
  - `docs/internal/architecture-decisions/README.md` — index update + ADR-001 cross-reference (ADR-001 explicitly does NOT add `save_mode` because of this decision)
- **Future ADRs unblocked:** ADR-004 (lib.php module shape) can now treat settings dispatch and user-prefs dispatch as separate islands.

## Open questions

All four resolved at stamping (2026-05-15):

1. ~~Initial allowlist size?~~ **Resolved:** v3.30.0 ships with `site_theme` **only**. Every future entry requires an ADR-002 amendment recorded here, not just a code-review nod.
2. **Re-opened then resolved 2026-05-16 — theme migration / backfill.** The original "no backfill" answer assumed `site_theme` was an unpopulated `settings` row. It is not — theme is the `users.theme` column and holds live per-user data for every user who has set one. **Resolved (re-stamp, Sean, 2026-05-16): option (a)** — migrate `users.theme` → `user_preferences` with a one-time backfill (every existing user's theme is copied into the new table), retire `set_theme.php`, repoint `assets/app.js` `cycleTheme()` and `page_header()` at `user_preference.php`, and drop the `users.theme` column. The backfill is mandatory because live per-user data exists. Rejected: (b) build the subsystem but leave theme on `users.theme` (would ship the table with no allowlisted key, conflicting with Q1); (c) defer the subsystem entirely.
3. ~~Schema name?~~ **Resolved:** **`user_preferences`**. Idiomatic across other CRUD apps; the scope-creep risk is mitigated by the explicit allowlist rule (open questions answer #1) — adding profile-shaped data to this table requires an ADR amendment.
4. ~~Step-up auth for preference writes?~~ **Resolved:** **No.** Preferences are cosmetic; CSRF + session is the only gate. A future security-relevant preference (e.g. "show secret values in UI by default") would require an ADR-002 amendment that explicitly raises the auth bar for that key.

## References

- ADR-001 — settings table type system (accepted 2026-05-15) — explicitly does NOT add `save_mode` because ADR-002 locks the answer here.
- `docs/internal/roadmap.md` § 10.1 (locked 2026-05-11)
- Bug V #1121 — root incident
- `Simple-PHP-IPAM/settings.php:89-99` — historical comment block documenting the v3.27.2 + v3.29.0 #1126 resolution
- `tests/SettingsToggleConsistencyTest::testPerKeyHandlerIsGone` — regression guard
