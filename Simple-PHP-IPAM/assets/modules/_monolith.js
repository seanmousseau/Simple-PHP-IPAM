// ─── End of Phase 2a (C01-C18 + sub-concerns). Concerns below this line are ──
// trailing standalone IIFEs that pre-existed Phase 2a (already self-contained)
// and will be moved to assets/modules/<NN>-<name>.js in Phase 2b / Phase 3.

// backups.php modal handlers — CSP-safe event delegation on data-action attributes
(function () {
  var page = document.getElementById("backups-page");
  if (!page) return;

  function openModal(id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.style.display = "flex";
    el.addEventListener("click", function onBg(e) {
      if (e.target === el) { closeModal(id); el.removeEventListener("click", onBg); }
    });
    var first = el.querySelector("button, [href], [tabindex]");
    if (first) first.focus();
  }

  function closeModal(id) {
    var el = document.getElementById(id);
    if (el) el.style.display = "none";
  }

  document.addEventListener("click", function (e) {
    var btn = e.target.closest("[data-action]");
    if (!btn) return;
    var action = btn.getAttribute("data-action");

    if (action === "restore-info") {
      var phpPath = page.getAttribute("data-restore-script") || "/path/to/Simple-PHP-IPAM/restore.php";
      var rawArg  = btn.getAttribute("data-path") || btn.getAttribute("data-filename") || "<path/to/backup>";
      var fileArg = "'" + rawArg.replace(/'/g, "'\\''") + "'";
      var dry  = document.getElementById("restore-cmd-dry");
      var apply = document.getElementById("restore-cmd-apply");
      if (dry)   dry.textContent   = "php " + phpPath + " --from=" + fileArg + " --dry-run";
      if (apply) apply.textContent = "php " + phpPath + " --from=" + fileArg + " --force";
      openModal("restore-modal");
    } else if (action === "backup-delete") {
      var idEl = document.getElementById("delete-id");
      var bodyEl = document.getElementById("delete-modal-body");
      if (idEl) idEl.value = btn.getAttribute("data-id") || "";
      if (bodyEl) bodyEl.textContent =
        'Delete backup record and file "' + (btn.getAttribute("data-filename") || "") + '"? This cannot be undone.';
      openModal("delete-modal");
    } else if (action === "close-modal") {
      closeModal(btn.getAttribute("data-target") || "");
    }
  });

  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") {
      closeModal("restore-modal");
      closeModal("delete-modal");
    }
  });
})();

// TOTP enrollment QR code — runs after qrcode.min.js is loaded inline in the view
(function () {
  var qrEl = document.getElementById("totp-qr");
  if (!qrEl || typeof QRCode === "undefined") return;
  var uri = qrEl.getAttribute("data-uri");
  if (!uri) return;
  new QRCode(qrEl, {
    text: uri,
    width: 200,
    height: 200,
    colorDark: "#000000",
    colorLight: "#ffffff",
    correctLevel: QRCode.CorrectLevel.M
  });
})();

// uPlot dashboard growth chart
(function () {
  var el = document.getElementById('growth-chart');
  if (!el || typeof uPlot === 'undefined') return;

  var xs, ys;
  try {
    xs = JSON.parse(el.getAttribute('data-uplot-xs') || '[]');
    ys = JSON.parse(el.getAttribute('data-uplot-ys') || '[]');
  } catch (e) { return; }
  if (!xs.length) return;

  var hasData = ys.some(function(v) { return v !== 0; });
  if (!hasData) {
    /* All content below is hardcoded literal HTML — no user data, no XSS risk */
    var wrap  = document.createElement('div');
    wrap.className = 'chart-empty';
    var svgIcon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svgIcon.setAttribute('class', 'icon');
    svgIcon.setAttribute('aria-hidden', 'true');
    var useEl = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    useEl.setAttribute('href', 'assets/icons.svg#icon-reports');
    svgIcon.appendChild(useEl);
    var msg = document.createElement('p');
    msg.className = 'chart-empty__msg';
    msg.textContent = 'No address activity in the past 30 days';
    var cta = document.createElement('a');
    cta.className = 'chart-empty__cta';
    cta.href = 'subnets.php';
    cta.textContent = 'Go to Subnets';
    wrap.appendChild(svgIcon);
    wrap.appendChild(msg);
    wrap.appendChild(cta);
    el.appendChild(wrap);
    return;
  }

  var style  = getComputedStyle(document.documentElement);
  var stroke = (style.getPropertyValue('--link') || '#0077cc').trim();
  var fill   = stroke + '22';
  var muted  = (style.getPropertyValue('--muted') || '#6c757d').trim();

  var opts = {
    width:  640,
    height: 180,
    cursor: { drag: { x: false, y: false } },
    select: { show: false },
    legend: { show: false },
    series: [
      {},
      {
        label: 'New addresses',
        stroke: stroke,
        fill:   fill,
        width:  2
      }
    ],
    axes: [
      { gap: 8, size: 28, stroke: muted, ticks: { stroke: muted } },
      {
        gap: 8, size: 40, stroke: muted, ticks: { stroke: muted },
        values: function (_u, vals) {
          return vals.map(function (v) { return v == null ? '' : String(Math.round(v)); });
        }
      }
    ],
    scales: { x: { time: true } }
  };

  var u = new uPlot(opts, [xs, ys], el);

  // Hide stale cursor lines/points after mouse leaves the chart (#648)
  el.addEventListener('mouseleave', function () {
    u.over.dispatchEvent(new MouseEvent('mousemove', { bubbles: false, clientX: -9999, clientY: -9999 }));
  });

  function resizeChart() {
    var w = el.offsetWidth;
    if (w > 0) u.setSize({ width: w, height: 180 });
  }

  requestAnimationFrame(resizeChart);
  window.addEventListener('resize', resizeChart);
  document.addEventListener('ipam:sidebar-toggle', resizeChart);
}());

