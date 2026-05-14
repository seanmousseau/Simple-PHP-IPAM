# Marketing site — server-side docs rendering decision (ADR)

> **Status:** Proposed (not implemented). Reserved for a focused implementation session, not a side-quest during release work.
>
> **Date:** 2026-05-14.
>
> **Scope:** `simplephpipam.com` (the WordPress marketing site). Does **not** affect the IPAM app itself.
>
> **Companion docs:** `docs/internal/marketing-site.md` (current procedure), this file (the future design).

---

## Context

The marketing site at `simplephpipam.com` serves doc pages at `/docs/<slug>/` from a custom WordPress theme. The current implementation, documented in `marketing-site.md`:

- The WordPress page only **exists** as a placeholder for URL routing.
- Actual content is fetched **client-side** at runtime: `page.php` emits a `<script>` that does `fetch('https://raw.githubusercontent.com/seanmousseau/Simple-PHP-IPAM/main/docs/<slug>.md')` and renders the markdown via `marked.js`, with `Prism.js` doing syntax highlighting.

This is fine for browsing humans on fast connections. It is **bad for SEO** because:

1. Search-engine crawlers fetch the HTML, see an empty content shell, and have no doc text to index. The pages exist in the sitemap but rank for nothing.
2. Social-card previews (OpenGraph, Twitter card, Slack/Discord unfurl) hit the same empty shell — no excerpt, no preview image keyed off content.
3. Initial render is blocked on a cross-origin fetch + a second-party JS bundle parse. Lighthouse SEO score takes a hit, and Core Web Vitals (LCP in particular) are worse than necessary.
4. AI assistants that index the public web (ChatGPT, Claude.ai web, Perplexity) see the same empty shell. As of 2026 this is non-trivial — operator-research-via-LLM is a real discovery path for self-hosted infrastructure tools.

The fix is server-side rendering: render the markdown to HTML in `page.php` before the response goes out, cache the result, and emit a fully-populated page to crawlers and browsers alike.

**Why this isn't shipped now:** v3.28.1 just went out at midnight after a long session. The repo's expensive failures (v2.2.1 data wipe, v3.27.8 silent-fail cron, the auto-generated-vault-key footgun chain that took three releases to fully untangle) all share a shape — "while we're here" changes made when context was thinner than it felt. This ADR locks the design while the context is fresh and reserves the implementation for a focused session.

---

## Decision

Implement server-side markdown rendering with the following stack:

### 1. Markdown renderer: `league/commonmark` (NOT Parsedown)

**Choice:** `league/commonmark` v2.x via Composer.

**Why not Parsedown:**
- Last meaningful Parsedown release was 2019. Effectively unmaintained.
- Parsedown has shipped reflected-XSS CVEs (CVE-2022-23252 and earlier) that were patched only because of community pressure, not active maintenance.
- Parsedown's "Extra" extension for tables/footnotes is a separate unmaintained package.
- CommonMark is the actual specification; Parsedown's heuristics drift from spec in edge cases.

**Why CommonMark:**
- Maintained by The PHP League (the people behind Flysystem, OAuth2 Server, etc.). Active monthly releases.
- First-party `league/commonmark-ext-github-flavored-markdown` (already part of v2.x as `GithubFlavoredMarkdownConverter`) gives us tables, task lists, autolinks, strikethrough — all of which appear in `docs/*.md`.
- AST-based design means we can write custom extensions cleanly if needed later (e.g. resolving relative links to other doc pages, or generating per-page TOC).
- Composer-installable; standard PHP infrastructure.

Picking Parsedown today would be a quick-win choice we'd revisit in 18 months, exactly the pattern this ADR is designed to avoid.

### 2. Composer-managed in the theme

**Choice:** add `composer.json` to the website theme. Run `composer install --no-dev --optimize-autoloader` on the deploy host (or commit the `vendor/` tree — see "Open questions" below).

