/* === v3.17 restore confirm-typing gate === */
(function () {
    var input = document.getElementById('restore-confirm-input');
    var button = document.getElementById('restore-apply-button');
    if (!input || !button) return;
    input.addEventListener('input', function () {
        button.disabled = (input.value !== 'RESTORE');
    });
})();