// Backup History drawer action handlers (#803).
// Verify and Delete are exposed inside the drawer body partial as
// <button data-action="verify|delete">. Download is a submit button bound
// via form="backup-run-download" to a sibling POST form (CSRF + dest_id + name).
(function () {
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('#backup-run-actions [data-action]') : null;
        if (!btn || btn.disabled) return;
        var action = btn.getAttribute('data-action');
        if (action !== 'verify' && action !== 'delete') return;
        e.preventDefault();
        var form = btn.closest('#backup-run-actions');
        if (!form) return;
        var runId = form.getAttribute('data-run-id');
        var csrfInput = form.querySelector('input[name=csrf]');
        var csrf = csrfInput ? csrfInput.value : '';
        if (!runId || !csrf) return;
        if (action === 'verify') _backupRunVerify(form, runId, csrf);
        else                     _backupRunDeletePromptThenSubmit(form, runId, csrf);
    });

    function _backupRunVerify(form, runId, csrf) {
        var resultEl = form.querySelector('#drawer-action-result');
        if (!resultEl) return;
        resultEl.hidden = false;
        resultEl.className = 'drawer-action-result';
        resultEl.textContent = 'Verifying…';
        var fd = new FormData();
        fd.append('csrf', csrf);
        fd.append('action', 'verify');
        fd.append('id', runId);
        fetch('backup_admin.php?tab=history', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json().then(function (j) { return [r.status, j]; }); })
            .then(function (pair) {
                var j = pair[1];
                if (j.ok) {
                    resultEl.classList.add('is-ok');
                    resultEl.textContent = 'Verified — sha256 matches (' + (j.actual || '').slice(0, 12) + '…).';
                } else if (j.expected && j.actual) {
                    resultEl.classList.add('is-error');
                    resultEl.textContent = 'Checksum mismatch — recorded ' + j.expected.slice(0, 12) + '… vs destination ' + j.actual.slice(0, 12) + '…';
                } else {
                    resultEl.classList.add('is-error');
                    resultEl.textContent = 'Verify failed: ' + (j.message || j.error || 'unknown');
                }
            })
            .catch(function (err) {
                resultEl.classList.add('is-error');
                resultEl.textContent = 'Verify request failed: ' + (err && err.message ? err.message : 'network error');
            });
    }

    function _backupRunDeletePromptThenSubmit(form, runId, csrf) {
        if (form.querySelector('.drawer-danger')) return;  // already prompting
        var danger = document.createElement('div');
        danger.className = 'drawer-danger';

        var label = document.createElement('label');
        label.textContent = 'Type DELETE to confirm. This removes the file at the destination AND the history row. This cannot be undone.';

        var input = document.createElement('input');
        input.type = 'text';
        input.id = 'drawer-delete-confirm';
        input.autocomplete = 'off';

        var btnRow = document.createElement('div');
        btnRow.style.marginTop = '0.5rem';
        btnRow.style.display = 'flex';
        btnRow.style.gap = '0.5rem';
        btnRow.style.justifyContent = 'flex-end';

        var cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'action-pill button-secondary';
        cancel.id = 'drawer-delete-cancel';
        cancel.textContent = 'Cancel';

        var arm = document.createElement('button');
        arm.type = 'button';
        arm.className = 'action-pill button-danger';
        arm.id = 'drawer-delete-arm';
        arm.textContent = 'Delete';
        arm.disabled = true;

        btnRow.appendChild(cancel);
        btnRow.appendChild(arm);
        danger.appendChild(label);
        danger.appendChild(input);
        danger.appendChild(btnRow);
        form.appendChild(danger);
        input.focus();

        input.addEventListener('input', function () { arm.disabled = (input.value !== 'DELETE'); });
        cancel.addEventListener('click', function () { danger.remove(); });
        arm.addEventListener('click', function () {
            // CR feedback PR #1054: lock destructive controls before fetch.
            // The remote-delete path is non-idempotent (DELETE on the storage
            // backend); a fast double-click could fire two requests, the
            // second of which fails because the artifact is already gone, and
            // the user sees a confusing error after the first delete already
            // succeeded. Disable arm + cancel for the in-flight window; only
            // re-enable on failure paths.
            arm.disabled    = true;
            cancel.disabled = true;
            var resultEl = form.querySelector('#drawer-action-result');
            if (resultEl) {
                resultEl.hidden = false;
                resultEl.className = 'drawer-action-result';
                resultEl.textContent = 'Deleting…';
            }
            var fd = new FormData();
            fd.append('csrf', csrf);
            fd.append('action', 'delete');
            fd.append('id', runId);
            fd.append('confirm', 'DELETE');
            fetch('backup_admin.php?tab=history', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json().then(function (j) { return [r.status, j]; }); })
                .then(function (pair) {
                    var status = pair[0], j = pair[1];
                    if (j.ok) {
                        if (resultEl) {
                            resultEl.classList.add('is-ok');
                            resultEl.textContent = 'Deleted.';
                        }
                        var row = document.querySelector('.history-row[data-run-id="' + runId + '"]');
                        if (row && row.parentNode) row.parentNode.removeChild(row);
                        setTimeout(function () { if (window.IpamDrawer) IpamDrawer.close(); }, 600);
                    } else {
                        if (resultEl) {
                            resultEl.classList.add('is-error');
                            resultEl.textContent = 'Delete failed (' + status + '): ' + (j.message || j.error || 'unknown');
                        }
                        // Unlock for retry once destination is reachable.
                        cancel.disabled = false;
                        arm.disabled    = (input.value !== 'DELETE');
                    }
                })
                .catch(function (err) {
                    if (resultEl) {
                        resultEl.classList.add('is-error');
                        resultEl.textContent = 'Delete request failed: ' + (err && err.message ? err.message : 'network error');
                    }
                    cancel.disabled = false;
                    arm.disabled    = (input.value !== 'DELETE');
                });
        });
    }
}());

