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

