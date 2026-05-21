// ─── C13d — Subnet edit drawer (#567) (Phase 2a, #939) ───────────────────────
// Click `.subnet-edit-btn` → hydrate `#subnet-edit-drawer` from row data
// (vlan/vrf/site/tags/custom-fields/notes) → `IpamDrawer.openNode(...)`.
// Cross-module dependency: IpamDrawer in `20-drawer.js` loads first.
// Inner IIFE survives the outer wrap (closure for editDrawer + site*
// element refs).
(function(){
  document.addEventListener("DOMContentLoaded", function() {
    /* ---- Subnet edit drawer (#567) ---- */
    (function() {
      var editDrawer = document.getElementById("subnet-edit-drawer");
      if (!editDrawer) return;

      var siteWrap = document.getElementById("subnet-edit-site-wrap");
      var siteLocked = document.getElementById("subnet-edit-site-locked");
      var siteSelect = document.getElementById("subnet-edit-site");
      var siteHidden = document.getElementById("subnet-edit-site-hidden");
      var siteBadge = document.getElementById("subnet-edit-site-badge");

      document.addEventListener("click", function(e) {
        var btn = e.target.closest(".subnet-edit-btn");
        if (!btn) return;
        var d = btn.dataset;

        document.getElementById("subnet-edit-id").value = d.sid;
        document.getElementById("subnet-delete-id").value = d.sid;
        document.getElementById("subnet-edit-cidr").value = d.cidr;
        document.getElementById("subnet-edit-description").value = d.description;
        document.getElementById("subnet-edit-notes").value = d.notes;
        var vlanSel = document.getElementById("subnet-edit-vlan");
        if (vlanSel) vlanSel.value = d.vlanFk;
        var vrfSel = document.getElementById("subnet-edit-vrf");
        if (vrfSel) vrfSel.value = d.vrfId;

        if (parseInt(d.depth, 10) > 0 && siteLocked && siteWrap) {
          siteWrap.hidden = true;
          if (siteSelect) siteSelect.disabled = true;
          siteLocked.hidden = false;
          if (siteHidden) { siteHidden.value = d.siteId; siteHidden.disabled = false; }
          if (siteBadge) {
            var opt = siteSelect && siteSelect.querySelector("option[value='" + d.siteId + "']");
            siteBadge.textContent = (opt ? opt.textContent : "(none)") + " \u2191";
            siteBadge.title = "Inherited from parent subnet";
          }
        } else if (siteWrap && siteLocked) {
          siteWrap.hidden = false;
          if (siteSelect) { siteSelect.disabled = false; siteSelect.value = d.siteId; }
          siteLocked.hidden = true;
          if (siteHidden) siteHidden.disabled = true;
        }

        var alertsCb = document.getElementById("subnet-edit-alerts");
        if (alertsCb) alertsCb.checked = (d.alertsEnabled !== "0");

        var dhcpFields = [
          ["dhcp-routers", "dhcpRouters"], ["dhcp-dns-servers", "dhcpDnsServers"],
          ["dhcp-domain-name", "dhcpDomainName"], ["dhcp-lease-default", "dhcpLeaseDefault"],
          ["dhcp-lease-max", "dhcpLeaseMax"], ["dhcp-next-server", "dhcpNextServer"],
          ["dhcp-boot-filename", "dhcpBootFilename"]
        ];
        dhcpFields.forEach(function(pair) {
          var el = document.getElementById("subnet-edit-" + pair[0]);
          if (el) el.value = d[pair[1]] || "";
        });
        var dhcpDetails = document.querySelector(".dhcp-options-group");
        if (dhcpDetails) {
          var anySet = dhcpFields.some(function(pair) { return !!(d[pair[1]]); });
          dhcpDetails.open = anySet;
        }

        var contactPicker = document.getElementById("subnet-edit-contacts");
        if (contactPicker) {
          var existingContacts = [];
          try { existingContacts = JSON.parse(d.contacts || "[]"); } catch(ex) {}
          var rowsDiv = contactPicker.querySelector(".contact-picker-rows");
          if (rowsDiv) rowsDiv.textContent = "";
          contactPicker.setAttribute("data-existing", JSON.stringify(existingContacts));
          contactPicker.dispatchEvent(new CustomEvent("reinit"));
        }

        // #1138: pre-select tags on edit-drawer open from data-tag-ids JSON.
        var tagSelect = document.getElementById("subnet-edit-tag-ids");
        if (tagSelect) {
          var selectedTagIds = [];
          try { selectedTagIds = JSON.parse(d.tagIds || "[]"); } catch (ex) {}
          var selectedSet = {};
          for (var ti = 0; ti < selectedTagIds.length; ti++) selectedSet[String(selectedTagIds[ti])] = true;
          Array.prototype.forEach.call(tagSelect.options, function(opt) {
            opt.selected = !!selectedSet[opt.value];
          });
        }

        // Fill custom field inputs from data-custom-fields JSON
        var cfContainer = document.getElementById("subnet-edit-cf-inputs");
        if (cfContainer) {
          var cfValues = {};
          try { cfValues = JSON.parse(d.customFields || "{}"); } catch(ex) {}
          cfContainer.querySelectorAll("[data-cf-key]").forEach(function(inp) {
            var cfKey = inp.getAttribute("data-cf-key");
            var val = cfValues[cfKey];
            if (inp.type === "checkbox") {
              inp.checked = val === true || val === 1 || val === "1";
            } else {
              inp.value = (val !== null && val !== undefined) ? String(val) : "";
            }
          });
        }

        IpamDrawer.openNode("Edit " + d.cidr, editDrawer);
      });
    }());
  });
}());
