<?php
declare(strict_types=1);
/**
 * Partial: Add Address form (used in the global drawer on addresses.php).
 *
 * Props injected via extract():
 *   $selectedSubnetId  int
 *   $selectedSubnet    array<string,mixed>|false
 *   $nextAvailableIp   string
 *   $prefillIp         string   — pre-validated IP from ?next_ip= query param (empty string if absent)
 *   $addrCfDefs        list<array<string,mixed>>
 *   $deviceList        list<array<string,mixed>>
 */
/** @var int $selectedSubnetId */
/** @var array<string,mixed>|false $selectedSubnet */
/** @var string $nextAvailableIp */
/** @var string $prefillIp */
/** @var list<array<string,mixed>> $addrCfDefs */
/** @var list<array<string,mixed>> $deviceList */
?>
<?php if ($nextAvailableIp): ?>
  <p class="muted">Next available: <b><?= e($nextAvailableIp) ?></b>
    <a class="action-pill" href="#" data-fill-ip="<?= e($nextAvailableIp) ?>">Use</a>
  </p>
<?php endif; ?>
<form method="post" action="addresses.php">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="create">
  <input type="hidden" name="subnet_id" value="<?= (int)$selectedSubnetId ?>">
  <div class="row">
    <label>IP<br><input name="ip" value="<?= e($prefillIp) ?>" placeholder="<?= ($selectedSubnet && to_int($selectedSubnet['ip_version']) === 6) ? '2001:db8::10' : '10.0.0.10' ?>" required data-validate="ip"></label>
    <label>Hostname<br><input name="hostname" maxlength="253"></label>
    <label>Owner<br>
      <span class="contact-typeahead-wrap">
        <input name="owner" maxlength="255" autocomplete="off" data-contact-typeahead>
        <input type="hidden" name="owner_contact_id" value="0">
        <button type="button" class="contact-browse-btn" title="Browse contacts">Browse</button>
      </span>
    </label>
    <label>Group<br><input name="grp" maxlength="100" placeholder="e.g. web-tier" class="mw-160"></label>
    <label>MAC<br><input name="mac" maxlength="64" placeholder="e.g. aa:bb:cc:dd:ee:ff" class="mw-160"></label>
    <label>Expires<br><input name="expires_at" type="date" class="mw-160"></label>
    <label>Status<br>
      <select name="status">
        <option value="used">used</option>
        <option value="reserved">reserved</option>
        <option value="free">free</option>
      </select>
    </label>
  </div>
  <div class="row">
    <label class="flex-1">Note<br><input name="note" maxlength="1000" class="w-full"></label>
  </div>
  <?php if ($deviceList): ?>
  <div class="row">
    <label>Device<br>
      <select name="device_id" class="addr-device-select" data-iface-target="add-iface-select">
        <option value="0">(none)</option>
        <?php foreach ($deviceList as $dv): ?>
          <option value="<?= to_int($dv['id']) ?>"><?= e(to_str($dv['name'])) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Interface<br>
      <select name="interface_id" id="add-iface-select">
        <option value="0">(none)</option>
      </select>
    </label>
  </div>
  <?php endif; ?>
  <?php if ($addrCfDefs): ?>
  <?= render_custom_field_inputs($addrCfDefs, []) ?>
  <?php endif; ?>
  <p>
    <button type="submit"
      <?= ($selectedSubnetId > 0 && current_user()['role'] !== 'readonly') ? '' : 'disabled' ?>>
      Add
    </button>
  </p>
  <?php if ($selectedSubnetId <= 0): ?><p class="muted">Select a subnet first.</p><?php endif; ?>
  <?php if (current_user()['role'] === 'readonly'): ?><p class="muted">Read-only account.</p><?php endif; ?>
</form>