// ─── Site filter strip (#629) ─────────────────────────────────────────────────
// Pill-based client-side filter for subnets.php.
// Filter state is stored in sessionStorage so a page reload restores the last
// selection, but navigating away or opening a new tab starts fresh.
(function () {
    var strip = document.getElementById("site-filter-strip");
    if (!strip) return;

    var STORAGE_KEY = "ipam_subnet_site_filter";

    // Restore persisted filter
    var saved = "";
    try { saved = sessionStorage.getItem(STORAGE_KEY) || ""; } catch (e) { saved = ""; }

    // Collect all subnet-node elements (top-level only; children inherit via parent hiding)
    // We show/hide the outermost .subnet-node for each root by checking data-site-id.
    // For nested nodes we must also hide when the root is filtered.

    function getActiveSiteIds(filterVal) {
        // Returns a Set of numeric site IDs that should be SHOWN, or null for "all".
        if (!filterVal || filterVal === "all") return null;
        if (filterVal.indexOf("region:") === 0) {
            // All child site IDs of this region are embedded as data-filter-site on the child pills
            var regionId = filterVal.split(":")[1];
            var region = strip.querySelector("[data-region-id='" + regionId + "']");
            if (!region) return null;
            var ids = new Set();
            region.querySelectorAll(".site-filter-pill--child[data-filter-site]").forEach(function (pill) {
                var v = pill.dataset.filterSite;
                if (v && v !== "all" && v.indexOf("region:") < 0) ids.add(parseInt(v, 10));
            });
            // also include the region's own site ID if it has self_used subnets (child pill "(all)" uses plain int)
            // Those are already captured above since they use an integer data-filter-site.
            return ids.size > 0 ? ids : null;
        }
        var n = parseInt(filterVal, 10);
        if (isNaN(n) || n <= 0) return null;
        return new Set([n]);
    }

    function applyFilter(filterVal) {
        var activeSiteIds = getActiveSiteIds(filterVal);
        var isAll = (activeSiteIds === null);

        // Update pill aria-pressed states
        strip.querySelectorAll(".site-filter-pill").forEach(function (pill) {
            var pv = pill.dataset.filterSite || "";
            var active = (isAll && pv === "all") || (!isAll && pv === filterVal);
            pill.setAttribute("aria-pressed", active ? "true" : "false");
            pill.classList.toggle("site-filter-pill--active", active);
        });

        // Show/hide subnet-node elements; operate on ALL .subnet-node in the list view.
        // Child subnets are DOM-nested inside parent subnet-node divs, so we must also
        // keep a parent visible when any of its descendants match the active site filter.
        document.querySelectorAll("#subnet-list-view .subnet-node").forEach(function (node) {
            if (isAll) {
                node.classList.remove("subnet-node--filtered");
                return;
            }
            var siteId = parseInt(node.dataset.siteId || "0", 10);
            var selfMatch = activeSiteIds !== null && activeSiteIds.has(siteId);
            var childMatch = !selfMatch && Array.from(node.querySelectorAll(".subnet-node[data-site-id]")).some(function (desc) {
                return activeSiteIds !== null && activeSiteIds.has(parseInt(desc.dataset.siteId || "0", 10));
            });
            node.classList.toggle("subnet-node--filtered", !selfMatch && !childMatch);
        });

        // Hide site-group containers that now have no visible subnet nodes
        document.querySelectorAll("#subnet-list-view .site-group").forEach(function (sg) {
            if (isAll) {
                sg.classList.remove("site-group--filter-empty");
                return;
            }
            var visible = sg.querySelectorAll(".subnet-node:not(.subnet-node--filtered)").length > 0;
            sg.classList.toggle("site-group--filter-empty", !visible);
        });

        // Persist
        try { sessionStorage.setItem(STORAGE_KEY, filterVal || "all"); } catch (e) {}
    }

    // Handle pill clicks (and keyboard: Enter / Space)
    strip.addEventListener("click", function (e) {
        var pill = e.target.closest(".site-filter-pill");
        if (!pill) return;
        e.preventDefault();

        // Region toggle button: toggle collapsed children; do not change the tree filter
        var regionId = pill.dataset.regionToggle;
        if (regionId !== undefined) {
            var expanded = pill.getAttribute("aria-expanded") !== "false";
            pill.setAttribute("aria-expanded", expanded ? "false" : "true");
            var childrenWrap = strip.querySelector("[data-region-children='" + regionId + "']");
            if (childrenWrap) childrenWrap.classList.toggle("is-collapsed", expanded);
            // Also apply a filter for the whole region when clicking the region header pill
            applyFilter(pill.dataset.filterSite || "all");
            return;
        }

        applyFilter(pill.dataset.filterSite || "all");
    });

    strip.addEventListener("keydown", function (e) {
        if (e.key === "Enter" || e.key === " ") {
            var pill = e.target.closest(".site-filter-pill");
            if (pill) { e.preventDefault(); pill.click(); }
        }
    });

    // Apply saved state on load
    applyFilter(saved || "all");
}());


// Bulk-select bar for addresses table
(function () {
    var bar = document.getElementById("bulk-bar");
    if (!bar) return;
    var countEl = document.getElementById("bulk-bar-count");
    var linkEl = document.getElementById("bulk-bar-link");
    var subnetId = parseInt(bar.getAttribute("data-subnet-id") || "0", 10);

    function updateBar() {
        var checked = document.querySelectorAll(".row-select:checked");
        var n = checked.length;
        bar.classList.toggle("is-visible", n > 0);
        if (countEl) countEl.textContent = n + " selected";
        if (linkEl && subnetId > 0) {
            var ids = [];
            for (var i = 0; i < checked.length; i++) ids.push(checked[i].value);
            linkEl.href = "bulk_update.php?subnet_id=" + encodeURIComponent(subnetId) + "&ids=" + ids.join(",");
        }
    }

    var selectAll = document.getElementById("select-all-addresses");
    if (selectAll) {
        selectAll.addEventListener("change", function () {
            var boxes = document.querySelectorAll(".row-select");
            for (var i = 0; i < boxes.length; i++) boxes[i].checked = selectAll.checked;
            updateBar();
        });
    }

    document.addEventListener("change", function (e) {
        if (e.target && e.target.classList.contains("row-select")) updateBar();
    });
}());

// ── Address page: site → subnet cascading filter ──────────────────────────────
(function () {
    var siteSelect = document.getElementById("addrSiteFilter");
    var subnetSelect = document.querySelector("select[name=\"subnet_id\"]");
    if (!siteSelect || !subnetSelect) return;

    // Snapshot all options before any filtering occurs
    var allOpts = Array.prototype.slice.call(subnetSelect.options);

    function filterBySite(siteId) {
        var prevVal = subnetSelect.value;
        allOpts.forEach(function (opt) {
            // Always show the placeholder "-- Select --" (value 0 or empty)
            if (!opt.value || opt.value === "0") { opt.hidden = false; return; }
            opt.hidden = siteId > 0 && parseInt(opt.getAttribute("data-site-id") || "0", 10) !== siteId;
        });
        // If the currently-selected subnet is now hidden, reset it
        if (prevVal && prevVal !== "0") {
            var sel = subnetSelect.querySelector("option[value=\"" + prevVal + "\"]");
            if (sel && sel.hidden) subnetSelect.value = "0";
        }
    }

    siteSelect.addEventListener("change", function () {
        filterBySite(parseInt(this.value, 10) || 0);
    });

    // Apply filter on page load so a pre-selected site narrows the list immediately
    var initSite = parseInt(siteSelect.value, 10) || 0;
    if (initSite > 0) filterBySite(initSite);
}());

