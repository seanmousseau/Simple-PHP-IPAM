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

