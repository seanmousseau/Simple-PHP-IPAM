# Marketing site (`simplephpipam.com`) procedure

> Everything needed to update the marketing WordPress site at `simplephpipam.com` — version bump, feature cards, adding new docs, and the cache-purge sequence. Linked from `docs/internal/release-workflow.md` step 9.
>
> **Required reading before touching `website/`.**

The marketing site is a WordPress install on OpenLiteSpeed at `root@192.168.80.23`. The theme repo is `simple-php-ipam-website` (separate from the IPAM repo) and is deployed via `rsync website/ → /usr/local/lsws/vhosts/simplephpipam.com/html/wp-content/themes/simplephpipam/`.

Doc pages are served as WordPress pages at `/docs/<slug>/`. As of the v3.28.2 SSR rollout, `page.php` server-side renders the markdown via `league/commonmark` (composer dep, vendor/ committed) before the response goes out — crawlers and social-card unfurls see real content, not an empty shell. The WP page still only needs to **exist** for the URL to resolve; its `post_content` is ignored by the SSR path. Markdown source is fetched live from `https://raw.githubusercontent.com/seanmousseau/Simple-PHP-IPAM/main/docs/<slug>.md` and cached in a WP transient (`sipam_doc_<slug>`, 24h TTL safety net, webhook-invalidated on every docs/*.md push to main). If the fetch/render fails the page falls back to the legacy JS-loader path.

Design + rationale: [`marketing-site-ssr-decision.md`](marketing-site-ssr-decision.md).

## Per-release version bump

`website/front-page.php` carries the version in **four** places — every release, all four:

1. Hero badge: `<div class="hero__badge">vX.Y.Z — <tagline></div>`
2. Hero download button label: `Download vX.Y.Z`
3. Quickstart download button label (separate occurrence — easy to miss)
4. Quickstart `tar` command: `tar -xzf ipam-X.Y.Z.tar.gz`

Plus: update or add **feature cards** for significant new features.

## Adding a new doc page

Wiring a new doc requires changes in **six places** plus a WordPress page. Missing any one causes a blank render, broken nav entry, or 404. Do all of these in one website PR:

1. **`docs/<slug>.md`** in the IPAM repo — write the doc. **Do NOT include Jekyll/YAML frontmatter** (`---\ntitle: …\n---`); the marked.js renderer on the marketing site does not strip it and it leaks as visible text at the top of the page. Existing docs (`security.md`, `installation.md`, etc.) have no frontmatter — match that convention.
2. **`website/functions.php`** — add `'<slug>' => '<slug>.md'` to `sipam_doc_md_file()`. **Without this the page renders blank** — `$rawUrl` is empty so the loader script is omitted entirely.
3. **`website/header.php`** — add `<li role="menuitem"><a href="<?php echo esc_url(home_url('/docs/<slug>/')); ?>">Label</a></li>` to the Docs nav dropdown.
4. **`website/page.php`** — add `['slug' => '<slug>', 'label' => 'Label']` to the `$docs` array (left sidebar shown on every doc page).
5. **`website/page-docs.php`** — add an `<a class="doc-card">` block to the docs index grid.
6. **Feature card link in `website/front-page.php`** — add `<a href="<?php echo esc_url(home_url('/docs/<slug>/')); ?>">Guide name</a>` inside the relevant feature card description.
7. **Create the WordPress page** via WP-CLI (Docs parent page is ID 22):
   ```bash
   ssh root@192.168.80.23 "wp --path=/usr/local/lsws/vhosts/simplephpipam.com/html --allow-root \
     post create --post_type=page --post_status=publish \
     --post_title='<Title>' --post_name='<slug>' --post_parent=22 --post_content=''"
   ```

## Deploy

**Step zero: verify the active theme.** The live site has both `simplephpipam` and `twentytwentyfive` installed; rsyncing to the inactive one produces zero observable change and looks like a deploy that "took silently". Confirm before deploying:

```bash
ssh root@192.168.80.23 "wp --path=/usr/local/lsws/vhosts/simplephpipam.com/html --allow-root theme list --status=active --fields=name"
# expected: simplephpipam
# if not, activate: wp ... theme activate simplephpipam
```

Then:

```bash
cd website/
git add -A && git commit -m "chore(release): <message>"
git push origin main
cd ..
# vendor/ is committed in the website repo (per ADR) so a single rsync ships
# everything the SSR path needs. Include vendor/** explicitly because some
# rsync wrappers/.cvsignore patterns would otherwise skip it.
rsync -az --delete \
  --include='vendor/' --include='vendor/**' \
  website/ root@192.168.80.23:/usr/local/lsws/vhosts/simplephpipam.com/html/wp-content/themes/simplephpipam/
ssh root@192.168.80.23 "chown -R nobody:nogroup /usr/local/lsws/vhosts/simplephpipam.com/html/wp-content/themes/simplephpipam/"
```

## Cache purge

`simplephpipam.com` is fronted by **QUIC.cloud CDN**, not Cloudflare. Cloudflare purge applies only to `demo.simplephpipam.com`.

Run the full sequence in order — skipping any step leaves stale state somewhere:

```bash
ssh root@192.168.80.23 "
  cd /usr/local/lsws/vhosts/simplephpipam.com/html
  # 1. Reset PHP OPcache so new PHP files load
  echo '<?php opcache_reset(); echo \"ok\";' > opcache_flush.php
  chown nobody:nogroup opcache_flush.php
  curl -sk https://simplephpipam.com/opcache_flush.php; echo
  rm opcache_flush.php
  # 2. Wipe LiteSpeed page cache directory
  rm -rf /usr/local/lsws/vhosts/simplephpipam.com/cachedata/*
  # 3. Flush WP rewrite rules (required after creating new pages — without this /docs/<slug>/ returns 404)
  wp --allow-root rewrite flush
  # 4. Flush WP object cache (the LiteSpeed Cache plugin's object-cache.php dropin
  #    caches the SSR sipam_doc_<slug> transients independently of wp_options;
  #    \`wp transient delete\` alone does NOT clear them).
  wp --allow-root cache flush
  # 5. Purge LSCache + QUIC.cloud edge in one shot (QUIC.cloud caches pre-creation 404s)
  wp --allow-root litespeed-purge all
"
```

## Verify

```bash
curl -sk -o /dev/null -w '%{http_code}\n' https://simplephpipam.com/docs/<slug>/   # expect 200
```

In a browser, hard-refresh is **not** enough — browser caching on this site is aggressive. Use **DevTools → Application → Storage → Clear site data** to bypass it.

## Webhook cache invalidation (post-SSR)

Every push to `main` that touches `docs/*.md` triggers `.github/workflows/marketing-cache-bust.yml` in the IPAM repo. The workflow POSTs the changed slugs to `https://simplephpipam.com/wp-json/sipam/v1/cache-bust` with an `X-Webhook-Secret` header validated via `hash_equals` against the WP option `sipam_marketing_webhook_secret`.

Set up (one-time):

```bash
# Generate a random secret and store it on both ends.
secret=$(openssl rand -hex 32)
ssh root@192.168.80.23 "wp --path=/usr/local/lsws/vhosts/simplephpipam.com/html --allow-root option update sipam_marketing_webhook_secret '$secret'"
gh -R seanmousseau/Simple-PHP-IPAM secret set MARKETING_WEBHOOK_SECRET --body "$secret"
```

If the secret is unset the workflow emits a `::warning::` and exits 0 (no-op) so doc PRs don't fail before rollout. The 24h transient TTL is the safety net for missed webhooks.

## LSCache JS bundling — useful to know

LSCache combines all inline `<script>` blocks into a single external `.js` bundle (e.g. `/wp-content/litespeed/js/<hash>.js`). The doc loader's `rawUrl` is in that bundle, not the HTML, so `curl … | grep raw.github` against the page HTML will return nothing. To verify the bundle for a doc page:

```bash
jsurl=$(curl -k -s "https://simplephpipam.com/docs/<slug>/?cb=$RANDOM" | grep -oE '/wp-content/litespeed/js/[a-f0-9]+\.js\?[^"]*' | head -1)
curl -k -s "https://simplephpipam.com${jsurl}" | grep -oE "raw\.githubusercontent\.com..[^\"]*<slug>\.md"
```

## Pitfalls (learned the hard way)

- **Jekyll frontmatter leaks visible.** Never include `---\ntitle: …\n---` in new docs. The SSR renderer and the JS fallback both leave it as visible text at the top of the page.
- **OPcache + cachedata + LSCache object-cache + rewrite rules + QUIC.cloud edge** are FIVE distinct cache layers. `wp litespeed-purge all` alone is not enough after a renderer change; you also need OPcache reset, cachedata wipe, **`wp cache flush` for the SSR transient via the LSCache object-cache dropin**, and rewrite-flush (only when creating new pages). Discovered the hard way during the SSR rollout — staging served stale SSR HTML for hours after a docs-ssr.php edit because the LSCache object-cache held the old transient.
- **QUIC.cloud caches 404s.** A page that returned 404 before creation will continue to 404 at the edge until `wp litespeed-purge all` runs (which also flushes the QUIC.cloud edge).
- **`rsync` working-tree includes `vendor/`** (per ADR the theme's vendor/ is committed, not gitignored). Make sure deploy rsyncs include `--include='vendor/' --include='vendor/**'`.
- **`page-docs.php` doc cards** are separate from `page.php` `$docs` sidebar array and `header.php` dropdown. All three must be updated when adding a new doc.
- **SSR renderer can be disabled** via `define('SIPAM_DOCS_SSR_ENABLED', false);` in `wp-config.php`. Use as kill switch if a doc edit ever produces broken output that can't be diagnosed quickly — `page.php` falls back to the legacy JS-loader path and the site stays up.