// Webhooks page — drawer + gen_secret + test-fire (#645 CSP fix, was inline IIFE)
(function () {
  var overlay   = document.getElementById('wh-form-overlay');
  if (!overlay) return; // not on webhooks.php

  var drawer    = document.getElementById('wh-form-drawer');
  var title     = document.getElementById('wh-drawer-title');
  var form      = document.getElementById('wh-form');
  var fAction   = document.getElementById('wh-form-action');
  var fId       = document.getElementById('wh-form-id');
  var fName     = document.getElementById('wh-f-name');
  var fUrl      = document.getElementById('wh-f-url');
  var fSecret   = document.getElementById('wh-f-secret');
  var cbs       = document.querySelectorAll('.wh-event-cb');
  var testPanel = document.getElementById('wh-test-panel');
  var testResult = document.getElementById('wh-test-result');

  function openDrawer() {
    overlay.style.display = 'block';
    drawer.style.display  = 'block';
  }
  function closeDrawer() {
    overlay.style.display = 'none';
    drawer.style.display  = 'none';
  }

  document.getElementById('add-wh-btn').addEventListener('click', function () {
    title.textContent = 'Add webhook';
    fAction.value = 'create';
    fId.value = '';
    form.reset();
    testPanel.style.display = 'none';
    openDrawer();
  });

  document.querySelectorAll('.wh-edit-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      title.textContent = 'Edit webhook';
      fAction.value = 'edit';
      fId.value     = btn.dataset.id || '';
      fName.value   = btn.dataset.name || '';
      fUrl.value    = btn.dataset.url  || '';
      var evts = JSON.parse(btn.dataset.events || '[]');
      cbs.forEach(function (cb) { cb.checked = evts.includes(cb.value); });
      testPanel.style.display = 'none';
      openDrawer();
    });
  });

  document.getElementById('wh-drawer-close').addEventListener('click', closeDrawer);
  document.getElementById('wh-drawer-close2').addEventListener('click', closeDrawer);
  overlay.addEventListener('click', closeDrawer);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && overlay.style.display === 'block') closeDrawer(); });

  document.getElementById('wh-gen-secret').addEventListener('click', function () {
    var fd = new FormData();
    fd.append('csrf', document.querySelector('input[name=csrf]').value);
    fd.append('action', 'gen_secret');
    fetch('webhooks.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d.secret) fSecret.value = d.secret; });
  });

  document.querySelectorAll('.wh-testfire-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      title.textContent = 'Test fire — ' + (btn.dataset.name || '');
      fAction.value = 'test_fire';
      fId.value     = btn.dataset.id || '';
      testPanel.style.display = 'block';
      testResult.innerHTML    = '<span class="muted">Sending…</span>';
      openDrawer();

      var fd = new FormData();
      fd.append('csrf', document.querySelector('input[name=csrf]').value);
      fd.append('action', 'test_fire');
      fd.append('id', btn.dataset.id || '');
      fetch('webhooks.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          var colour = d.ok ? '#065f46' : '#991b1b';
          var status = d.status ? 'HTTP ' + d.status : 'No response';
          function escHtml(s) {
            var t = document.createElement('div');
            t.textContent = typeof s === 'string' ? s : String(s);
            return t.innerHTML;
          }
          testResult.innerHTML =
            '<p style="color:' + colour + ';font-weight:600">' +
              (d.ok ? '✓ Delivered' : '✗ Failed') + ' — ' + status + '</p>' +
            (d.error ? '<p class="muted">Error: ' + escHtml(d.error) + '</p>' : '') +
            '<p class="muted" style="font-size:.8rem">Signature: <code>' + escHtml(d.signature || '') + '</code></p>' +
            (d.body ? '<pre style="font-size:.75rem;overflow-x:auto;max-height:120px">' + escHtml(d.body.substring(0, 500)) + '</pre>' : '');
        })
        .catch(function () { testResult.textContent = 'Request failed.'; });
    });
  });
}());

// Addresses page — device → interface dropdown cascade (#645 CSP fix, was inline IIFE)
(function () {
  var el = document.getElementById('iface-data');
  if (!el) return; // not on addresses.php or no devices

  var ifaceMap;
  try { ifaceMap = JSON.parse(el.getAttribute('data-ifaces') || '{}'); }
  catch (e) { ifaceMap = {}; }

  document.addEventListener('change', function (e) {
    var sel = e.target;
    if (!sel || !sel.classList.contains('addr-device-select')) return;
    var targetId = sel.getAttribute('data-iface-target');
    var target = targetId ? document.getElementById(targetId) : null;
    if (!target) return;
    var devId = parseInt(sel.value, 10);
    var ifaces = (devId && ifaceMap[devId]) ? ifaceMap[devId] : [];
    target.innerHTML = '<option value="0">(none)</option>';
    ifaces.forEach(function (iface) {
      var opt = document.createElement('option');
      opt.value = iface.id;
      opt.textContent = iface.name;
      target.appendChild(opt);
    });
  });
}());

