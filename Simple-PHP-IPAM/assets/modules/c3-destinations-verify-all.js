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
