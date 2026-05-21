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

