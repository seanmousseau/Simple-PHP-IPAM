# v4.x release stream — design

> **Forward-looking strategy.** Not a commitment. Captures the rationale, theme, sequencing, and library decisions for the v4.x release stream so they don't have to be re-litigated each session. Mirrors the pattern of `v4-tenancy-design.md` and `i18n-design.md` — design lock, not implementation start.
>
> Read this when: planning what goes into a v4.X release, evaluating a new auth/i18n feature for fit, or being asked "why isn't multi-tenancy in v4 anymore?"

---

## Theme

**v4.x = enterprise auth + global reach.**

Two complementary value drivers:

1. **Enterprise auth** — bring IPAM into the credential ecosystems large orgs already operate (SAML SSO, LDAP/AD bind, fine-grained RBAC, SCIM provisioning). Removes the friction that keeps IPAM out of compliance-bound deployments.
2. **Global reach (i18n)** — make IPAM usable by non-English ops teams. Removes the silent disqualifier that filters out most of the global ops user base.

Together: every release in the v4 stream either widens the addressable population (i18n) or removes a procurement blocker (enterprise auth).

---

## Why this theme

### Why NOT multi-tenancy as v4.0

Originally planned. **Deferred** to a non-version "Multi-tenancy (deferred)" milestone for sustainability reasons:

- The primary beneficiary of multi-tenancy is **MSPs** (managed service providers running IPAM-as-a-service for many customer networks).
- The project has **no commercial licensing infrastructure**. Without one, multi-tenancy ships as a free OSS feature whose biggest economic beneficiary returns nothing to the project.
- Maintenance burden falls on the project regardless.
- Per-user value is high but **addressable population is small** compared to the full set of single-tenant ops users globally.

This is a **sustainability decision, not a technical one.** When a licensing model exists (freemium with tenant cap, or dual-license, or co-maintainer steps up), multi-tenancy can be revived. Design is captured intact in `v4-tenancy-design.md`; tickets are parked in the "Multi-tenancy (deferred)" milestone.

### Why i18n + auth specifically as the v4 theme

- **Each is independently valuable.** Either alone justifies a major version bump because the user-facing impact is dramatic (new language picker, new SAML/LDAP options).
- **Together they form a coherent narrative.** "v4 makes IPAM usable by enterprise ops teams in any language."
- **Both grow adoption without requiring a commercial model.** Free-software-friendly value capture: more users → more contributors, more issue reporters, more brand pull, eventual support/enterprise interest.
- **Neither blocks the other.** Auth and i18n touch different parts of the codebase and can interleave without coordination overhead.
- **Phasing is natural.** i18n is 4 phases (infrastructure → extraction → first translation → crowdsourcing). Auth is several discrete additions (RBAC, JWT lib swap, SAML, LDAP, OAuth, SCIM). The stream interleaves cleanly.

---

## Release sequencing (12 releases)

Each release has one coherent theme. Stream takes IPAM from "single English-only org tool" to "multi-language enterprise-auth-capable IPAM" across 12 minor releases. Maintainer is comfortable stretching cadence over speed; each release should be reviewable on its own.

Each row links to a tracking epic in the GitHub milestone of that name. Sub-issues per release are spawned at scope-lock per `release-kickoff-prompt.md`.

