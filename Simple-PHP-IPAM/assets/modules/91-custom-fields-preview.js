// ─── C15b — custom_fields.php type-select preview (Phase 2a, #939) ───────────
// On custom_fields.php, the "Type" <select> drives both the visibility of
// the per-type options row (only "select" shows it) and which of the
// per-type preview widgets is shown. Page-targeted; runs once at load.
(function(){
  // custom_fields.php — type-select → options-row + preview toggle
  var cfTypeSelect = document.getElementById("cf-type-select");
  if (cfTypeSelect) {
    var cfOptionsRow = document.getElementById("cf-options-row");
    var cfPreviews   = ["text", "number", "date", "boolean", "select"];
    function syncCfType() {
      var t = cfTypeSelect.value;
      cfPreviews.forEach(function(p) {
        var el = document.getElementById("cf-preview-" + p);
        if (el) el.hidden = (p !== t);
      });
      if (cfOptionsRow) cfOptionsRow.hidden = (t !== "select");
    }
    cfTypeSelect.addEventListener("change", syncCfType);
    syncCfType();
  }
}());
