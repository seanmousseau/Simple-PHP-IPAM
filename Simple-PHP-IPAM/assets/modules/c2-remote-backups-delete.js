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

