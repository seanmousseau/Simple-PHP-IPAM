<?php
declare(strict_types=1);
/**
 * Partial: Add Subnet form (used in the global drawer on subnets.php).
 *
 * Props injected via extract():
 *   $vlanList       list<array<string,mixed>>
 *   $vrfList        list<array<string,mixed>>
 *   $siteList       list<array<string,mixed>>
 *   $subnetCfDefs   list<array<string,mixed>>
 *   $contactList    list<array<string,mixed>>
 */
/** @var list<array<string,mixed>> $vlanList */
/** @var list<array<string,mixed>> $vrfList */
/** @var list<array<string,mixed>> $siteList */
/** @var list<array<string,mixed>> $subnetCfDefs */
/** @var list<array<string,mixed>> $contactList */
?>
<form method="post" action="subnets.php">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="create">
  <div class="row">
    <label>CIDR<br><input name="cidr" placeholder="10.0.0.0/24 or 2001:db8::/64" required data-validate="cidr"></label>
    <label>Description<br><input name="description" placeholder="Office LAN"></label>
    <label class="subnet-notes-edit">Notes<br><textarea name="notes" rows="3" placeholder="Long-form operational notes, runbook links, ownership context…"></textarea></label>
    <?php if ($vlanList): ?>
    <label>VLAN<br>
      <select name="vlan_fk">
        <option value="0">(none)</option>
        <?php foreach ($vlanList as $vl): ?>
          <option value="<?= to_int($vl['id']) ?>"><?= to_int($vl['vlan_id']) ?> &mdash; <?= e(to_str($vl['name'])) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php endif; ?>
    <?php if ($vrfList): ?>
    <label>VRF<br>
      <select name="vrf_id">
        <option value="0">(global)</option>
        <?php foreach ($vrfList as $vr): ?>
          <option value="<?= to_int($vr['id']) ?>"><?= e(to_str($vr['name'])) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php endif; ?>
    <label>Site<br>
      <select name="site_id">
        <option value="0">(none)</option>
        <?php foreach ($siteList as $site): ?>
          <option value="<?= to_int($site['id']) ?>"><?= e(to_str($site['name'])) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button type="submit" <?= (current_user()['role'] === 'readonly') ? 'disabled' : '' ?>>Add</button>
  </div>
  <?php if ($subnetCfDefs): ?>
  <?= render_custom_field_inputs($subnetCfDefs, []) ?>
  <?php endif; ?>
  <?php if ($contactList): ?>
  <div class="row mt-8">
    <input type="hidden" name="contact_id_present" value="1">
    <div class="contact-picker" data-contacts='<?= e(json_encode(array_map(fn($c) => ['id' => to_int($c['id']), 'name' => to_str($c['name']), 'email' => to_str($c['email'])], $contactList), JSON_UNESCAPED_SLASHES) ?: '[]') ?>'>
      <label>Contacts</label>
      <div class="contact-picker-rows"></div>
      <button type="button" class="button-secondary btn-sm contact-picker-add">+ Add contact</button>
    </div>
  </div>
  <?php endif; ?>
  <?php // #1138: tag picker on subnet create form (WR-04). ?>
  <?php if (!empty($tagList)): ?>
  <div class="row mt-8">
    <label>Tags <span class="muted font-xs">Cmd/Ctrl-click to toggle</span><br>
      <input type="hidden" name="tag_ids[]" value="">
      <select name="tag_ids[]" multiple size="<?= min(6, max(3, count($tagList))) ?>" class="w-full">
        <?php foreach ($tagList as $t): ?>
          <option value="<?= to_int($t['id']) ?>"><?= e(to_str($t['name'])) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </div>
  <?php endif; ?>
  <?php $autoReserveDefault = (bool)ipam_setting('display.auto_reserve_network_broadcast'); ?>
  <div class="row mt-8">
    <label class="row-inline">
      <input type="checkbox" name="auto_reserve" value="1" <?= $autoReserveDefault ? 'checked' : '' ?>>
      Auto-reserve network, broadcast &amp; gateway IPs
    </label>
    <label>Gateway (optional)<br><input name="gateway" placeholder="e.g. 10.0.0.1"></label>
  </div>
  <?php if (current_user()['role'] === 'readonly'): ?><p class="muted">Read-only account.</p><?php endif; ?>
</form>
