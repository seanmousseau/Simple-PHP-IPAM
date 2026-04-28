<?php /** @var array<string,mixed> $cfg */
$cfg = $cfg ?? [];
$p = e(to_str($cfg['path'] ?? 'data/backups'));
?>
<label>Path <input type="text" name="local_path" value="<?= $p ?>" required></label>
<p class="muted">Must be under <code>Simple-PHP-IPAM/data/</code>. Created automatically if it doesn't exist.</p>
