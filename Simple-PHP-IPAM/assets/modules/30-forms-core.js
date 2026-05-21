// ─── C05 — Forms-core (Phase 2a, #939) ───────────────────────────────────────
// Auto-submit on select change + the password show/hide toggle complex
// (eye-icon button + reveal-from-stored secret fetch + OIDC step-up
// round-trip replay marker). The pw-toggle is the bulk of this concern.
// Uses `window.__ipamPwReplayInProgress` as a one-shot anti-loop flag
// (window-scoped because the marker survives a page navigation through
// step_up.php and back). The eye SVG sprites live in icons.svg as
// icon-eye / icon-eye-slash and are wired into the markup by
// `lib/presentation.php`'s settings_group_form partial (#942/PR-1).
(function(){
  document.addEventListener("DOMContentLoaded", function() {
    // --- Auto-submit selects (data-auto-submit) ---
    document.querySelectorAll("[data-auto-submit]").forEach(function(el) {
      el.addEventListener("change", function() { el.form.submit(); });
    });

    // --- Password show/hide toggle (eye-icon button, #449) ---
    // <button class="pw-toggle" data-pw-toggle-for="<input-id>"> flips the
    // referenced password input between type=password and type=text.
    // Replaced the v2.6.0 inline-onclick + v2.7.0 nested-checkbox approaches
    // after both regressed in real browsers (containerized Playwright passed
    // but the deployed UI did not flip). Stays on type=password by default
    // so password manager autofill keeps working.
    //
    // #449 follow-up: settings.php sensitive fields render with value=""
    // (the stored secret never appears in HTML source), so an unaided
    // toggle could only reveal what the user had just typed. When the
    // button carries data-pw-reveal-key + data-pw-reveal-csrf, fetch the
    // stored value from settings_reveal.php on the first reveal click and
    // populate the input. On hide, clear the input so the secret does not
    // linger in the DOM and the existing "leave blank to keep current"
    // submit semantics still work.
    function pwToggleApply(btn, input, willShow) {
      input.type = willShow ? "text" : "password";
      btn.setAttribute("aria-pressed", willShow ? "true" : "false");
      btn.setAttribute("aria-label", willShow ? "Hide password" : "Show password");
    }
    document.addEventListener("click", function(e) {
      var btn = e.target && e.target.closest ? e.target.closest("button.pw-toggle[data-pw-toggle-for]") : null;
      if (!btn) return;
      var input = document.getElementById(btn.getAttribute("data-pw-toggle-for"));
      if (!input) return;
      var willShow = input.type === "password";

      // Hide path: if we filled this from the stored value, clear the
      // input so the secret leaves the DOM.
      if (!willShow) {
        if (input.dataset.pwRevealedFromStored === "1") {
          input.value = "";
          delete input.dataset.pwRevealedFromStored;
        }
        pwToggleApply(btn, input, false);
        return;
      }

      // Show path: if the input is empty and the button declares a reveal
      // key, fetch the stored secret first. Otherwise just flip the type
      // (user has typed a value they want to verify).
      var revealKey = btn.getAttribute("data-pw-reveal-key");
      var revealCsrf = btn.getAttribute("data-pw-reveal-csrf");
      if (revealKey && revealCsrf && input.value === "") {
        var body = new URLSearchParams({ csrf: revealCsrf, key: revealKey });
        // Build an absolute URL with any embedded credentials stripped so
        // the fetch() constructor does not throw "Request cannot be
        // constructed from a URL that includes credentials" when the page
        // was loaded with HTTP basic auth credentials in the URL (dev /
        // test scenarios).
        var revealUrl;
        try {
          revealUrl = new URL("settings_reveal.php", window.location.href);
          revealUrl.username = "";
          revealUrl.password = "";
          revealUrl = revealUrl.toString();
        } catch (_) {
          revealUrl = "settings_reveal.php";
        }
        btn.disabled = true;
        // v3.27.0 (#1113): settings_reveal returns 401 + JSON
        // {error:'step_up_required'} when the session has no fresh sudo
        // grant. Navigate to step_up.php with a return-to so the user
        // re-authenticates inline and lands back here. Any other error
        // surfaces in the eye's aria-label as before.
        fetch(revealUrl, { method: "POST", body: body, credentials: "include" })
          .then(function(r) {
            return r.json().then(function(data) { return { ok: r.ok, status: r.status, data: data }; },
                                 function() { return { ok: r.ok, status: r.status, data: null }; });
          })
          .then(function(resp) {
            if (resp.status === 401 && resp.data && resp.data.error === "step_up_required") {
              // Build a return-to that points back at this exact settings tab so
              // the user lands where they started after the proof. Any anchor
              // that pinned them to a specific group is preserved.
              var returnTo;
              try {
                var here = new URL(window.location.href);
                here.username = ""; here.password = "";
                returnTo = here.pathname + here.search + here.hash;
              } catch (_) {
                returnTo = "settings.php";
              }
              // #1140: stash a one-shot XHR replay marker so the user
              // doesn't have to click the eye a second time after the
              // OIDC step-up round-trip. Cleared on click and on
              // successful replay; expires after 120 s. Cross-tab safe
              // (sessionStorage is per-tab) and single-use. CSRF is
              // re-read from the page DOM at replay time (it rotates
              // through the OIDC redirect chain), so we do NOT stash it.
              if (!window.__ipamPwReplayInProgress) {
                try {
                  sessionStorage.setItem("ipam_xhr_replay_v1", JSON.stringify({
                    type: "pw_reveal",
                    inputId: input.id,
                    revealKey: revealKey,
                    ts: Date.now()
                  }));
                } catch (_) { /* storage may be full or blocked */ }
              }
              var stepUpUrl = "step_up.php?return_to=" + encodeURIComponent(returnTo);
              window.location.assign(stepUpUrl);
              return;
            }
            if (!resp.ok) throw new Error("HTTP " + resp.status);
            if (resp.data && typeof resp.data.value === "string") {
              input.value = resp.data.value;
              input.dataset.pwRevealedFromStored = "1";
            }
            pwToggleApply(btn, input, true);
          })
          .catch(function() {
            btn.setAttribute("aria-label", "Could not reveal stored value");
          })
          .finally(function() {
            btn.disabled = false;
            // #1140: clear the replay guard once the fetch resolves (success
            // or fail). Pre-fix the flag stayed true after a successful
            // auto-replay, so a later step-up flow on the same page would
            // skip stashing — the second OIDC round-trip would then drop
            // back without an auto-resume, re-creating the bug for the
            // second action. CR follow-up on PR #1144.
            window.__ipamPwReplayInProgress = false;
          });
        return;
      }

      pwToggleApply(btn, input, true);
    });
    // #1140: consume the one-shot XHR replay marker if present. Set just
    // before navigating to step_up.php from a 401 step_up_required; cleared
    // here on the originating page's next load. Auto-clicks the matching
    // pw-toggle button so the user sees the reveal complete instead of a
    // silent drop. If the replay itself gets another 401 (e.g. user
    // cancelled OIDC), __ipamPwReplayInProgress prevents a re-stash loop.
    //
    // #1146 (v3.27.6): only removeItem when we actually have a matching
    // button to click. Pre-fix removeItem fired unconditionally on every
    // page load that saw the marker — so step_up.php (which is a
    // legitimate intermediate stop on the OIDC reauth journey, has no
    // pw-toggle button, but DOES load app.js) ate the marker before the
    // user got back to the originating settings.php. The 120 s TTL
    // bounds any case where no page ever matches.
    try {
      var replayRaw = sessionStorage.getItem("ipam_xhr_replay_v1");
      if (replayRaw) {
        var replay = JSON.parse(replayRaw);
        if (replay && replay.type === "pw_reveal"
            && typeof replay.inputId === "string"
            && typeof replay.revealKey === "string"
            && (Date.now() - (replay.ts || 0)) < 120000) {
          var replayBtn = document.querySelector(
            'button.pw-toggle[data-pw-toggle-for="' + CSS.escape(replay.inputId) + '"]'
            + '[data-pw-reveal-key="' + CSS.escape(replay.revealKey) + '"]'
          );
          if (replayBtn) {
            sessionStorage.removeItem("ipam_xhr_replay_v1");
            window.__ipamPwReplayInProgress = true;
            // Defer to the next microtask so the rest of DOMContentLoaded
            // wiring (including the click handler attached above) is in
            // place before we synthesise the click.
            setTimeout(function() { replayBtn.click(); }, 0);
          }
        } else {
          // Malformed or expired marker — eat it so it can't fire later.
          sessionStorage.removeItem("ipam_xhr_replay_v1");
        }
      }
    } catch (_) { /* malformed JSON or storage blocked; non-fatal */ }

    // Per-browser opt-out: users whose password manager already provides a
    // visibility toggle can hide the IPAM eye buttons by setting
    // localStorage['ipam-pw-toggle-hidden'] = '1' from devtools.
    try {
      if (window.localStorage && localStorage.getItem("ipam-pw-toggle-hidden") === "1") {
        document.body.classList.add("pw-toggle-hidden");
      }
    } catch (_) { /* localStorage may be blocked; non-fatal */ }
  });
}());