**Why:**
- The theme has no `vendor/` today. Establishing the convention now means future PHP dependencies (a structured-data generator, a feed builder, anything else) land cleanly without an architectural step change.
- The rsync deploy step in `marketing-site.md` already calls out the gotcha that `vendor/` is gitignored by default; we update that procedure to explicitly include the theme's vendor tree.

**Procedure update:** the rsync line in `marketing-site.md` Deploy section becomes:

```bash
rsync -az --delete \
  --include='vendor/' --include='vendor/**' \
  website/ root@192.168.80.23:/usr/local/lsws/vhosts/simplephpipam.com/html/wp-content/themes/simplephpipam/
```

### 3. Server-side syntax highlighting: `scrivo/highlight.php`

**Choice:** `scrivo/highlight.php` v9.x (PHP port of `highlight.js`).

**Why server-side:**
- The whole point of SSR is rendered HTML reaching the crawler. Leaving code blocks as `<pre><code class="language-x">` and patching them up client-side defeats half the SEO purpose — search engines see un-highlighted, structurally-poor code blocks. Worse, they see `<pre>` content that may include `> ` shell prompts being treated as quoted text.
- `scrivo/highlight.php` is a 1:1 port of `highlight.js`, supports 180+ languages, MIT-licensed, actively maintained.
- Output is identical to what `highlight.js` produces, so existing Prism/highlight.js CSS in the theme works unchanged.
- We can drop the client-side highlighter entirely once SSR rolls out — eliminates ~50 KB of JS from the doc pages.

**Alternative considered:** `phpolar/php-highlight` (smaller). Rejected because the language set is narrower and we use bash, php, json, sh, yaml, dockerfile, sql, diff, ini, http, javascript across the docs — `scrivo/highlight.php` covers all of these out of the box.

### 4. Webhook-driven cache invalidation (NOT TTL)

**Choice:** GitHub Actions workflow on `paths: docs/**` POSTs to a custom WP REST endpoint, which deletes the affected transients.

**Why webhook over TTL:**
- TTL feels easier but creates a permanent "is the site stale right now?" question that compounds over time. A 6-hour TTL means a typo-fix doc PR is invisible to users for 6 hours, which is irritating; a 24-hour TTL is worse; a 5-minute TTL burns the cache benefit.
- Webhook is the *right shape* and sets up cleanly for future cache-busting needs (front-page feature cards pulling from a release manifest, version-bump auto-sync, etc.).
- Operational complexity is small: one GH Action workflow file, one WP REST endpoint, one shared secret in GitHub Secrets + WP options.

**Sketch:**

```yaml
# .github/workflows/marketing-cache-bust.yml (in the IPAM repo)
on:
  push:
    branches: [main]
    paths: ['docs/**']
jobs:
  bust:
    runs-on: ubuntu-latest
    steps:
      - name: Notify marketing site
        env:
          MARKETING_WEBHOOK_SECRET: ${{ secrets.MARKETING_WEBHOOK_SECRET }}
        run: |
          slugs=$(git diff --name-only ${{ github.event.before }}..${{ github.sha }} -- 'docs/*.md' | xargs -n1 basename | sed 's/\.md$//' | jq -R . | jq -s .)
          curl -sS -X POST https://simplephpipam.com/wp-json/sipam/v1/cache-bust \
            -H "X-Webhook-Secret: $MARKETING_WEBHOOK_SECRET" \
            -H "Content-Type: application/json" \
            -d "{\"slugs\": $slugs}"
```

WP endpoint (in `functions.php`):

```php
add_action('rest_api_init', function () {
    register_rest_route('sipam/v1', '/cache-bust', [
        'methods'             => 'POST',
        'permission_callback' => function (WP_REST_Request $req) {
            $secret = $req->get_header('x-webhook-secret');
            $expected = get_option('sipam_marketing_webhook_secret', '');
            return $secret !== '' && $expected !== '' && hash_equals($expected, $secret);
        },
        'callback' => function (WP_REST_Request $req) {
            $slugs = (array) $req->get_param('slugs');
            foreach ($slugs as $slug) {
                if (preg_match('/^[a-z0-9-]+$/', $slug)) {
                    delete_transient('sipam_doc_' . $slug);
                }
            }
            return ['ok' => true, 'busted' => $slugs];
        },
    ]);
});
```

