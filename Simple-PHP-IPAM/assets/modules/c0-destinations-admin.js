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