// Collapsible row toggle — sites admin and any future collapsible groups (v3.11.0 #632 #633)
(function () {
  function applyCollapsible(btn, childRows, expanded) {
    btn.setAttribute('aria-expanded', String(expanded));
    childRows.forEach(function (row) {
      row.classList.toggle('collapsible-child--hidden', !expanded);
    });
  }

  document.querySelectorAll('[data-collapsible-toggle]').forEach(function (btn) {
    var groupId = btn.getAttribute('data-collapsible-group-id');
    var storageKey = 'ipam-collapsible-' + groupId;
    var childRows = Array.prototype.slice.call(document.querySelectorAll('[data-collapsible-child="' + groupId + '"]'));
    var saved = sessionStorage.getItem(storageKey);
    var expanded = saved === null ? true : saved === 'true';
    applyCollapsible(btn, childRows, expanded);
    btn.addEventListener('click', function () {
      var isExpanded = btn.getAttribute('aria-expanded') === 'true';
      applyCollapsible(btn, childRows, !isExpanded);
      sessionStorage.setItem(storageKey, String(!isExpanded));
    });
  });

  var collapseAllBtn = document.querySelector('[data-collapsible-collapse-all]');
  var expandAllBtn   = document.querySelector('[data-collapsible-expand-all]');
  function allGroupsAction(expanded) {
    document.querySelectorAll('[data-collapsible-toggle]').forEach(function (btn) {
      var groupId = btn.getAttribute('data-collapsible-group-id');
      var childRows = Array.prototype.slice.call(document.querySelectorAll('[data-collapsible-child="' + groupId + '"]'));
      applyCollapsible(btn, childRows, expanded);
      sessionStorage.setItem('ipam-collapsible-' + groupId, String(expanded));
    });
  }
  if (collapseAllBtn) { collapseAllBtn.addEventListener('click', function () { allGroupsAction(false); }); }
  if (expandAllBtn)   { expandAllBtn.addEventListener('click',   function () { allGroupsAction(true); }); }
}());

// ── Passkey verify page (#689) ──────────────────────────────────────────────
(function () {
  var btn = document.getElementById('btn-passkey');
  if (!btn) return;

  var status = document.getElementById('passkey-status');

  function b64url(buf) {
    return btoa(String.fromCharCode.apply(null, Array.prototype.slice.call(new Uint8Array(buf))))
      .replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
  }

  function b64ToBytes(b64) {
    return Uint8Array.from(atob(b64.replace(/-/g, '+').replace(/_/g, '/')), function (c) { return c.charCodeAt(0); });
  }

  function verify() {
    var optsRaw = btn.getAttribute('data-assert-opts');
    if (!optsRaw) { return; }
    var opts;
    try { opts = JSON.parse(optsRaw); } catch (e) { return; }

    btn.disabled = true;
    if (status) status.textContent = 'Waiting for authenticator…';

    opts.challenge = b64ToBytes(opts.challenge);
    if (opts.allowCredentials) {
      opts.allowCredentials = opts.allowCredentials.map(function (c) {
        return Object.assign({}, c, { id: b64ToBytes(c.id) });
      });
    }

    navigator.credentials.get({ publicKey: opts }).then(function (cred) {
      document.getElementById('f-clientDataJSON').value    = b64url(cred.response.clientDataJSON);
      document.getElementById('f-authenticatorData').value = b64url(cred.response.authenticatorData);
      document.getElementById('f-signature').value         = b64url(cred.response.signature);
      document.getElementById('f-credentialId').value      = b64url(cred.rawId);
      document.getElementById('passkey-form').submit();
    }).catch(function () {
      if (status) status.textContent = 'Passkey prompt cancelled or failed. Try again.';
      btn.disabled = false;
    });
  }

  btn.addEventListener('click', verify);
  setTimeout(verify, 300);
}());

// ── Step-up auth prompt (#1107) ────────────────────────────────────────────
// Drives views/_step_up_prompt.php: hides/shows method-specific sections
// when the user changes the method dropdown, and runs the WebAuthn
// navigator.credentials.get() flow when the user clicks "Verify with passkey".
(function () {
  var prompt = document.querySelector('[data-step-up-prompt]');
  if (!prompt) return;

  var methodEl = prompt.querySelector('[data-step-up-method]');
  var sections = prompt.querySelectorAll('[data-step-up-section]');

  function showSection(method) {
    for (var i = 0; i < sections.length; i++) {
      var sec = sections[i];
      if (sec.getAttribute('data-step-up-section') === method) {
        sec.removeAttribute('hidden');
      } else {
        sec.setAttribute('hidden', '');
      }
    }
  }

  if (methodEl && methodEl.tagName === 'SELECT') {
    methodEl.addEventListener('change', function () { showSection(methodEl.value); });
    showSection(methodEl.value);
  }

  var waBtn = document.getElementById('step-up-webauthn-btn');
  if (!waBtn) return;

  var waStatus = document.getElementById('step-up-webauthn-status');

  function b64url(buf) {
    return btoa(String.fromCharCode.apply(null, Array.prototype.slice.call(new Uint8Array(buf))))
      .replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
  }
  function b64ToBytes(b64) {
    return Uint8Array.from(atob(b64.replace(/-/g, '+').replace(/_/g, '/')), function (c) { return c.charCodeAt(0); });
  }

  function setStatus(msg) { if (waStatus) waStatus.textContent = msg; }

  function runWebAuthn() {
    // Defensive guards (CodeRabbit #1116): WebAuthn may be unavailable
    // (older browsers, insecure context); the prompt's hidden form fields
    // may be missing if the partial was misincluded; the dropdown may be
    // absent in single-method renderings. Validate everything before
    // mutating state, and never leave waBtn disabled on an early return.
    if (!window.PublicKeyCredential ||
        !navigator.credentials ||
        typeof navigator.credentials.get !== 'function') {
      setStatus('Passkeys are not supported in this browser. Choose another method.');
      return;
    }

    var cdj = document.getElementById('step-up-cdj');
    var ad  = document.getElementById('step-up-ad');
    var sig = document.getElementById('step-up-sig');
    var cid = document.getElementById('step-up-cid');
    var form = document.getElementById('step-up-form');
    if (!cdj || !ad || !sig || !cid || !form) {
      setStatus('Step-up form is missing required fields. Reload the page.');
      return;
    }

    var optsRaw = waBtn.getAttribute('data-step-up-webauthn-opts');
    if (!optsRaw) { setStatus('No passkey challenge available. Reload the page.'); return; }

    var opts;
    try {
      opts = JSON.parse(optsRaw);
      opts.challenge = b64ToBytes(opts.challenge);
      if (opts.allowCredentials) {
        opts.allowCredentials = opts.allowCredentials.map(function (c) {
          return Object.assign({}, c, { id: b64ToBytes(c.id) });
        });
      }
    } catch (e) {
      setStatus('Bad challenge data. Reload the page.');
      return;
    }

    waBtn.disabled = true;
    setStatus('Waiting for authenticator…');

    try {
      navigator.credentials.get({ publicKey: opts }).then(function (cred) {
        if (!cred || !cred.response || !cred.rawId) {
          setStatus('Passkey response was incomplete. Try again.');
          waBtn.disabled = false;
          return;
        }
        cdj.value = b64url(cred.response.clientDataJSON);
        ad.value  = b64url(cred.response.authenticatorData);
        sig.value = b64url(cred.response.signature);
        cid.value = b64url(cred.rawId);
        // Force the chosen method to webauthn even if the dropdown is on
        // something else.
        if (methodEl) methodEl.value = 'webauthn';
        form.submit();
      }).catch(function () {
        setStatus('Passkey prompt cancelled or failed. Try again.');
        waBtn.disabled = false;
      });
    } catch (e) {
      setStatus('Passkey prompt failed to start. Try again.');
      waBtn.disabled = false;
    }
  }

  waBtn.addEventListener('click', runWebAuthn);
}());

