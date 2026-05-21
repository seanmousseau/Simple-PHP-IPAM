// Bulk-select bar for addresses table
(function () {
    var bar = document.getElementById("bulk-bar");
    if (!bar) return;
    var countEl = document.getElementById("bulk-bar-count");
    var linkEl = document.getElementById("bulk-bar-link");
    var subnetId = parseInt(bar.getAttribute("data-subnet-id") || "0", 10);

    function updateBar() {
        var checked = document.querySelectorAll(".row-select:checked");
        var n = checked.length;
        bar.classList.toggle("is-visible", n > 0);
        if (countEl) countEl.textContent = n + " selected";
        if (linkEl && subnetId > 0) {
            var ids = [];
            for (var i = 0; i < checked.length; i++) ids.push(checked[i].value);
            linkEl.href = "bulk_update.php?subnet_id=" + encodeURIComponent(subnetId) + "&ids=" + ids.join(",");
        }
    }

    var selectAll = document.getElementById("select-all-addresses");
    if (selectAll) {
        selectAll.addEventListener("change", function () {
            var boxes = document.querySelectorAll(".row-select");
            for (var i = 0; i < boxes.length; i++) boxes[i].checked = selectAll.checked;
            updateBar();
        });
    }

    document.addEventListener("change", function (e) {
        if (e.target && e.target.classList.contains("row-select")) updateBar();
    });
}());

