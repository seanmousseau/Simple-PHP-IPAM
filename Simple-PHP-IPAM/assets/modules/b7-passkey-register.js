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