// ── Passkey registration (change_password.php #passkeys) (#688) ─────────────
(function () {
  var btn = document.getElementById('btn-add-passkey');
  if (!btn) return;

  var statusEl = document.getElementById('passkey-add-status');

  function b64url(buf) {
    return btoa(String.fromCharCode.apply(null, Array.prototype.slice.call(new Uint8Array(buf))))
      .replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
  }

  function b64ToBytes(b64) {
    return Uint8Array.from(atob(b64.replace(/-/g, '+').replace(/_/g, '/')), function (c) { return c.charCodeAt(0); });
  }

  function setStatus(msg) {
    if (!statusEl) return;
    statusEl.style.display = 'inline';
    statusEl.textContent = msg;
  }

  function register() {
    btn.disabled = true;
    setStatus('Generating challenge…');

    var csrf = (document.querySelector('[name=csrf]') || {}).value || '';

    fetch('passkey_register.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ action: 'get_challenge', csrf: csrf })
    }).then(function (r) { return r.json(); }).then(function (data) {
      if (!data.ok) throw new Error(data.error || 'Challenge failed');

      var opts = data.options;
      opts.challenge = b64ToBytes(opts.challenge);
      opts.user.id   = b64ToBytes(opts.user.id);
      if (opts.excludeCredentials) {
        opts.excludeCredentials = opts.excludeCredentials.map(function (c) {
          return Object.assign({}, c, { id: b64ToBytes(c.id) });
        });
      }

      setStatus('Waiting for authenticator…');
      return navigator.credentials.create({ publicKey: opts });
    }).then(function (cred) {
      var credName = btn.getAttribute('data-default-name') || 'Passkey';
      return fetch('passkey_register.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
          action:            'complete',
          csrf:              (document.querySelector('[name=csrf]') || {}).value || '',
          clientDataJSON:    b64url(cred.response.clientDataJSON),
          attestationObject: b64url(cred.response.attestationObject),
          name:              credName
        })
      }).then(function (r) { return r.json(); }).then(function (result) {
        if (!result.ok) throw new Error(result.error || 'Registration failed');
        setStatus('Passkey registered!');
        setTimeout(function () { window.location.reload(); }, 800);
      });
    }).catch(function (err) {
      setStatus('Registration failed: ' + err.message);
      btn.disabled = false;
    });
  }

  btn.addEventListener('click', register);
}());

