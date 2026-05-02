<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_role('admin');

require __DIR__ . '/lib/backup_admin_history.php';

/**
 * Bulk-delete handler (#1052, v3.22.0).
 *
 * Operators routinely accumulate hundreds of `backup_runs` rows after long
 * retention windows or after fixing a misconfigured destination that produced
 * a wave of failures. The single-row drawer delete is correct for one row but
 * punishingly slow at scale; this handler accepts a checkbox-driven `ids[]`
 * and runs the per-row delete helper (`ipam_backup_run_delete`) for each so
 * the artifact-then-row order, `is_protected=1` refusal, and audit semantics
 * stay identical to the single-row path.
 *
 * Confirm gate: caller must POST `confirm` equal to the string form of the
 * selected count (e.g. "42") — see issue #1052 acceptance.
 */
$flashSummary = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && to_str($_POST['action'] ?? '') === 'bulk_delete') {
    csrf_require();

    /** @var array<int,mixed> $rawIds */
    $rawIds = is_array($_POST['ids'] ?? null) ? $_POST['ids'] : [];
    $ids = [];
    foreach ($rawIds as $rawId) {
        $n = to_int($rawId);
        if ($n > 0) {
            $ids[$n] = true; // dedupe via key
        }
    }
    /** @var list<int> $ids */
    $ids = array_keys($ids);

    if (count($ids) === 0) {
        header('Location: backup_history.php?bulk=empty');
        exit;
    }

    $confirmRaw = to_str($_POST['confirm'] ?? '');
    if ($confirmRaw !== (string) count($ids)) {
        header('Location: backup_history.php?bulk=confirm_required');
        exit;
    }

    // Look up each row up-front so we can refuse the whole operation if any
    // row is `is_protected=1` (issue #1052 acceptance: "Bulk delete refuses
    // to proceed if any selected row is is_protected = 1 — operator must
    // unprotect first or deselect"). Unknown ids are silently dropped for
    // race robustness — another tab or the retention pruner may have
    // already removed them.
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $lookupStmt = $db->prepare(
        "SELECT id, is_protected FROM backup_runs WHERE id IN ($placeholders)"
    );
    foreach ($ids as $i => $rid) {
        $lookupStmt->bindValue($i + 1, $rid, \PDO::PARAM_INT);
    }
    $lookupStmt->execute();
    /** @var list<array<string,mixed>> $lookupRows */
    $lookupRows = $lookupStmt->fetchAll(\PDO::FETCH_ASSOC);

    $present  = [];
    $protected = [];
    foreach ($lookupRows as $row) {
        $rid = to_int($row['id'] ?? 0);
        $present[$rid] = true;
        if (to_int($row['is_protected'] ?? 0) === 1) {
            $protected[] = $rid;
        }
    }

    if (count($protected) > 0) {
        header('Location: backup_history.php?bulk=protected&n=' . count($protected));
        exit;
    }

    $deleted        = 0;
    /** @var list<array{id:int,error:string}> $failed */
    $failed         = [];
    foreach ($ids as $rid) {
        if (!isset($present[$rid])) {
            continue; // race: row gone since the form was rendered, drop silently.
        }
        try {
            $result = ipam_backup_run_delete($db, $rid);
        } catch (\Throwable $e) {
            error_log('[backup_history bulk_delete] id=' . $rid . ' threw ' . $e->getMessage());
            $failed[] = ['id' => $rid, 'error' => 'exception'];
            continue;
        }
        if (($result['ok'] ?? false) === true) {
            $deleted++;
            audit(
                $db,
                'backup_run.bulk_delete',
                'backup_run',
                $rid,
                'actor=bulk deleted=1'
            );
        } else {
            $errCode = to_str($result['error'] ?? 'unknown');
            // Best-effort: a single broken destination must not block cleanup
            // of every other selected row. The per-row helper already records
            // a structured `*.delete_failed` audit, so we just collect the
            // outcome for the summary banner here.
            error_log(
                '[backup_history bulk_delete] id=' . $rid . ' failed: ' . $errCode
            );
            $failed[] = ['id' => $rid, 'error' => $errCode];
        }
    }

    $location = 'backup_history.php?bulk=ok&n=' . $deleted;
    if (count($failed) > 0) {
        $location .= '&failed=' . count($failed);
    }
    header('Location: ' . $location);
    exit;
}

// GET flash decode for the banner above the table.
$bulkFlash = to_str($_GET['bulk'] ?? '');
if ($bulkFlash !== '') {
    $flashSummary = match ($bulkFlash) {
        'ok'               => [
            'level' => 'success',
            'msg'   => 'Deleted ' . max(0, to_int($_GET['n'] ?? 0)) . ' backup run(s).'
                . (isset($_GET['failed']) ? ' ' . max(0, to_int($_GET['failed'])) . ' failed — see audit log.' : ''),
        ],
        'empty'            => ['level' => 'warning', 'msg' => 'No rows selected.'],
        'confirm_required' => ['level' => 'warning', 'msg' => 'Confirmation count did not match the selection.'],
        'protected'        => [
            'level' => 'warning',
            'msg'   => max(0, to_int($_GET['n'] ?? 0)) . ' selected row(s) are protected. Unprotect or deselect them and try again.',
        ],
        default            => null,
    };
}

$state = ipam_backup_history_load_state($db);