| # | Release | Headline | Tracking | Why this slot |
|---|---|---|---|---|
| 1 | **v4.0.0** | i18n infrastructure (phase 1) | [#1064](https://github.com/seanmousseau/Simple-PHP-IPAM/issues/1064) | Foundation. Major version bump announces the v4 stream theme. User-visible payoff: language picker exists in Account page (English variants only) |
| 2 | **v4.1.0** | i18n extraction sweep (phase 2) | [#1063](https://github.com/seanmousseau/Simple-PHP-IPAM/issues/1063) | Mechanical wrap-every-string PR. Must follow v4.0; unblocks any actual translation. Separated for review burden |
| 3 | **v4.2.0** | OIDC engine swap (firebase/php-jwt) | [#417](https://github.com/seanmousseau/Simple-PHP-IPAM/issues/417) | Retire hand-rolled JWT/JWK code. Foundational for SAML signing in v4.7.0. Small, focused, security-positive |
| 4 | **v4.3.0** | RBAC foundation: user groups | [#334](https://github.com/seanmousseau/Simple-PHP-IPAM/issues/334) | New `groups` table + `user_groups` join. Must come before editable RBAC; doing alone keeps scope tight |
| 5 | **v4.4.0** | Editable RBAC engine | [#456](https://github.com/seanmousseau/Simple-PHP-IPAM/issues/456) | Replace hard-coded `admin`/`readonly` checks with permission-target gates. Headline RBAC release. Builds on v4.3 (groups) |
| 6 | **v4.5.0** | Per-subnet ACLs | [#333](https://github.com/seanmousseau/Simple-PHP-IPAM/issues/333) | Resource-level ACLs complementing role-level RBAC. Completes the permissions story |
| 7 | **v4.6.0** | i18n first non-English: fr-CA (phase 3) | [#1066](https://github.com/seanmousseau/Simple-PHP-IPAM/issues/1066) | First real translation. Validates translator workflow, plural-forms, text expansion. Interleave point — visible i18n progress between auth releases |
| 8 | **v4.7.0** | SAML 2.0 SSO | [#1065](https://github.com/seanmousseau/Simple-PHP-IPAM/issues/1065) | First major enterprise auth integration. `onelogin/php-saml`. Reuses JWT primitives from v4.2 |
| 9 | **v4.8.0** | LDAP / Active Directory | [#1069](https://github.com/seanmousseau/Simple-PHP-IPAM/issues/1069) | Most-requested enterprise auth gap. `symfony/ldap`. Independent of SAML |
| 10 | **v4.9.0** | Generic OAuth 2.0 providers | [#1070](https://github.com/seanmousseau/Simple-PHP-IPAM/issues/1070) | Supplements existing OIDC. `league/oauth2-client` for GitHub/GitLab/Bitbucket/custom |
| 11 | **v4.10.0** | SCIM 2.0 provisioning | [#1068](https://github.com/seanmousseau/Simple-PHP-IPAM/issues/1068) | Lifecycle automation from Okta/Azure AD/etc. Hand-rolled REST over existing user table |
| 12 | **v4.11.0** | i18n crowdsourcing: Weblate (phase 4) | [#1071](https://github.com/seanmousseau/Simple-PHP-IPAM/issues/1071) | Wraps the v4.x stream. Self-hosted Weblate, community translations |

### Stream characteristics

- **12 minor releases** — comparable to the existing v3.22 → v3.35 cadence depth.
- **No release "wastes" a version slot.** Every release has a clear user-facing payoff or a foundational must-do. No procedural releases.
- **Every release is independently shippable.** Each has its own changelog story. If a release slips, the rest can still proceed (with the hard-constraint exceptions below).
- **Patch versions reserved for hotfixes** per existing release model. Additional language catalogs after v4.6.0 can ship as v4.6.1/v4.6.2/etc. (catalog-only patches; no app code changes).
- **`firebase/php-jwt` adoption demoted from "Explicitly not adopted"** in `runtime-dependency-policy.md` — needs corresponding policy doc update at v4.2.0 ship time.
- Sequencing is **structured, not locked**. Specific feature scope per release is made at scope-lock time per `release-workflow.md`. The hard constraints (below) cannot move; soft constraints can.

### Hard sequencing constraints

These cannot be reordered without breaking dependencies:

- **v4.0 → v4.1** — extraction sweep requires the helpers from infrastructure
- **v4.0 → v4.6** — first translation requires the catalog wired up
- **v4.2 → v4.7** — SAML signing reuses JWT primitives; do the swap first
- **v4.3 → v4.4** — RBAC's permission target should be groups from day one (avoid users → groups refactor mid-RBAC release)
- **v4.4 → v4.5** — per-subnet ACLs check role permissions; need the role engine first
- **v4.6 → v4.11** — Weblate without a shipped non-English catalog has nothing to crowdsource

### Soft sequencing constraints

Preferred but flexible:

- **i18n + auth interleave** — v4.0/v4.1 i18n; v4.2 OIDC swap; v4.3/v4.4/v4.5 RBAC; v4.6 i18n; v4.7-4.10 auth; v4.11 i18n. Users see steady progress on both fronts; neither stream feels stalled
- **OIDC swap before SAML** — minor sequencing nicety; SAML's signing implementation is cleaner inheriting new JWT primitives rather than soon-to-be-retired code
- **LDAP before OAuth** — enterprise demand for LDAP is more universal than for non-OIDC OAuth; ship the bigger lift first

### When to stop or shorten

Mid-stream sustainability checkpoint after **v4.5 or v4.6**. If any of these are true:

- **Community translation interest hasn't materialized** → drop v4.11.0 (Weblate is overhead without contributors)
- **Enterprise SAML/LDAP demand hasn't materialized** → drop or reorder v4.7-v4.10 (e.g. ship LDAP only, defer SAML/OAuth/SCIM)
- **Maintainer bandwidth / burnout signals** → consolidate remaining auth releases (e.g. SAML + LDAP into one release)

The point of the schedule is to have a clear plan; the point of the checkpoint is to avoid sunk-cost completion if reality diverges from the plan.

---

## Library decisions

For each library candidate, decisions are tentative until the implementing release scopes its actual ticket. All clear the runtime-dependency-policy bar (`docs/internal/runtime-dependency-policy.md`).

### Recommended adoptions

| Library | Version | Purpose | Why this one |
|---|---|---|---|
| **firebase/php-jwt** | ^6.0 | Retire hand-rolled JWT/JWK code in OIDC; pre-req for SAML signing | Mature (~12y), MIT, low transitive deps. Industry standard for PHP JWT |
| **onelogin/php-saml** | ^4.0 | SAML 2.0 SP role | Lightweight (vs SimpleSAMLphp), focused on SP-only, ~10y mature, MIT. Used by widely-deployed PHP CMSes |
| **symfony/ldap** | ^7.0 | LDAP/AD authentication wrapping `ext-ldap` | Decoupled from full Symfony framework, MIT, well-maintained. Hand-rolling LDAP filters is footgun-rich |
| **league/oauth2-client** | ^2.7 | Generic OAuth 2.0 client for non-OIDC providers | League ecosystem is widely used in modern PHP. MIT. Many provider-specific sub-packages already exist |

### Considered and not adopted (explicit choices)

| Library | Why not |
|---|---|
| `simplesamlphp/simplesamlphp` | Heavyweight (~20MB), self-contained "framework" rather than embed-friendly library. Meant to be standalone SAML toolkit, not what IPAM needs |
| `freedsx/ldap` | Pure-PHP LDAP (no `ext-ldap` needed). Backup option only — `ext-ldap` is widely available and `symfony/ldap` is the better-maintained wrapper |
| Hand-rolled SCIM library | SCIM 2.0 is a REST spec — building endpoints on the existing user table is simpler than adopting a library. No mature embed-friendly PHP SCIM library exists anyway |
| Hand-rolled LDAP | `ext-ldap` directly is footgun-rich (search filter escaping, paging controls, SASL mechanisms). The wrapper pays for itself |
| `arietimmerman/laravel-scim-server` | Laravel-coupled |
| `ext-krb5` (Kerberos / SPNEGO) | Niche, mostly Windows enterprise. Defer until specific demand surfaces |

---

## What's NOT in v4.x

Explicit non-scope to prevent scope creep:

- **Multi-tenancy** — deferred (see above). Lives in `v4-tenancy-design.md` + "Multi-tenancy (deferred)" milestone.
- **API translation** — REST/JSON responses stay English (per `i18n-design.md` non-goals). Clients localize their own UIs.
- **Documentation translation** — `docs/*.md` stays English in v4.x. Translation is a phase-5+ workstream.
- **Marketing site translation** — separate WordPress workstream, not bundled with app i18n.
- **Per-tenant key derivation (HKDF)** — paired with multi-tenancy, deferred.
- **Tenant URL resolution / discovery page / super-admin model** — deferred.
- **Right-to-left language support** — deferred to phase 5+ (use CSS logical properties going forward to bound future cost; ship time when there's RTL translation demand).

---

## Cross-cutting concerns

These touch multiple v4.x releases and need consistent handling:

### Audit logging

Every new auth method needs new audit actions per `audit-actions.md`. Conventions:

- `auth.saml_login`, `auth.saml_failed`, `auth.saml_provision`
- `auth.ldap_login`, `auth.ldap_failed`, `auth.ldap_bind_failed`
- `auth.oauth_login`, `auth.oauth_failed`, `auth.oauth_provision`
- `auth.scim_provision`, `auth.scim_update`, `auth.scim_deactivate`
- `rbac.role_create`, `rbac.role_update`, `rbac.role_delete`, `rbac.permission_grant`, `rbac.permission_revoke`
- `i18n.locale_change` (user changed their language preference)

Add to `audit-actions.md` when each release lands.

### Settings registry

Each new auth method adds settings via `ipam_setting_definitions()` per `adding-a-setting.md`:

- `saml.enabled`, `saml.idp_metadata_url`, `saml.entity_id`, `saml.x509_cert`, `saml.x509_key` (sensitive)
- `ldap.enabled`, `ldap.server`, `ldap.bind_dn`, `ldap.bind_password` (sensitive), `ldap.base_dn`, `ldap.user_filter`, `ldap.group_filter`
- `oauth.enabled`, `oauth.providers` (JSON array of provider configs)
- `scim.enabled`, `scim.bearer_token` (sensitive)
- `rbac.enabled`, `i18n.enabled`, `i18n.fallback_locale`

### User identity model

Adding multiple auth providers per user means the `users` table grows linkage columns. Reuse the existing `users.oidc_sub` pattern:

- `users.saml_nameid` (UNIQUE, nullable)
- `users.ldap_dn` (UNIQUE, nullable)
- `users.oauth_provider` + `users.oauth_id` (composite UNIQUE, nullable) — or normalize into a separate `user_oauth_links` table if multi-provider per user is needed
- `users.scim_external_id` (UNIQUE, nullable)

Consider a v4.X migration that promotes these to a `user_identity_links` table if the column count gets unwieldy. Decide at scope-lock for the first auth release.

### Test surface

Each new auth method adds:

- Unit tests (`tests/`) for token/assertion verification using reference vectors from the relevant spec (per `lessons-learned.md` — v3.19.1 S3 SigV4 lesson)
- Integration tests for happy-path login + provision flows
- Playwright spec for the auth method's UI (login page, config page)
- Negative-path tests (expired tokens, invalid signatures, missing required attributes)

Reference vectors are non-negotiable. Hand-rolling auth without spec-vector tests is exactly how v3.19.1 happened.

### Documentation

Each auth method gets its own `docs/<method>.md` (e.g. `docs/saml.md`, `docs/ldap.md`) plus a feature card on the marketing site per `marketing-site.md`. Pattern: minimal user-facing config example + IdP-side configuration snippet for the most common identity providers (Okta, Azure AD, Google Workspace).

---

## Open questions

Not blocking v4.0.0 but should be decided before later releases:

1. **Multiple auth methods enabled simultaneously** — login page UX when SAML + LDAP + OIDC + local-password are all enabled. Tabbed? Discovery? Defer until v4.4.0 when LDAP joins existing OIDC.
2. **Auth method preference per user vs per install** — does each user pick their auth method, or does the install force one? Recommend per-user with a per-install fallback for auto-discovery.
3. **i18n of auth-method-emitted error messages** — SAML/LDAP libraries emit their own English error strings. Wrap or translate at our layer. Decide before v4.3.0.
4. **Group → role mapping** — when LDAP/SAML/OAuth deliver group membership, how do groups map to IPAM roles? Hard-coded admin mapping or settings-driven? Recommend settings-driven, configurable per provider.
5. **JIT (Just-in-Time) provisioning vs SCIM** — SAML and OAuth typically support JIT user creation on first login. SCIM is push-from-IdP. Both, neither, or one? Recommend support both — JIT for first login, SCIM for ongoing lifecycle.
6. **API key authentication for SAML/LDAP-managed users** — does an SSO-managed user create local API keys, or do they go through their IdP's machine credentials? Recommend local API keys (the API contract stays simple); SSO is for human web UI auth.
7. **Session/token expiry under SSO** — when an SAML session times out, what happens to the local IPAM session? Force logout, refresh, soft expire? Recommend soft-expire with re-auth on next request, matching existing OIDC behaviour.

---

## Sequencing constraints

Hard constraints (do not violate):

- **i18n phase 1 (v4.0.0) before any non-English translation work.** No locale-switching feature without the underlying infrastructure.
- **#334 (user groups) before or with #456 (RBAC).** RBAC's permission-target should be groups, not users, from day one.
- **#417 (JWT lib swap) before SAML (v4.3.0).** SAML signing reuses JWT primitives; doing the swap first means SAML doesn't inherit hand-rolled crypto.
- **Reference-vector tests before any new signer/verifier ships.** v3.19.1 S3 SigV4 lesson; do not ship hand-rolled crypto without spec-vector tests.

Soft constraints (preferred but flexible):

- i18n and auth releases interleave (don't ship two i18n releases back-to-back; users want visible progress on both fronts).
- LDAP before OAuth (LDAP is more universally requested in enterprise; OAuth supplements OIDC which already works).

---

## Sustainability checkpoint

The v4.x stream is ~6 releases, each substantial. Mid-stream (after v4.2 or v4.3), check:

- Is community contribution materializing? (PR count, issue triage from non-maintainers)
- Has translation interest emerged? (Phase-3 first-language adoption)
- Has any commercial sponsorship/support interest surfaced?

If yes to any: continue the stream as planned.
If no to all: shorten the back half. SCIM and OAuth can defer indefinitely; LDAP and SAML are the high-impact must-ships.

---

## Cross-references

- `i18n-design.md` — full i18n design, phases, libraries.
- `v4-tenancy-design.md` — deferred multi-tenancy design (still load-bearing if MT ever revives).
- `runtime-dependency-policy.md` — six criteria every new auth library must clear.
- `adding-a-runtime-dependency.md` — proposal procedure for each library adoption.
- `auth-model.md` — current auth surface (will grow as v4.x ships).
- `audit-actions.md` — full action vocabulary; auth additions catalogued here.
- `adding-a-setting.md` — registry pattern for new auth/i18n settings.
- `lessons-learned.md` — reference-vector test rule for hand-rolled crypto.
- `cleanup.md` → Localization category — Canadian English context.
- `feedback_canadian_english.md` (auto-memory) — context for en-CA as source-of-truth locale.