**Fallback:** a "purge all" admin-only button in WP admin for the manual escape hatch. Operationally cheap to add and useful for "I deleted the webhook by accident" recovery.

### 5. ETag-aware fetch with `wp_remote_get`

**Choice:** always send `If-None-Match`. On `304 Not Modified`, refresh the transient's TTL without rebuilding the HTML. On `200`, render + cache the new ETag alongside the rendered HTML.

**Why:**
- GitHub's `raw.githubusercontent.com` honours `If-None-Match` and `If-Modified-Since`.
- Cheap to add now while we're touching the fetch path; expensive to retrofit later when we discover we're burning rate-limit budget or seeing fetch latency we didn't anticipate.

### 6. Keep the JS loader as a runtime fallback (defense in depth)

**Choice:** if `wp_remote_get` returns an error (timeout, network failure, GitHub outage), `page.php` emits the existing client-side loader script instead of an empty page.

**Why not "remove the old code":**
- Marketing-site availability matters during a release announcement — exactly when GitHub is under load. A fetch failure should degrade to "JS-rendered content" not "blank page."
- Costs ~5 lines of `<noscript>` + a feature-flag check. Negligible.
- Sets the precedent that SSR is an optimization, not a single point of failure.

**Feature flag:** `define('SIPAM_DOCS_SSR_ENABLED', true)` in `wp-config.php` (or a WP option). Lets us roll the SSR path out behind a flag and turn it off with a one-line change if something misbehaves in production.

### 7. Memory MCP decision entity + procedure-doc update

**Choice:** create `decision:wordpress-server-side-docs` in Memory MCP, and update `docs/internal/marketing-site.md` to point at this ADR + reflect the new procedure.

**Why:**
- The pattern of "Composer in the marketing theme" will look weird to a future contributor without the rationale. Putting it in Memory MCP and the procedure doc means the question "why is there a `vendor/` in this WordPress theme?" gets answered without spelunking through git history.
- The phpstan-baseline-orphan lesson from v3.28.1 (`lessons-learned.md § 6.5`) was exactly this shape — a non-obvious choice that needs a written rationale. Bake that habit in now.

---

## Consequences

### Positive

- Doc pages become indexable. Expect search-engine indexing within the standard re-crawl cycle (days to weeks) once SSR is live.
- LCP improves measurably on doc pages (no fetch-then-render delay).
- Social-card unfurls show real content excerpts.
- LLM-based discovery tools see real content instead of "Loading…" placeholders.
- ~50 KB of JS removed from doc pages once the client-side renderer is no longer the primary path.

### Negative

