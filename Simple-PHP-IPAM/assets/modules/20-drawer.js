/**
 * 20-drawer.js — global right-side drawer (IpamDrawer, #517).
 *
 * Extracted from assets/app.js (now assets/modules/_monolith.js) in v3.34.0
 * Phase 1 of the app.js modularization (#939). See
 * docs/internal/app-js-modularization-plan.md for the full rollout.
 *
 * Load order: this module is numbered `20-` so it loads BEFORE every
 * consumer module that calls IpamDrawer.open / .openNode / .close. Today's
 * consumers in _monolith.js are the command palette (Add Subnet, Add
 * Address) and the webhooks-page IIFE. The [data-drawer-tpl] /
 * [data-drawer-url] global click delegates live INSIDE this IIFE, so they
 * are wired up the moment this defer script executes — before
 * DOMContentLoaded fires elsewhere.
 *
 * The `var IpamDrawer = ...` at top-level intentionally lands on
 * window.IpamDrawer via browser script-scope var semantics, so consumer
 * modules can call it without an import/export.
 *
 * innerHTML safety: there are three innerHTML writes inside this module.
 * Each has an explanatory comment inline. All three either (1) set the
 * drawer DOM from a project-controlled literal string, (2) clone a
 * project-rendered <template>-style hidden div's innerHTML, or (3) inject
 * a same-origin admin-gated server-rendered HTML partial after an explicit
 * origin check. No untrusted input crosses any of those paths.
 */