/* ── Legacy fragment-only bookmark redirect (#749 follow-up) ──────────── */
// Pre-#749 a user could bookmark `settings.php#group-mfa`. After the tab
// rewrite the MFA form lives under ?tab=authentication, so a bare anchor
// lands on the General tab and silently shows the wrong content. This
// shim reads window.location.hash on page load and, if the hash points at
// a known group on a tab other than the current one, replaces the URL
// with the owning tab + the same anchor. Runs before the rail nav IIFE
// so the redirect happens before any user interaction.
(function () {
  var rail = document.querySelector('[data-settings-rail]');
  if (!rail) return;
  var pageName = (window.location.pathname.split('/').pop() || '');
  if (!/^settings\.php/.test(pageName)) return;
  var m = window.location.hash.match(/^#group-([a-z0-9_]+)$/);
  if (!m) return;
  var map;
  try {
    map = JSON.parse(rail.getAttribute('data-group-tab-map') || '{}');
  } catch (err) {
    return;
  }
  var owningTab = map[m[1]];
  if (!owningTab) return;
  var url = new URL(window.location.href);
  if (url.searchParams.get('tab') === owningTab) return;
  url.searchParams.set('tab', owningTab);
  window.location.replace(url.pathname + url.search + window.location.hash);
}());

/* ── Settings rail keyboard nav + mobile select (#749, v3.16.0) ───────── */
(function () {
  // Mobile: changing the <select> navigates to ?tab=<value>. The form has
  // method=get action=settings.php so non-JS users get the same behaviour
  // by submitting normally; this just removes the extra click.
  var mobileSelect = document.querySelector('select[data-settings-mobile-nav]');
  if (mobileSelect) {
    mobileSelect.addEventListener('change', function () {
      var form = mobileSelect.closest('form');
      if (form) form.submit();
    });
  }

  // Desktop rail: ArrowUp/ArrowDown move focus between rail links,
  // Home/End jump to first/last, Enter activates the focused link.
  var rail = document.querySelector('[data-settings-rail]');
  if (!rail) return;
  var links = Array.prototype.slice.call(rail.querySelectorAll('.settings-rail__link'));
  if (links.length === 0) return;

  rail.addEventListener('keydown', function (e) {
    var idx = links.indexOf(document.activeElement);
    if (idx === -1) return;
    var next = null;
    if (e.key === 'ArrowDown') {
      next = links[(idx + 1) % links.length];
    } else if (e.key === 'ArrowUp') {
      next = links[(idx - 1 + links.length) % links.length];
    } else if (e.key === 'Home') {
      next = links[0];
    } else if (e.key === 'End') {
      next = links[links.length - 1];
    } else if (e.key === 'Enter') {
      // Native <a> activates on Enter already; do not preventDefault.
      return;
    } else {
      return;
    }
    e.preventDefault();
    if (next) next.focus();
  });
}());

/* === v3.17 destinations admin === */
(function () {
    // Selector list must include every trigger this IIFE wires up. Missing
    // [data-run-now-target] here used to short-circuit the entire block on
    // the unified Backup tab (#1040), leaving the Run-now button inert.
    if (!document.querySelector('.destination-form, [data-edit-destination], [data-test-destination], [data-run-now], [data-run-now-target]')) {
        return;
    }

    function getCsrf() {
        var el = document.querySelector('input[name="csrf"]');
        return el ? el.value : '';
    }

    // Edit drawer toggles for destinations and schedules (#778, #780).
    function bindToggle(triggerAttr, cancelAttr) {
        document.querySelectorAll('[' + triggerAttr + ']').forEach(function (btn) {
            btn.addEventListener('click', function (ev) {
                ev.preventDefault();
                var row = document.getElementById(btn.getAttribute('aria-controls'));
                if (!row) return;
                var open = row.hasAttribute('hidden');
                if (open) {
                    row.removeAttribute('hidden');
                    btn.setAttribute('aria-expanded', 'true');
                    var firstInput = row.querySelector('input:not([type=hidden]):not([disabled]), select, textarea');
                    if (firstInput) firstInput.focus();
                } else {
                    row.setAttribute('hidden', '');
                    btn.setAttribute('aria-expanded', 'false');
                }
            });
        });
        document.querySelectorAll('[' + cancelAttr + ']').forEach(function (btn) {
            btn.addEventListener('click', function (ev) {
                ev.preventDefault();
                var id = btn.getAttribute(cancelAttr);
                var row = btn.closest('tr');
                var trigger = document.querySelector('[' + triggerAttr + '="' + id + '"]');
                if (row) row.setAttribute('hidden', '');
                if (trigger) trigger.setAttribute('aria-expanded', 'false');
            });
        });
    }
    bindToggle('data-edit-destination', 'data-edit-destination-cancel');
    bindToggle('data-edit-schedule',    'data-edit-schedule-cancel');

    // #781: hide schedule fields that don't apply to the chosen frequency.
    // Matrix: hourly = none; daily = time_of_day; weekly = time_of_day + day_of_week;
    // monthly = time_of_day + day_of_month. Hidden inputs are also disabled so that
    // the browser drops them from the submitted payload (defence-in-depth: server
    // also normalises non-applicable fields to NULL).
    function applyFreqGating(form) {
        var sel = form.querySelector('select[name="frequency"]');
        if (!sel) return;
        var freq = sel.value;
        var visible = { time_of_day: false, day_of_week: false, day_of_month: false };
        if (freq === 'daily')   { visible.time_of_day = true; }
        if (freq === 'weekly')  { visible.time_of_day = true; visible.day_of_week  = true; }
        if (freq === 'monthly') { visible.time_of_day = true; visible.day_of_month = true; }
        form.querySelectorAll('[data-freq-field]').forEach(function (el) {
            var key = el.getAttribute('data-freq-field');
            var show = !!visible[key];
            el.hidden = !show;
            el.querySelectorAll('input, select, textarea').forEach(function (i) {
                i.disabled = !show;
            });
        });
    }
    function bindScheduleForm(form) {
        if (!form || form.dataset.freqBound === '1') return;
        var sel = form.querySelector('select[name="frequency"]');
        if (!sel) return;
        sel.addEventListener('change', function () { applyFreqGating(form); });
        applyFreqGating(form);
        form.dataset.freqBound = '1';
    }
    document.querySelectorAll('form.schedule-form').forEach(bindScheduleForm);
    // Drawer-injected schedule forms need re-binding (#803).
    document.addEventListener('drawer:loaded', function (e) {
        var body = (e && e.detail && e.detail.body) || document;
        body.querySelectorAll('form.schedule-form').forEach(bindScheduleForm);
    });

    // Type selector swap. Hidden fieldsets must also disable their inputs so that
    // HTML5 validation on `required` inputs in non-active fieldsets does not block
    // form submission (otherwise the browser silently rejects the submit).
    var sel = document.querySelector('[data-destination-type-selector]');
    if (sel) {
        var updateFieldset = function () {
            document.querySelectorAll('.destination-fields').forEach(function (fs) {
                var active = fs.dataset.type === sel.value;
                fs.hidden = !active;
                fs.querySelectorAll('input, textarea, select').forEach(function (el) {
                    el.disabled = !active;
                });
            });
        };
        sel.addEventListener('change', updateFieldset);
        updateFieldset();
    }

    // Test connection
    document.querySelectorAll('[data-test-destination]').forEach(function (btn) {
        btn.addEventListener('click', function (ev) {
            ev.preventDefault();
            var id = btn.dataset.testDestination;
            btn.disabled = true;
            var orig = btn.textContent;
            btn.textContent = 'Testing…';
            var fd = new FormData();
            fd.append('csrf', getCsrf());
            fd.append('id', id);
            fetch('test_destination.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (j.ok) {
                        btn.textContent = '✓ ' + (j.message || 'connected') + (j.latency_ms != null ? ' (' + j.latency_ms + 'ms)' : '');
                        btn.classList.add('button-success');
                    } else {
                        btn.textContent = '✗ ' + (j.message || 'failed');
                        btn.classList.add('button-danger');
                    }
                })
                .catch(function () {
                    btn.textContent = '✗ network error';
                    btn.classList.add('button-danger');
                })
                .finally(function () {
                    setTimeout(function () {
                        btn.disabled = false;
                        btn.textContent = orig;
                        btn.classList.remove('button-success', 'button-danger');
                    }, 5000);
                });
        });
    });

    // Run now
    // v3.21.0 #797 Backup tab — run-now button paired with a destination
    // <select>. Result text is rendered into the configured aria-live span.
    document.querySelectorAll('[data-run-now-target]').forEach(function (btn) {
        btn.addEventListener('click', function (ev) {
            ev.preventDefault();
            var sel = document.getElementById(btn.dataset.runNowTarget);
            var out = document.getElementById(btn.dataset.runNowResult || '');
            if (!sel) return;
            var destId = sel.value;
            if (!destId || destId === '0') return;
            if (!confirm('Run backup now for the selected destination?')) return;
            btn.disabled = true;
            sel.disabled = true;
            var orig = btn.textContent;
            btn.textContent = 'Running…';
            if (out) { out.textContent = ''; out.classList.remove('success', 'danger'); }
            var fd = new FormData();
            fd.append('csrf', getCsrf());
            fd.append('destination_id', destId);
            fetch('run_backup_now.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (out) {
                        if (j.ok) {
                            out.textContent = '✓ ' + j.filename + ' (' + j.size + ' bytes)';
                            out.classList.add('success');
                        } else {
                            out.textContent = '✗ ' + (j.message || 'failed');
                            out.classList.add('danger');
                        }
                    }
                })
                .catch(function () {
                    if (out) {
                        out.textContent = '✗ network error';
                        out.classList.add('danger');
                    }
                })
                .finally(function () {
                    btn.disabled = false;
                    sel.disabled = false;
                    btn.textContent = orig;
                });
        });
    });

    document.querySelectorAll('[data-run-now]').forEach(function (btn) {
        btn.addEventListener('click', function (ev) {
            ev.preventDefault();
            if (!confirm('Run backup now for this destination?')) return;
            var destId = btn.dataset.runNow;
            btn.disabled = true;
            var orig = btn.textContent;
            btn.textContent = 'Running…';
            var fd = new FormData();
            fd.append('csrf', getCsrf());
            fd.append('destination_id', destId);
            fetch('run_backup_now.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (j.ok) {
                        btn.textContent = '✓ ' + j.filename + ' (' + j.size + ' bytes)';
                        btn.classList.add('button-success');
                    } else {
                        btn.textContent = '✗ ' + (j.message || 'failed');
                        btn.classList.add('button-danger');
                    }
                })
                .catch(function () {
                    btn.textContent = '✗ network error';
                    btn.classList.add('button-danger');
                })
                .finally(function () {
                    setTimeout(function () {
                        btn.disabled = false;
                        btn.textContent = orig;
                        btn.classList.remove('button-success', 'button-danger');
                    }, 8000);
                });
        });
    });
}());