- New PHP dependency surface (`league/commonmark`, `scrivo/highlight.php`, plus transitive deps). All MIT-licensed; both maintainers are reputable. Still worth a `composer audit` in CI on the website repo.
- The marketing-site deploy procedure gets a step more complex (run `composer install` if vendor/ isn't checked in; verify the rsync includes vendor/).
- Cache invalidation now has an external dependency (GitHub webhook reaching WP). When the webhook fails silently, content can go stale until the manual purge button is used. Mitigation: a daily cron-style purge as a safety net (low TTL on the safety-net purge, NOT on the normal cache path).

### Neutral

- The JS loader stays around. If a future contributor wants to fully delete it, that's a separate decision.

---

## Open questions (resolve at implementation start)

These don't need to be resolved tonight, but the implementer should pick a stance on each before opening the PR:

1. **Commit `vendor/` or run `composer install` on deploy?**
   - Commit: deploy is one `rsync` step, no PHP needed on the deploy host. Adds ~3-5 MB to the repo.
   - Build: smaller repo, but adds a `composer install` step to the deploy procedure and requires PHP + Composer on the deploy host (already true — the host runs WordPress).
   - **Lean:** build (run `composer install --no-dev` on the host). The deploy host has PHP 8.4; the dep tree is small; runtime composer install is the standard convention.

2. **What's the TTL on the safety-net purge?**
   - Bare-bones answer: 24h. A doc edit that misses the webhook is at most one day stale.
   - **Lean:** 24h, configurable via WP option.

3. **Inline CSS for highlight.php or shared stylesheet?**
   - `highlight.php` doesn't ship CSS — it emits `<span class="hljs-…">` markup that needs styling from a `highlight.js` theme stylesheet.
   - The theme currently uses (presumably) Prism's CSS. We'd swap to a highlight.js theme stylesheet. Choosing one that matches the existing visual style is a 30-minute task.
   - **Lean:** use the `atom-one-dark.css` highlight.js theme (closest to the current site dark aesthetic).

4. **Do we generate a TOC server-side or keep that JS-side?**
   - Currently I believe a JS post-processor builds the floating TOC. Server-side generation is straightforward with CommonMark's `HeadingPermalinkExtension` + a custom walker.
   - **Lean:** ship SSR for content first, TOC port is a follow-up PR.

5. **`raw.githubusercontent.com` rate limits?**
   - Unauthenticated fetches are 60/hour per IP. The webhook flow guarantees we only fetch on cache miss → only on `docs/*.md` push, so 60/hour is plenty.
   - If we ever want to refresh independently of pushes (e.g. cron warm-up), authenticated fetches go to 5000/hour. Not needed for v1.
   - **Lean:** no auth; revisit if we ever see 429s.

---

## Implementation plan (sketch)

1. **Spike:** new branch `feature/marketing-ssr` in the **website** repo. Add `composer.json` with the three deps. Add a `lib/docs-ssr.php` that exposes `sipam_render_doc(string $slug): ?string` (returns HTML or null on fetch failure). Unit-testable in isolation against fixtures in `lib/tests/`. ~2-3 hours.
2. **Integrate:** wire `page.php` to call `sipam_render_doc()`; on null, fall back to existing JS loader. Feature-flag the SSR path. ~1 hour.
3. **Cache layer:** transient get/set, ETag plumbing. ~1 hour.
4. **Webhook:** WP REST endpoint + GH Action workflow file + shared secret setup. ~1.5 hours.
5. **Highlight.php CSS:** swap Prism CSS for an `atom-one-dark` highlight.js theme. ~30 min.
6. **Test:** local WP dev environment (Docker? Pre-existing harness?) — verify a known doc page renders identically to the JS-rendered version. Side-by-side diff at the HTML level. ~1.5 hours.
7. **Deploy behind flag:** rsync + cache purge. Flip flag for `installation.md` only. Verify with `curl https://simplephpipam.com/docs/installation/ | grep -c '<h2'` returning >0. ~30 min.
8. **Roll out:** flip flag globally. Watch Search Console + Lighthouse over the next 7 days.
9. **Memory MCP + procedure doc:** create the decision entity, update `marketing-site.md`. ~30 min.

**Total estimate:** ~8-9 hours (1 focused day with breaks).

---

## When to revisit this ADR

- If `league/commonmark` v3 ships with a breaking API change → re-pin the dep.
- If WordPress block themes (FSE) become relevant for this site → the rendering integration point moves and the ADR needs an addendum on where SSR hooks in under FSE.
- If we move off WordPress entirely (Hugo / static site generator) → archive this ADR; the SSR concern goes away.
- If we add a search feature to the docs → bring in `meilisearch` or similar; this ADR's CommonMark AST is the right input to feed it. Document the search index build path here.

---

## Related

- `docs/internal/marketing-site.md` — current marketing-site procedure (to be updated as part of implementation).
- `docs/internal/lessons-learned.md § 6.5` — phpstan-baseline-orphan lesson from v3.28.1 #1177; same shape of "make the non-obvious choice obvious in a written doc" pattern.
- Memory MCP: `decision:wordpress-server-side-docs` (to be created at implementation start).