/* global IpamDrawer — right-side drawer (#517) */
/* —— extracted verbatim from assets/app.js in v3.34.0 #939 Phase 1 —— */
var IpamDrawer = (function () {
    var drawer, overlay, titleEl, bodyEl, _lastFocus;

    function _getFocusable() {
        return Array.prototype.slice.call(
            drawer.querySelectorAll(
                'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])'
            )
        );
    }

    function _trapFocus(e) {
        if (e.key !== 'Tab') return;
        var focusable = _getFocusable();
        if (!focusable.length) { e.preventDefault(); return; }
        var first = focusable[0];
        var last  = focusable[focusable.length - 1];
        if (e.shiftKey) {
            if (document.activeElement === first) { e.preventDefault(); last.focus(); }
        } else {
            if (document.activeElement === last)  { e.preventDefault(); first.focus(); }
        }
    }

    function _init() {
        if (drawer) return;
        drawer = document.createElement('div');
        drawer.id = 'global-drawer';
        drawer.className = 'drawer';
        drawer.setAttribute('role', 'dialog');
        drawer.setAttribute('aria-modal', 'true');
        drawer.setAttribute('aria-hidden', 'true');
        drawer.setAttribute('aria-labelledby', 'global-drawer-title');
        drawer.setAttribute('tabindex', '-1');
        drawer.innerHTML =
            '<div class="drawer-header">' +
            '<span class="drawer-title" id="global-drawer-title"></span>' +
            '<button class="drawer-close" id="global-drawer-close" aria-label="Close drawer">' +
            '<svg class="icon" aria-hidden="true" focusable="false"><use href="#icon-x"></use></svg>' +
            '</button>' +
            '</div>' +
            '<div class="drawer-body" id="global-drawer-body"></div>';
        document.body.appendChild(drawer);

        overlay = document.createElement('div');
        overlay.className = 'drawer-overlay';
        document.body.appendChild(overlay);

        titleEl = document.getElementById('global-drawer-title');
        bodyEl  = document.getElementById('global-drawer-body');

        document.getElementById('global-drawer-close').addEventListener('click', close);
        overlay.addEventListener('click', close);

        document.addEventListener('keydown', function (e) {
            if (!drawer.classList.contains('is-open')) return;
            if (e.key === 'Escape') { close(); return; }
            _trapFocus(e);
        });
    }

    function open(title, tplId) {
        _init();
        _lastFocus = document.activeElement;
        var tpl = document.getElementById(tplId);
        bodyEl.innerHTML = tpl ? tpl.innerHTML : '';
        titleEl.textContent = title;
        overlay.classList.add('is-visible');
        drawer.classList.add('is-open');
        drawer.setAttribute('aria-hidden', 'false');
        var focusable = _getFocusable();
        if (focusable.length) focusable[0].focus();
        else drawer.focus();
    }

    // openNode — like open(), but accepts a DOM node and MOVES it (rather
    // than copying innerHTML). Use when the content has JS-attached
    // behaviour that must survive the drawer open: bound event listeners,
    // imperatively populated values, references held in closures (e.g. the
    // subnets edit drawer's tag select, custom-field inputs, and typeahead
    // pickers). innerHTML cloning would lose all of that. Caller may pass
    // either an Element or an id string. The node is set non-hidden after
    // it lands in the drawer; previous body content is discarded.
    function openNode(title, nodeOrId) {
        _init();
        var node = (typeof nodeOrId === 'string') ? document.getElementById(nodeOrId) : nodeOrId;
        if (!node) return;
        _lastFocus = document.activeElement;
        while (bodyEl.firstChild) bodyEl.removeChild(bodyEl.firstChild);
        node.hidden = false;
        bodyEl.appendChild(node);
        titleEl.textContent = title;
        overlay.classList.add('is-visible');
        drawer.classList.add('is-open');
        drawer.setAttribute('aria-hidden', 'false');
        var focusable = _getFocusable();
        if (focusable.length) focusable[0].focus();
        else drawer.focus();
    }

    function close() {
        if (!drawer) return;
        drawer.classList.remove('is-open');
        overlay.classList.remove('is-visible');
        drawer.setAttribute('aria-hidden', 'true');
        if (_lastFocus && _lastFocus.focus) _lastFocus.focus();
    }

    // Event delegation — handle [data-drawer-tpl] buttons (CSP-safe, no onclick).
    // Match on data-drawer-tpl (the template-id content source), NOT on
    // data-drawer-title: data-drawer-title is just a label and may appear on
    // any element. Historical note (#1243): a second slide-in form-drawer
    // (#247) was retired in v3.34.0; before that, data-drawer-title could
    // appear on triggers belonging to either drawer, and matching on it
    // would have double-opened.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('[data-drawer-tpl]') : null;
        if (!btn) return;
        // [data-drawer-url] elements have their own delegate below; don't double-handle.
        if (btn.hasAttribute('data-drawer-url')) return;
        e.preventDefault();
        open(
            btn.getAttribute('data-drawer-title') || '',
            btn.getAttribute('data-drawer-tpl')   || ''
        );
    });

    // Event delegation — handle [data-drawer-url] elements (#803).
    // Loads an HTML partial via fetch and injects it into the drawer body.
    //
    // Trust model: the partial is fetched from a same-origin admin-gated
    // endpoint (require_role('admin')) that emits server-rendered HTML with
    // every user-controlled value passed through e()/htmlspecialchars. This
    // matches the trust model of the existing template-id path above
    // (bodyEl.innerHTML = tpl.innerHTML). innerHTML is safe here because
    // (a) the source is our own server, (b) admins are already trusted,
    // (c) <script> tags injected via innerHTML do not execute in modern
    // browsers per the HTML spec.
    document.addEventListener('click', function (e) {
        var trigger = e.target.closest ? e.target.closest('[data-drawer-url]') : null;
        if (!trigger) return;
        if (e.target.closest && e.target.closest('#global-drawer')) return;  // already-open drawer
        e.preventDefault();
        _openFromUrl(trigger);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        var trigger = e.target.closest ? e.target.closest('[data-drawer-url]') : null;
        if (!trigger) return;
        var tag = (e.target.tagName || '').toLowerCase();
        if (tag === 'input' || tag === 'textarea' || tag === 'select' || tag === 'button') return;
        e.preventDefault();
        _openFromUrl(trigger);
    });

    var _drawerRequestSeq = 0;

    function _openFromUrl(trigger) {
        var url   = trigger.getAttribute('data-drawer-url');
        var title = trigger.getAttribute('data-drawer-title') || 'Details';
        if (!url) return;
        // CR feedback PR #1054: same-origin guard before injecting via innerHTML.
        // Comment promises "trusted same-origin partial" — enforce it here so
        // a future trigger with an absolute or user-influenced URL can't break
        // the contract and turn this into a DOM-XSS sink.
        try {
            var resolved = new URL(url, window.location.href);
            if (resolved.origin !== window.location.origin) return;
            url = resolved.toString();
        } catch (_e) {
            return;
        }
        open(title, '');
        var bodyEl = document.getElementById('global-drawer-body');
        if (!bodyEl) return;
        // Loading state — fully DOM-constructed, no untrusted input involved.
        while (bodyEl.firstChild) bodyEl.removeChild(bodyEl.firstChild);
        var loading = document.createElement('p');
        loading.className = 'muted';
        loading.textContent = 'Loading…';
        bodyEl.appendChild(loading);
        // CR feedback PR #1054: ignore stale fetches. Two rapid clicks (run A
        // then run B) could otherwise let A's slower response overwrite B's
        // already-rendered body, leaving the title and Verify/Delete form
        // out of sync. Each call increments the seq; only the latest renders.
        var requestSeq = ++_drawerRequestSeq;
        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.text();
            })
            .then(function (html) {
                if (requestSeq !== _drawerRequestSeq) return;
                bodyEl.innerHTML = html;  // trusted same-origin partial (admin-gated, server-rendered); origin-checked above
                // Notify rebindable widgets that fresh DOM was injected so they
                // can re-attach event listeners (e.g. schedule-form freq gating).
                var ev = new CustomEvent('drawer:loaded', { detail: { drawer: drawer, body: bodyEl } });
                document.dispatchEvent(ev);
            })
            .catch(function (err) {
                if (requestSeq !== _drawerRequestSeq) return;
                // Error state — build via DOM, do not interpolate err.message into HTML.
                while (bodyEl.firstChild) bodyEl.removeChild(bodyEl.firstChild);
                var box = document.createElement('div');
                box.className = 'drawer-error';
                box.setAttribute('role', 'alert');
                var p = document.createElement('p');
                p.textContent = 'Could not load details: ' + ((err && err.message) ? err.message : 'unknown error');
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'action-pill';
                btn.id = 'drawer-retry';
                btn.textContent = 'Retry';
                btn.addEventListener('click', function () { _openFromUrl(trigger); });
                box.appendChild(p);
                box.appendChild(btn);
                bodyEl.appendChild(box);
            });
    }

    return { open: open, openNode: openNode, close: close };
}());