/* === v3.17 restore confirm-typing gate === */
(function () {
    var input = document.getElementById('restore-confirm-input');
    var button = document.getElementById('restore-apply-button');
    if (!input || !button) return;
    input.addEventListener('input', function () {
        button.disabled = (input.value !== 'RESTORE');
    });
})();

/* === v3.17 remote_backups Delete-with-confirm (CSP-safe; replaces inline onsubmit) === */
(function () {
    document.querySelectorAll('form[data-confirm-delete]').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            // v3.25.0 #858: when the form also has data-confirm-typename, gate
            // delete behind typing the destination name. Belt-and-suspenders
            // alongside the simpler window.confirm() prompt for any form that
            // doesn't opt into the type-to-confirm pattern.
            var typeName = form.getAttribute('data-confirm-typename');
            if (typeName) {
                var typed = window.prompt(
                    'Type "' + typeName + '" to confirm delete:',
                    ''
                );
                if (typed !== typeName) {
                    ev.preventDefault();
                    if (typed !== null) {
                        window.alert('Name did not match. Delete canceled.');
                    }
                    return;
                }
                // Name matched — fall through (no second confirm needed).
                return;
            }
            var name = form.getAttribute('data-confirm-delete') || 'this file';
            if (!window.confirm('Delete ' + name + ' from the remote destination?')) {
                ev.preventDefault();
            }
        });
    });
})();

/* === v3.25.0 #850 destinations Verify-all bulk action ===
 * Buttons rendered with data-verify-all="<id>" + data-destination-name=
 * trigger a JSON POST to backup_admin.php?tab=destinations with
 * action=verify_all_destination. Result envelope is summarised inline next
 * to the button. */
(function () {
    document.querySelectorAll('button[data-verify-all]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var destId = btn.getAttribute('data-verify-all');
            var name   = btn.getAttribute('data-destination-name') || ('destination ' + destId);
            if (!window.confirm('Verify every backup on ' + name + '? This downloads each artifact and re-hashes it; long-running on large destinations.')) {
                return;
            }
            btn.disabled = true;
            var originalLabel = btn.textContent;
            btn.textContent = 'Verifying…';
            var token = (document.querySelector('input[name="csrf"]') || {}).value || '';
            var body  = new URLSearchParams();
            body.append('csrf', token);
            body.append('action', 'verify_all_destination');
            body.append('id', String(destId));
            fetch('backup_admin.php?tab=destinations', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
                body: body
            }).then(function (r) {
                return r.json().then(function (j) { return { ok: r.ok, body: j }; });
            }).then(function (resp) {
                var j = resp.body || {};
                var msg;
                if (j.ok) {
                    msg = '✓ ' + j.success + '/' + j.total + ' verified';
                } else {
                    msg = '✗ ' + (j.failed || 0) + '/' + (j.total || 0) + ' failed';
                    if (j.failures && j.failures.length) {
                        msg += ' (first: run #' + j.failures[0].run_id + ' — ' + j.failures[0].error + ')';
                    }
                }
                window.alert(msg);
            }).catch(function (e) {
                window.alert('Verify-all error: ' + (e && e.message ? e.message : e));
            }).finally(function () {
                btn.disabled = false;
                btn.textContent = originalLabel;
            });
        });
    });
})();

/* === v3.25.0 #855 skeleton-toggle helper ===
 * Pages opt in by setting `data-skeleton="loading"` on a container that
 * holds skeleton placeholder rows; once the real content is ready the
 * container's `data-skeleton` attribute is set to `ready`. CSS handles
 * the visual swap. This file just exposes a window-level helper for
 * page scripts to call.
 */
(function () {
    if (window.ipamSkeleton) return;
    window.ipamSkeleton = {
        loading: function (selector) {
            document.querySelectorAll(selector).forEach(function (el) {
                el.setAttribute('data-skeleton', 'loading');
            });
        },
        ready: function (selector) {
            document.querySelectorAll(selector).forEach(function (el) {
                el.setAttribute('data-skeleton', 'ready');
            });
        }
    };
})();

// #1131 (v3.27.3) — sudo-replay landing page auto-submits the staged
// POST so the user only briefly sees the "Resuming…" card. CSP-safe:
// no inline <script>, just a marker attribute on the form.
(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var forms = document.querySelectorAll('form[data-auto-submit]');
        for (var i = 0; i < forms.length; i++) {
            forms[i].submit();
        }
    });
})();
