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
