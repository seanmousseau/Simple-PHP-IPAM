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