page_header('Backup History');
?>
<main class="container">
  <h1>Backup History</h1>
  <p class="muted">Read-only log of all backup runs. <a href="destinations.php">Manage destinations →</a></p>
  <?php if (is_array($flashSummary)): ?>
    <div class="<?= e($flashSummary['level']) ?>" role="status" aria-live="polite">
      <?= e($flashSummary['msg']) ?>
    </div>
  <?php endif; ?>
  <?php
  ipam_render('backup_admin_history', array_merge($state, [
      'self'       => 'backup_history.php',
      'extraQuery' => '',
  ]));
  ?>

  <!--
    Bulk-select form (#1052). The shared history partial renders the table
    without checkboxes; the JS below walks every `tr.history-row`, prepends a
    row-select cell, and slots a header checkbox into the first <th>. On
    submit we POST a flat ids[] array plus a count-confirm token.
  -->
  <form id="bulk-delete-form" method="post" action="backup_history.php" hidden>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="bulk_delete">
    <input type="hidden" name="confirm" id="bulk-delete-confirm" value="">
    <div id="bulk-delete-ids"></div>
  </form>

  <div id="bulk-bar" class="bulk-bar" role="status" aria-live="polite" hidden>
    <span class="bulk-bar-count" id="bulk-bar-count">0 selected</span>
    <button type="button" class="button-danger" id="bulk-bar-delete">Delete selected</button>
    <a href="#" id="bulk-bar-clear">Clear selection</a>
  </div>
</main>

<script><!-- nosemgrep: ipam-inline-script-csp — #1052: bulk-select wiring is scoped to this page; lifting into assets/app.js tracked separately so v3.22.0 ships the operator feature without touching the global bundle -->
(function () {
    "use strict";
    var table = document.querySelector("section.card .data-table");
    if (!table) return;
    // Only operate on the History entries table — the "Status by destination"
    // summary table is also `.data-table` but its rows have no `.history-row`.
    var rows = table.querySelectorAll("tbody tr.history-row");
    if (rows.length === 0) return;

    // Header: prepend a select-all cell.
    var headerRow = table.querySelector("thead tr");
    if (headerRow) {
        var th = document.createElement("th");
        th.style.width = "1.5rem";
        var hbox = document.createElement("input");
        hbox.type = "checkbox";
        hbox.id = "bulk-select-all";
        hbox.setAttribute("aria-label", "Select all rows on this page");
        th.appendChild(hbox);
        headerRow.insertBefore(th, headerRow.firstChild);
    }

    // Each body row: prepend a row checkbox tied to the run id.
    for (var i = 0; i < rows.length; i++) {
        var row = rows[i];
        var rid = parseInt(row.getAttribute("data-run-id") || "0", 10);
        if (rid <= 0) continue;
        var td = document.createElement("td");
        // Stop the click bubbling up — the row itself is wired to open the
        // detail drawer, and clicking the checkbox should not also navigate.
        td.addEventListener("click", function (e) { e.stopPropagation(); });
        var cb = document.createElement("input");
        cb.type = "checkbox";
        cb.className = "row-select";
        cb.value = String(rid);
        cb.setAttribute("aria-label", "Select run " + rid);
        cb.addEventListener("click", function (e) { e.stopPropagation(); });
        td.appendChild(cb);
        row.insertBefore(td, row.firstChild);
    }

    var bar       = document.getElementById("bulk-bar");
    var countEl   = document.getElementById("bulk-bar-count");
    var deleteBtn = document.getElementById("bulk-bar-delete");
    var clearLink = document.getElementById("bulk-bar-clear");
    var headerCb  = document.getElementById("bulk-select-all");
    var form      = document.getElementById("bulk-delete-form");
    var idHolder  = document.getElementById("bulk-delete-ids");
    var confirmEl = document.getElementById("bulk-delete-confirm");

    function selectedCheckboxes() {
        return table.querySelectorAll("tbody .row-select:checked");
    }

    function updateBar() {
        var checked = selectedCheckboxes();
        var n = checked.length;
        if (n > 0) {
            bar.hidden = false;
            bar.classList.add("is-visible");
        } else {
            bar.classList.remove("is-visible");
            bar.hidden = true;
        }
        if (countEl) countEl.textContent = n + " selected";
        // Sync header indeterminate / checked state.
        if (headerCb) {
            var total = table.querySelectorAll("tbody .row-select").length;
            headerCb.checked       = n > 0 && n === total;
            headerCb.indeterminate = n > 0 && n <  total;
        }
    }

    if (headerCb) {
        headerCb.addEventListener("change", function () {
            var boxes = table.querySelectorAll("tbody .row-select");
            for (var j = 0; j < boxes.length; j++) boxes[j].checked = headerCb.checked;
            updateBar();
        });
    }

    table.addEventListener("change", function (e) {
        if (e.target && e.target.classList && e.target.classList.contains("row-select")) {
            updateBar();
        }
    });

    if (clearLink) {
        clearLink.addEventListener("click", function (e) {
            e.preventDefault();
            var boxes = table.querySelectorAll("tbody .row-select");
            for (var j = 0; j < boxes.length; j++) boxes[j].checked = false;
            updateBar();
        });
    }

    if (deleteBtn && form && idHolder && confirmEl) {
        deleteBtn.addEventListener("click", function () {
            var checked = selectedCheckboxes();
            var n = checked.length;
            if (n === 0) return;
            // Type-to-confirm gate (issue #1052): operator types the count.
            var entered = window.prompt(
                "Delete " + n + " backup run(s)? This removes the remote artifact AND the row.\n\n" +
                "Type the count (" + n + ") to confirm:"
            );
            if (entered === null) return;
            if (entered.trim() !== String(n)) {
                window.alert("Confirmation did not match. Nothing deleted.");
                return;
            }
            // Rebuild the hidden ids[] payload from the live selection.
            idHolder.innerHTML = "";
            for (var j = 0; j < checked.length; j++) {
                var hidden = document.createElement("input");
                hidden.type  = "hidden";
                hidden.name  = "ids[]";
                hidden.value = checked[j].value;
                idHolder.appendChild(hidden);
            }
            confirmEl.value = String(n);
            form.submit();
        });
    }
}());
</script>
<?php page_footer();
